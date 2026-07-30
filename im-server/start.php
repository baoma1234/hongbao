<?php
/**
 * FansHub IM WebSocket 启动入口
 * Linux:   php start.php start [-d]
 * Windows: php start.php start
 */

use Im\Handler\MessageRouter;
use Im\Service\AuthService;
use Im\Service\GroupService;
use Im\Service\MessageService;
use Im\Service\RedPacketService;
use Im\Service\AdminService;
use Im\Support\ConnMap;
use Im\Support\Db;
use Im\Support\NotifyDispatcher;
use Im\Support\PushBus;
use Im\Support\RedisClient;
use Workerman\Timer;
use Workerman\Worker;

require __DIR__ . '/vendor/autoload.php';

$cfg = require __DIR__ . '/config/app.php';

$wsCfg = $cfg['websocket'];
$worker = new Worker($wsCfg['listen']);
$worker->count = max(1, (int)($wsCfg['count'] ?? 1));
$worker->name = $wsCfg['name'] ?? 'FansHubIM';

$worker->onWorkerStart = function (Worker $worker) use ($cfg) {
    Db::init($cfg['db']);
    RedisClient::init($cfg['redis']);
    ConnMap::setWorkerId((int)$worker->id);

    $auth = new AuthService($cfg);
    $messages = new MessageService();
    $groups = new GroupService();
    $redPackets = new RedPacketService($cfg, $messages, $groups);
    $router = new MessageRouter($worker, $auth, $messages, $groups, $redPackets, $cfg);

    PushBus::boot($worker, function (array $envelope) use ($router) {
        $router->deliverEnvelope($envelope);
    });

    $worker->onConnect = function ($connection) use ($router) {
        $connection->lastMessageTime = time();
        $router->onConnect($connection);
    };
    $worker->onMessage = function ($connection, $data) use ($router) {
        $connection->lastMessageTime = time();
        $router->onMessage($connection, $data);
    };
    $worker->onClose = function ($connection) use ($router) {
        $router->onClose($connection);
    };

    // 消费跨进程推送队列（高频）
    $drainEvery = (float)($cfg['push']['drain_interval'] ?? 0.05);
    $drainBatch = (int)($cfg['push']['drain_batch'] ?? 100);
    Timer::add(max(0.02, $drainEvery), function () use ($drainBatch) {
        PushBus::drainOwnQueue($drainBatch);
    });

    // Worker 心跳 + 清理死进程
    Timer::add(5, function () {
        PushBus::heartbeat();
    });

    // 仅 worker0 消费后台代聊队列，再经 PushBus 扇出（避免多进程抢消息且漏推）
    if ((int)$worker->id === 0) {
        Timer::add(0.3, function () use ($groups) {
            try {
                $r = RedisClient::conn();
                $key = RedisClient::key('notify_queue');
                for ($i = 0; $i < 20; $i++) {
                    $raw = $r->lPop($key);
                    if (!$raw) {
                        break;
                    }
                    $evt = json_decode($raw, true);
                    if (!is_array($evt) || empty($evt['type'])) {
                        continue;
                    }
                    $msg = $evt['message'] ?? null;
                    if (!is_array($msg) && !empty($evt['data']['message']) && is_array($evt['data']['message'])) {
                        $msg = $evt['data']['message'];
                    }
                    if (!is_array($msg)) {
                        continue;
                    }
                    $type = (string)$evt['type'];
                    NotifyDispatcher::dispatch(
                        $type,
                        $msg,
                        !empty($evt['admin_only']),
                        $groups
                    );
                }
            } catch (\Throwable $e) {
            }
        });
    }

    // 过期红包退回（5s）+ 抢完未结算兜底（2s）+ 波场最新哈希轮询（仅 worker0）
    if ((int)$worker->id === 0) {
        // 全局唯一：每 3 秒拉一次最新真实区块哈希写入 Redis，业务只读缓存拆包
        try {
            \Im\Support\TronHashCache::refresh(4);
        } catch (\Throwable $e) {
            error_log('[TRON_HASH] boot refresh fail ' . $e->getMessage());
        }
        $hashPoll = \Im\Support\TronHashCache::pollIntervalSec();
        Timer::add((float)$hashPoll, function () {
            try {
                \Im\Support\TronHashCache::refresh(4);
            } catch (\Throwable $e) {
                error_log('[TRON_HASH] poll fail ' . $e->getMessage());
            }
        });
        Timer::add(5, function () use ($redPackets) {
            try {
                $redPackets->refundExpired(50);
            } catch (\Throwable $e) {
                error_log('[RP_EXPIRE] timer err ' . $e->getMessage());
            }
            try {
                \Im\Support\TronFair::pollPendingReveals(20);
            } catch (\Throwable $e) {
                error_log('[TRON_POLL] timer err ' . $e->getMessage());
            }
        });
        Timer::add(2, function () use ($redPackets) {
            try {
                $redPackets->retryPendingSettlements(30);
            } catch (\Throwable $e) {
                error_log('[RP_SETTLE_RETRY] timer err ' . $e->getMessage());
            }
        });
    }

    $heartbeat = (int)($cfg['websocket']['heartbeat'] ?? 50);
    if ($heartbeat > 0) {
        Timer::add($heartbeat, function () use ($worker) {
            $now = time();
            foreach ($worker->connections as $connection) {
                if (empty($connection->lastMessageTime)) {
                    $connection->lastMessageTime = $now;
                    continue;
                }
                if ($now - $connection->lastMessageTime > 120) {
                    $connection->close();
                    continue;
                }
                $uid = ConnMap::userIdOf((string)$connection->id);
                if ($uid > 0) {
                    ConnMap::touchUser($uid);
                }
            }
        });
    }
};

Worker::runAll();
