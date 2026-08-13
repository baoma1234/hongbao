<?php
/**
 * FansHub IM 后台定时进程（独立于 WS Worker0）
 * - Tron 哈希轮询 / pending reveal
 * - 过期红包退款
 * - 结算重试
 * - 自动发/抢红包机器人
 *
 * 不接 WebSocket，避免阻塞聊天推送。推送走 PushBus::toUsersExternal。
 *
 * Linux:   php start_cron.php start [-d]
 * Windows: php start_cron.php start
 */

use Im\Service\GroupService;
use Im\Service\MessageService;
use Im\Service\NiuniuService;
use Im\Service\NiuniuAutoBotService;
use Im\Service\RedPacketService;
use Im\Service\RpAutoBotService;
use Im\Support\Db;
use Im\Support\HealthProbe;
use Im\Support\RedisClient;
use Im\Support\TronFair;
use Im\Support\TronHashCache;
use Workerman\Timer;
use Workerman\Worker;

require __DIR__ . '/vendor/autoload.php';

$cfg = require __DIR__ . '/config/app.php';
$cronCfg = is_array($cfg['cron'] ?? null) ? $cfg['cron'] : [];

$worker = new Worker();
$worker->count = 1;
$worker->name = (string)($cronCfg['name'] ?? 'FansHubIM-Cron');

$worker->onWorkerStart = function () use ($cfg, $cronCfg) {
    Db::init($cfg['db']);
    RedisClient::init($cfg['redis']);

    Timer::add(60, function () {
        Db::keepalive();
    });

    // 健康探针：/health/deep 与 scripts/im_health_probe.php 依赖此心跳
    HealthProbe::touchCronAlive();
    Timer::add(10, function () {
        HealthProbe::touchCronAlive();
    });

    $messages = new MessageService();
    $groups = new GroupService();
    $redPackets = new RedPacketService($cfg, $messages, $groups);
    $rpAuto = new RpAutoBotService($redPackets, $groups);
    $niuniu = new NiuniuService($cfg, $messages, $groups);
    $nnAuto = new NiuniuAutoBotService($niuniu, $groups);

    $tronBusy = false;
    $refundBusy = false;
    $settleBusy = false;
    $autoBusy = false;
    $niuniuBusy = false;
    $nnAutoBusy = false;

    // 启动先拉一次哈希
    try {
        TronHashCache::refresh(4);
    } catch (\Throwable $e) {
        error_log('[CRON][TRON_HASH] boot refresh fail ' . $e->getMessage());
    }

    $hashPoll = (float)($cronCfg['tron_poll_interval'] ?? TronHashCache::pollIntervalSec());
    $hashPoll = max(1.0, min(30.0, $hashPoll));
    Timer::add($hashPoll, function () use (&$tronBusy) {
        if ($tronBusy) {
            return;
        }
        $tronBusy = true;
        try {
            TronHashCache::refresh(4);
        } catch (\Throwable $e) {
            error_log('[CRON][TRON_HASH] poll fail ' . $e->getMessage());
        } finally {
            $tronBusy = false;
        }
    });

    $refundEvery = max(5, (int)($cronCfg['refund_interval'] ?? 5));
    $refundLimit = max(1, (int)($cronCfg['refund_limit'] ?? 50));
    $tronRevealLimit = max(1, (int)($cronCfg['tron_reveal_limit'] ?? 20));
    Timer::add($refundEvery, function () use ($redPackets, $refundLimit, $tronRevealLimit, &$refundBusy) {
        if ($refundBusy) {
            return;
        }
        $refundBusy = true;
        try {
            try {
                $redPackets->refundExpired($refundLimit);
            } catch (\Throwable $e) {
                error_log('[CRON][RP_EXPIRE] ' . $e->getMessage());
            }
            try {
                TronFair::pollPendingReveals($tronRevealLimit);
            } catch (\Throwable $e) {
                error_log('[CRON][TRON_POLL] ' . $e->getMessage());
            }
        } finally {
            $refundBusy = false;
        }
    });

    $settleEvery = max(2, (int)($cronCfg['settle_interval'] ?? 2));
    $settleLimit = max(1, (int)($cronCfg['settle_limit'] ?? 30));
    Timer::add($settleEvery, function () use ($redPackets, $settleLimit, &$settleBusy) {
        if ($settleBusy) {
            return;
        }
        $settleBusy = true;
        try {
            $redPackets->retryPendingSettlements($settleLimit);
            $redPackets->retryPendingRelayRounds($settleLimit);
            $redPackets->collectExpireClawbackDebts($settleLimit);
        } catch (\Throwable $e) {
            error_log('[CRON][RP_SETTLE_RETRY] ' . $e->getMessage());
        } finally {
            $settleBusy = false;
        }
    });

    $autoEvery = max(2, (int)($cronCfg['auto_interval'] ?? 2));
    Timer::add($autoEvery, function () use ($rpAuto, &$autoBusy) {
        if ($autoBusy) {
            return;
        }
        $autoBusy = true;
        try {
            $rpAuto->tick();
        } catch (\Throwable $e) {
            error_log('[CRON][RP_AUTO] ' . $e->getMessage());
        } finally {
            $autoBusy = false;
        }
    });

    Timer::add(2, function () use ($niuniu, &$niuniuBusy) {
        if ($niuniuBusy) {
            return;
        }
        $niuniuBusy = true;
        try {
            $niuniu->tick(30);
        } catch (\Throwable $e) {
            error_log('[CRON][NIUNIU] ' . $e->getMessage());
        } finally {
            $niuniuBusy = false;
        }
    });

    Timer::add(2, function () use ($nnAuto, &$nnAutoBusy) {
        if ($nnAutoBusy) {
            return;
        }
        $nnAutoBusy = true;
        try {
            $nnAuto->tick();
        } catch (\Throwable $e) {
            error_log('[CRON][NN_AUTO] ' . $e->getMessage());
        } finally {
            $nnAutoBusy = false;
        }
    });

    error_log(sprintf(
        '[CRON] started tron=%.1fs refund=%ds settle=%ds auto=%ds niuniu=2s nn_auto=2s',
        $hashPoll,
        $refundEvery,
        $settleEvery,
        $autoEvery
    ));
};

Worker::runAll();
