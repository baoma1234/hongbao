<?php

namespace Im\Support;

use Workerman\Worker;

/**
 * 跨 Worker 推送总线（Redis 队列扇出）
 *
 * 本进程先本地投递，再 LPUSH 到其他存活 Worker 的专属队列，避免多进程漏推。
 * Windows 单进程时仅走本地投递。
 */
class PushBus
{
    /** @var Worker|null */
    protected static $worker;
    /** @var int */
    protected static $workerId = 0;
    /** @var callable|null function(array $envelope): void */
    protected static $localDeliver;

    public static function boot(Worker $worker, callable $localDeliver)
    {
        self::$worker = $worker;
        self::$workerId = (int)$worker->id;
        self::$localDeliver = $localDeliver;
        self::register();
        self::drainOwnQueue(200);
    }

    public static function workerId()
    {
        return self::$workerId;
    }

    public static function register()
    {
        try {
            $r = RedisClient::conn();
            $wid = (string)self::$workerId;
            $r->sAdd(RedisClient::key('workers'), $wid);
            $r->setex(RedisClient::key('worker:' . $wid . ':alive'), 20, '1');
            // 启动时清空可能残留的旧消息，避免重启后洪峰
            $r->del(RedisClient::key('w:' . $wid . ':push'));
        } catch (\Throwable $e) {
        }
    }

    public static function heartbeat()
    {
        try {
            $r = RedisClient::conn();
            $wid = (string)self::$workerId;
            $r->sAdd(RedisClient::key('workers'), $wid);
            $r->setex(RedisClient::key('worker:' . $wid . ':alive'), 20, '1');
            self::pruneDeadWorkers($r);
        } catch (\Throwable $e) {
        }
    }

    /**
     * 推给指定用户（可排除某个本机 connection id）
     *
     * @param int|int[] $userIds
     */
    public static function toUsers($userIds, $type, array $data, $exceptConnId = '')
    {
        if (!is_array($userIds)) {
            $userIds = [$userIds];
        }
        $ids = [];
        foreach ($userIds as $uid) {
            $uid = (int)$uid;
            if ($uid > 0) {
                $ids[$uid] = $uid;
            }
        }
        if (!$ids) {
            return;
        }
        // HTTP 桥等未 boot 的进程：无本地投递，走跨进程队列
        if (!self::$localDeliver) {
            self::toUsersExternal(array_values($ids), $type, $data);
            return;
        }
        $envelope = [
            'v'      => 1,
            'type'   => (string)$type,
            'data'   => $data,
            'uids'   => array_values($ids),
            'except' => (string)$exceptConnId,
            'from'   => self::$workerId,
            'ts'     => time(),
        ];
        self::publish($envelope);
    }

    /**
     * HTTP / 外部进程推送：无本地连接，直接写入各存活 Worker 的 push 队列
     *
     * @param int|int[] $userIds
     */
    public static function toUsersExternal($userIds, $type, array $data)
    {
        if (!is_array($userIds)) {
            $userIds = [$userIds];
        }
        $ids = [];
        foreach ($userIds as $uid) {
            $uid = (int)$uid;
            if ($uid > 0) {
                $ids[$uid] = $uid;
            }
        }
        if (!$ids) {
            return;
        }
        $envelope = [
            'v'      => 1,
            'type'   => (string)$type,
            'data'   => $data,
            'uids'   => array_values($ids),
            'except' => '',
            'from'   => -1,
            'ts'     => time(),
        ];
        $json = json_encode($envelope, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }
        try {
            $r = RedisClient::conn();
            $workers = $r->sMembers(RedisClient::key('workers'));
            if (!is_array($workers) || !$workers) {
                $workers = ['0'];
            }
            foreach ($workers as $wid) {
                $wid = (string)$wid;
                if ($wid === '') {
                    continue;
                }
                if (!$r->exists(RedisClient::key('worker:' . $wid . ':alive'))) {
                    // 仍写入 worker0，避免心跳短暂空窗丢推
                    if ($wid !== '0') {
                        continue;
                    }
                }
                $key = RedisClient::key('w:' . $wid . ':push');
                $r->lPush($key, $json);
                $r->lTrim($key, 0, 19999);
            }
            try {
                $r->publish(RedisClient::key('push_wake'), 'external');
            } catch (\Throwable $ePub) {
            }
        } catch (\Throwable $e) {
        }
    }

    public static function publish(array $envelope)
    {
        // 本进程立即投递（只命中本机连接）
        self::deliverLocal($envelope);

        $uids = $envelope['uids'] ?? [];
        if (!is_array($uids) || !$uids) {
            return;
        }
        // 按「用户当前所在 Worker」定向投递，避免 count=16 时每条消息复制 15 份全量 uid 列表
        $byWorker = self::routeUidsToRemoteWorkers($uids);
        if (!$byWorker) {
            return;
        }
        try {
            $r = RedisClient::conn();
            foreach ($byWorker as $wid => $subset) {
                if (!$subset) {
                    continue;
                }
                $env = $envelope;
                $env['uids'] = array_values($subset);
                $json = json_encode($env, JSON_UNESCAPED_UNICODE);
                if ($json === false) {
                    continue;
                }
                $key = RedisClient::key('w:' . $wid . ':push');
                $r->lPush($key, $json);
                $r->lTrim($key, 0, 19999);
            }
            try {
                $r->publish(RedisClient::key('push_wake'), (string)self::$workerId);
            } catch (\Throwable $e) {
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * 根据 uid→conn 映射，把用户分到远程 Worker（排除本进程）
     *
     * @param int[] $uids
     * @return array<string, array<int,int>> wid => [uid=>uid]
     */
    protected static function routeUidsToRemoteWorkers(array $uids)
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
        if (!$uids) {
            return [];
        }
        $me = (string)self::$workerId;
        $map = [];
        try {
            $r = RedisClient::conn();
            $chunkSize = 150;
            for ($i = 0; $i < count($uids); $i += $chunkSize) {
                $chunk = array_slice($uids, $i, $chunkSize);
                $r->multi(\Redis::PIPELINE);
                foreach ($chunk as $uid) {
                    $r->sMembers(RedisClient::key('uid:' . $uid . ':conns'));
                }
                $results = $r->exec();
                if (!is_array($results)) {
                    continue;
                }
                foreach ($chunk as $j => $uid) {
                    $members = $results[$j] ?? [];
                    if (!is_array($members) || !$members) {
                        continue;
                    }
                    foreach ($members as $member) {
                        $member = (string)$member;
                        $pos = strpos($member, ':');
                        if ($pos === false) {
                            continue;
                        }
                        $wid = substr($member, 0, $pos);
                        if ($wid === '' || $wid === $me) {
                            continue;
                        }
                        // 只投给仍标记存活的 Worker
                        if (!isset($map[$wid]) && !self::isWorkerAlive($wid)) {
                            continue;
                        }
                        $map[$wid][$uid] = $uid;
                    }
                }
            }
        } catch (\Throwable $e) {
            // 路由失败时回退：广播给其他存活 Worker（旧行为，保证不漏推）
            return self::broadcastFallback($uids);
        }
        return $map;
    }

    /** @return array<string, array<int,int>> */
    protected static function broadcastFallback(array $uids)
    {
        $me = (string)self::$workerId;
        $subset = [];
        foreach ($uids as $uid) {
            $uid = (int)$uid;
            if ($uid > 0) {
                $subset[$uid] = $uid;
            }
        }
        $out = [];
        foreach (self::otherAliveWorkers() as $wid) {
            $out[(string)$wid] = $subset;
        }
        return $out;
    }

    /** @var array<string,int> wid => expireAt */
    protected static $aliveCache = [];

    protected static function isWorkerAlive($wid)
    {
        $wid = (string)$wid;
        $now = time();
        if (isset(self::$aliveCache[$wid]) && self::$aliveCache[$wid] >= $now) {
            return true;
        }
        try {
            $ok = (bool)RedisClient::conn()->exists(RedisClient::key('worker:' . $wid . ':alive'));
            if ($ok) {
                self::$aliveCache[$wid] = $now + 2;
            }
            return $ok;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** 消费本 Worker 队列 */
    public static function drainOwnQueue($limit = 80)
    {
        $limit = max(1, (int)$limit);
        try {
            $r = RedisClient::conn();
            $key = RedisClient::key('w:' . self::$workerId . ':push');
            for ($i = 0; $i < $limit; $i++) {
                $raw = $r->rPop($key);
                if ($raw === false || $raw === null || $raw === '') {
                    break;
                }
                $envelope = json_decode($raw, true);
                if (!is_array($envelope)) {
                    continue;
                }
                self::deliverLocal($envelope);
            }
        } catch (\Throwable $e) {
        }
    }

    public static function deliverLocal(array $envelope)
    {
        if (self::$localDeliver) {
            call_user_func(self::$localDeliver, $envelope);
        }
    }

    /** @return string[] */
    protected static function otherAliveWorkers()
    {
        $all = self::aliveWorkers();
        $me = (string)self::$workerId;
        $out = [];
        foreach ($all as $wid) {
            if ((string)$wid !== $me) {
                $out[] = (string)$wid;
            }
        }
        return $out;
    }

    /** @return string[] */
    protected static function aliveWorkers()
    {
        try {
            $r = RedisClient::conn();
            $members = $r->sMembers(RedisClient::key('workers'));
            if (!is_array($members) || !$members) {
                return [(string)self::$workerId];
            }
            $alive = [];
            foreach ($members as $wid) {
                $wid = (string)$wid;
                if ($r->exists(RedisClient::key('worker:' . $wid . ':alive'))) {
                    $alive[] = $wid;
                }
            }
            return $alive ?: [(string)self::$workerId];
        } catch (\Throwable $e) {
            return [(string)self::$workerId];
        }
    }

    protected static function pruneDeadWorkers($r)
    {
        $members = $r->sMembers(RedisClient::key('workers'));
        if (!is_array($members)) {
            return;
        }
        foreach ($members as $wid) {
            $wid = (string)$wid;
            if ($wid === (string)self::$workerId) {
                continue;
            }
            if (!$r->exists(RedisClient::key('worker:' . $wid . ':alive'))) {
                $r->sRem(RedisClient::key('workers'), $wid);
                $r->del(RedisClient::key('w:' . $wid . ':push'));
            }
        }
    }
}
