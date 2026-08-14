<?php

namespace Im\Support;

/**
 * uid <-> connection_id 映射（进程内）+ Redis 在线集合（跨进程感知）
 *
 * Redis 中 conn 成员格式：{workerId}:{connectionId}，避免多进程 connectionId 冲突。
 * 另维护本进程 gid→online uids 倒排，供群频道本地扇出，避免每条消息对全量在线做 SISMEMBER。
 */
class ConnMap
{
    /** @var array<int,array<string,true>> uid => [connId => true] */
    protected static $uidConns = [];
    /** @var array<string,int> connId => uid */
    protected static $connUid = [];
    /** @var int */
    protected static $workerId = 0;
    /** @var array<int,array<int,true>> gid => [uid => true] 本进程在线群成员 */
    protected static $gidLocalUids = [];
    /** @var array<int,array<int,true>> uid => [gid => true]；键存在表示该 uid 已建索引 */
    protected static $uidLocalGids = [];

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
            CatchLog::quiet($e, 'Support.ConnMap');
        }
        // 鉴权后重建本进程群倒排（auth 低频，可接受一次轻量查库）
        self::rebuildUserGroupIndex($userId);
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
            self::clearUserGroupIndex($uid);
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
            CatchLog::quiet($e, 'Support.ConnMap');
        }
    }

    /** 刷新在线 TTL（心跳时可调用） */
    public static function touchUser($userId)
    {
        self::touchUsers([(int)$userId]);
    }

    /**
     * 批量刷新在线 TTL（心跳一轮一次 pipeline，避免万人×EXPIRE 打爆 Redis）
     *
     * @param int[] $userIds
     */
    public static function touchUsers(array $userIds)
    {
        $ids = [];
        foreach ($userIds as $userId) {
            $userId = (int)$userId;
            if ($userId > 0 && !empty(self::$uidConns[$userId])) {
                $ids[$userId] = $userId;
            }
        }
        if (!$ids) {
            return;
        }
        try {
            $r = RedisClient::conn();
            $onlineKey = RedisClient::key('online');
            $r->multi(\Redis::PIPELINE);
            foreach ($ids as $userId) {
                $connKey = RedisClient::key('uid:' . $userId . ':conns');
                $r->expire($connKey, 180);
                $r->sAdd($onlineKey, (string)$userId);
            }
            $r->exec();
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Support.ConnMap');
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
     * 本进程在线用户中属于某群的成员（用于群频道本地扇出）
     *
     * @return int[]
     */
    public static function filterLocalGroupMembers($groupId)
    {
        $groupId = (int)$groupId;
        if ($groupId <= 0) {
            return [];
        }

        $out = [];
        if (!empty(self::$gidLocalUids[$groupId])) {
            foreach (self::$gidLocalUids[$groupId] as $uid => $_) {
                if (!empty(self::$uidConns[$uid])) {
                    $out[(int)$uid] = (int)$uid;
                } else {
                    // 连接已断但倒排残留：顺手清掉
                    self::remLocalGroupMember($groupId, (int)$uid);
                }
            }
        }

        // 自愈：本进程有在线用户但尚未建群索引（竞态）→ 按库重建一次
        $needRebuild = [];
        foreach (self::$uidConns as $uid => $_) {
            $uid = (int)$uid;
            if (!array_key_exists($uid, self::$uidLocalGids)) {
                $needRebuild[] = $uid;
            }
        }
        if ($needRebuild) {
            foreach ($needRebuild as $uid) {
                self::rebuildUserGroupIndex($uid);
            }
            if (!empty(self::$gidLocalUids[$groupId])) {
                foreach (self::$gidLocalUids[$groupId] as $uid => $_) {
                    if (!empty(self::$uidConns[$uid])) {
                        $out[(int)$uid] = (int)$uid;
                    }
                }
            }
        }

        return array_values($out);
    }

    /**
     * 鉴权后：按库重建该用户本进程群倒排
     */
    public static function rebuildUserGroupIndex($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0 || empty(self::$uidConns[$userId])) {
            return;
        }
        self::clearUserGroupIndex($userId);
        self::$uidLocalGids[$userId] = [];
        try {
            $rows = Db::fetchAll(
                'SELECT group_id FROM ' . Db::table('chat_group_members')
                . ' WHERE user_id=? AND status=1',
                [$userId]
            );
            foreach ($rows ?: [] as $row) {
                $gid = (int)($row['group_id'] ?? 0);
                if ($gid > 0) {
                    self::addLocalGroupMember($gid, $userId);
                }
            }
        } catch (\Throwable $e) {
            // 失败时不占空索引，让 filter 走 SISMEMBER 自愈
            unset(self::$uidLocalGids[$userId]);
        }
    }

    public static function addLocalGroupMember($groupId, $userId)
    {
        $groupId = (int)$groupId;
        $userId = (int)$userId;
        if ($groupId <= 0 || $userId <= 0 || empty(self::$uidConns[$userId])) {
            return;
        }
        $wasEmpty = empty(self::$gidLocalUids[$groupId]);
        if (!isset(self::$gidLocalUids[$groupId])) {
            self::$gidLocalUids[$groupId] = [];
        }
        self::$gidLocalUids[$groupId][$userId] = true;
        if (!isset(self::$uidLocalGids[$userId])) {
            self::$uidLocalGids[$userId] = [];
        }
        self::$uidLocalGids[$userId][$groupId] = true;
        // 本 Worker 首次持有该群在线成员时登记，供 PushBus 只扇出到有成员的 Worker
        if ($wasEmpty) {
            self::registerGroupWorker($groupId);
        }
    }

    public static function remLocalGroupMember($groupId, $userId)
    {
        $groupId = (int)$groupId;
        $userId = (int)$userId;
        if ($groupId <= 0 || $userId <= 0) {
            return;
        }
        unset(self::$gidLocalUids[$groupId][$userId]);
        if (empty(self::$gidLocalUids[$groupId])) {
            unset(self::$gidLocalUids[$groupId]);
            self::unregisterGroupWorker($groupId);
        }
        unset(self::$uidLocalGids[$userId][$groupId]);
    }

    protected static function clearUserGroupIndex($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return;
        }
        $gids = self::$uidLocalGids[$userId] ?? [];
        foreach ($gids as $gid => $_) {
            unset(self::$gidLocalUids[$gid][$userId]);
            if (empty(self::$gidLocalUids[$gid])) {
                unset(self::$gidLocalUids[$gid]);
                self::unregisterGroupWorker((int)$gid);
            }
        }
        unset(self::$uidLocalGids[$userId]);
    }

    protected static function registerGroupWorker($groupId)
    {
        $groupId = (int)$groupId;
        if ($groupId <= 0) {
            return;
        }
        try {
            $r = RedisClient::conn();
            $key = RedisClient::key('g:' . $groupId . ':workers');
            $r->sAdd($key, (string)self::$workerId);
            $r->expire($key, 600);
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Support.ConnMap');
        }
    }

    protected static function unregisterGroupWorker($groupId)
    {
        $groupId = (int)$groupId;
        if ($groupId <= 0) {
            return;
        }
        try {
            $r = RedisClient::conn();
            $key = RedisClient::key('g:' . $groupId . ':workers');
            $r->sRem($key, (string)self::$workerId);
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Support.ConnMap');
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
