<?php

namespace Im\Service;

use Im\Support\Db;
use Im\Support\RedisClient;

/**
 * 官方社群展示人数 / 在线（与 PHP FansHubOfficialStats 共用 Redis 键）
 */
class OfficialStatsService
{
    const KEY_MEMBER_BASE = 'official:mbase';
    const KEY_VIEW_PREFIX = 'official:view:';
    const DEFAULT_BASE = 17888;
    const ONLINE_BUCKET_SEC = 7;

    public static function memberBase()
    {
        try {
            $r = RedisClient::conn();
            $v = $r->get(RedisClient::key(self::KEY_MEMBER_BASE));
            if ($v !== false && $v !== null && $v !== '') {
                $n = (int)$v;
                if ($n > 0) {
                    return $n;
                }
            }
            $seed = self::seedFromDb();
            $r->set(RedisClient::key(self::KEY_MEMBER_BASE), $seed);
            return $seed;
        } catch (\Throwable $e) {
            return self::seedFromDb();
        }
    }

    public static function onlineCount($groupId)
    {
        return self::floatingOnline((int)$groupId) + self::viewerCount((int)$groupId);
    }

    public static function floatingOnline($groupId)
    {
        $groupId = (int)$groupId;
        $bucket = (int)floor(time() / self::ONLINE_BUCKET_SEC);
        $h = crc32('og:' . $groupId . ':' . $bucket);
        if ($h < 0) {
            $h = -$h;
        }
        return 2200 + ($h % 2700);
    }

    public static function viewerCount($groupId)
    {
        $groupId = (int)$groupId;
        if ($groupId <= 0) {
            return 0;
        }
        try {
            return max(0, (int)RedisClient::conn()->sCard(RedisClient::key(self::KEY_VIEW_PREFIX . $groupId)));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function enterView($groupId, $userId)
    {
        $groupId = (int)$groupId;
        $userId = (int)$userId;
        if ($groupId <= 0 || $userId <= 0) {
            return self::onlineCount($groupId);
        }
        try {
            $r = RedisClient::conn();
            $key = RedisClient::key(self::KEY_VIEW_PREFIX . $groupId);
            $r->sAdd($key, (string)$userId);
            $r->expire($key, 90);
        } catch (\Throwable $e) {
        }
        return self::onlineCount($groupId);
    }

    public static function leaveView($groupId, $userId)
    {
        $groupId = (int)$groupId;
        $userId = (int)$userId;
        if ($groupId <= 0 || $userId <= 0) {
            return self::onlineCount($groupId);
        }
        try {
            RedisClient::conn()->sRem(RedisClient::key(self::KEY_VIEW_PREFIX . $groupId), (string)$userId);
        } catch (\Throwable $e) {
        }
        return self::onlineCount($groupId);
    }

    protected static function seedFromDb()
    {
        try {
            $row = Db::fetch(
                'SELECT MAX(IFNULL(display_member_count,0)) AS m FROM ' . Db::table('chat_groups')
                . ' WHERE status IN (1,3) AND IFNULL(is_recommend,0)=1'
            );
            $m = (int)($row['m'] ?? 0);
            if ($m >= 10000) {
                return $m;
            }
        } catch (\Throwable $e) {
        }
        return self::DEFAULT_BASE;
    }

    public static function isOfficialRecommend(array $group)
    {
        return (int)($group['is_recommend'] ?? 0) === 1;
    }
}
