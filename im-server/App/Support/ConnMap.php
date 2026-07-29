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

    /**
     * 过滤出当前在线用户（跨进程 Redis online 集合；失败时回退本进程）
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
            $online = $r->sMembers(RedisClient::key('online'));
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
