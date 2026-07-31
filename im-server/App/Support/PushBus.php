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

    public static function publish(array $envelope)
    {
        // 本进程立即投递（低延迟）
        self::deliverLocal($envelope);

        $others = self::otherAliveWorkers();
        if (!$others) {
            return;
        }
        try {
            $r = RedisClient::conn();
            $json = json_encode($envelope, JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                return;
            }
            foreach ($others as $wid) {
                $key = RedisClient::key('w:' . $wid . ':push');
                $r->lPush($key, $json);
                $r->lTrim($key, 0, 19999);
            }
            // 轻量唤醒：让其他 Worker 尽快 drain（订阅端可选；无订阅也不影响）
            try {
                $r->publish(RedisClient::key('push_wake'), (string)self::$workerId);
            } catch (\Throwable $e) {
            }
        } catch (\Throwable $e) {
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
