<?php

namespace Im\Support;

/**
 * uid <-> connection_id 映射（进程内）+ Redis 在线集合（跨进程感知）
 *
 * Redis 中 conn 成员格式：{workerId}:{connectionId}，避免多进程 connectionId 冲突。
 */
class ConnMap
{
    /** @var array<int,array<string,true>> uid => [connId => true] */
    protected static $uidConns = [];
    /** @var array<string,int> connId => uid */
    protected static $connUid = [];
    /** @var int */
    protected static $workerId = 0;

    public static function setWorkerId($workerId)
    {
        self::$workerId = (int)$workerId;
    }

    public static function bind($connectionId, $userId)
    {
        $connectionId = (string)$connectionId;
        $userId = (int)$userId;
        self::unbindConn($connectionId);
        self::$connUid[$connectionId] = $userId;
        if (!isset(self::$uidConns[$userId])) {
            self::$uidConns[$userId] = [];
        }
        self::$uidConns[$userId][$connectionId] = true;
        try {
            $r = RedisClient::conn();
            $member = self::redisMember($connectionId);
            $connKey = RedisClient::key('uid:' . $userId . ':conns');
            $r->sAdd($connKey, $member);
            $r->expire($connKey, 180);
            $r->setex(RedisClient::key('conn:' . $member), 180, (string)$userId);
            $r->sAdd(RedisClient::key('online'), (string)$userId);
        } catch (\Throwable $e) {
        }
    }

    public static function unbindConn($connectionId)
    {
        $connectionId = (string)$connectionId;
        if (!isset(self::$connUid[$connectionId])) {
            return;
        }
        $uid = self::$connUid[$connectionId];
        unset(self::$connUid[$connectionId]);
        unset(self::$uidConns[$uid][$connectionId]);
        $emptyLocal = empty(self::$uidConns[$uid]);
        if ($emptyLocal) {
            unset(self::$uidConns[$uid]);
        }
        try {
            $r = RedisClient::conn();
            $member = self::redisMember($connectionId);
            $connKey = RedisClient::key('uid:' . $uid . ':conns');
            $r->sRem($connKey, $member);
            $r->del(RedisClient::key('conn:' . $member));
            $left = (int)$r->sCard($connKey);
            if ($left <= 0) {
                $r->del($connKey);
                $r->sRem(RedisClient::key('online'), (string)$uid);
            }
        } catch (\Throwable $e) {
        }
    }

    /** 刷新在线 TTL（心跳时可调用） */
    public static function touchUser($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0 || empty(self::$uidConns[$userId])) {
            return;
        }
        try {
            $r = RedisClient::conn();
            $connKey = RedisClient::key('uid:' . $userId . ':conns');
            $r->expire($connKey, 180);
            $r->sAdd(RedisClient::key('online'), (string)$userId);
        } catch (\Throwable $e) {
        }
    }

    public static function userIdOf($connectionId)
    {
        return self::$connUid[(string)$connectionId] ?? 0;
    }

    /** @return string[] 本进程 connection id */
    public static function connIdsOfUser($userId)
    {
        $userId = (int)$userId;
        return array_keys(self::$uidConns[$userId] ?? []);
    }

    public static function isLocalOnline($userId)
    {
        return !empty(self::$uidConns[(int)$userId]);
    }

    /** @return int[] 本进程已认证用户 id */
    public static function localUserIds()
    {
        return array_map('intval', array_keys(self::$uidConns));
    }

    /**
     * 本进程在线用户中属于某群的成员（用于群频道本地扇出，避免跨进程传万级 uid）
     *
     * @return int[]
     */
    public static function filterLocalGroupMembers($groupId)
    {
        $groupId = (int)$groupId;
        $uids = self::localUserIds();
        if ($groupId <= 0 || !$uids) {
            return [];
        }
        try {
            $r = RedisClient::conn();
            $setKey = RedisClient::key('g:' . $groupId . ':mset');
            if (!(int)$r->exists($setKey) || (int)$r->sCard($setKey) <= 0) {
                return [];
            }
            $out = [];
            $chunkSize = 200;
            for ($i = 0; $i < count($uids); $i += $chunkSize) {
                $chunk = array_slice($uids, $i, $chunkSize);
                $r->multi(\Redis::PIPELINE);
                foreach ($chunk as $uid) {
                    $r->sIsMember($setKey, (string)$uid);
                }
                $flags = $r->exec();
                if (!is_array($flags)) {
                    continue;
                }
                foreach ($chunk as $j => $uid) {
                    if (!empty($flags[$j])) {
                        $out[] = (int)$uid;
                    }
                }
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 过滤出当前在线用户（跨进程 Redis online 集合；失败时回退本进程）
     * 大批量会员时用 pipeline SISMEMBER，避免每次 SMEMBERS 全站在线集
     *
     * @param int[] $userIds
     * @return int[]
     */
    public static function filterOnlineUserIds(array $userIds)
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$userIds) {
            return [];
        }
        try {
            $r = RedisClient::conn();
            $onlineKey = RedisClient::key('online');
            // 少量目标：读全站 online 再过滤；大量目标：逐个 SISMEMBER（1 次 pipeline RTT）
            if (count($userIds) <= 80) {
                $online = $r->sMembers($onlineKey);
                if (!is_array($online) || !$online) {
                    return array_values(array_filter($userIds, function ($uid) {
                        return self::isLocalOnline($uid);
                    }));
                }
                $map = [];
                foreach ($online as $v) {
                    $map[(int)$v] = true;
                }
                $out = [];
                foreach ($userIds as $uid) {
                    if (isset($map[$uid]) || self::isLocalOnline($uid)) {
                        $out[] = $uid;
                    }
                }
                return $out;
            }
            $out = [];
            $chunkSize = 200;
            for ($i = 0; $i < count($userIds); $i += $chunkSize) {
                $chunk = array_slice($userIds, $i, $chunkSize);
                $r->multi(\Redis::PIPELINE);
                foreach ($chunk as $uid) {
                    $r->sIsMember($onlineKey, (string)$uid);
                }
                $flags = $r->exec();
                if (!is_array($flags)) {
                    foreach ($chunk as $uid) {
                        if (self::isLocalOnline($uid)) {
                            $out[] = $uid;
                        }
                    }
                    continue;
                }
                foreach ($chunk as $j => $uid) {
                    if (!empty($flags[$j]) || self::isLocalOnline($uid)) {
                        $out[] = $uid;
                    }
                }
            }
            return $out;
        } catch (\Throwable $e) {
            return array_values(array_filter($userIds, function ($uid) {
                return self::isLocalOnline($uid);
            }));
        }
    }

    protected static function redisMember($connectionId)
    {
        return self::$workerId . ':' . $connectionId;
    }
}
