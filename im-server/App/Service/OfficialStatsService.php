<?php

namespace Im\Service;

use Im\Support\CatchLog;

use Im\Support\Db;
use Im\Support\RedisClient;

/**
 * 官方社群展示人数（与 PHP FansHubOfficialStats 一致：持久化基数，无秒级抖动）
 */
class OfficialStatsService
{
    const KEY_MEMBER_PREFIX = 'official:mbase:';
    const KEY_VIEW_PREFIX = 'official:view:';
    const DEFAULT_BASE = 17888;
    const FLOAT_BUCKET_SEC = 2;
    const FLOAT_MAX = 10;

    public static function floatDelta($salt, $bucket = null)
    {
        if ($bucket === null) {
            $bucket = (int)floor(time() / self::FLOAT_BUCKET_SEC);
        }
        $h = crc32((string)$salt . ':' . (int)$bucket);
        if ($h < 0) {
            $h = -$h;
        }
        return ($h % (self::FLOAT_MAX * 2 + 1)) - self::FLOAT_MAX;
    }

    public static function uniqueSeedForGroup($groupId)
    {
        $groupId = (int)$groupId;
        $h = crc32('mb:' . $groupId);
        if ($h < 0) {
            $h = -$h;
        }
        return 17000 + ($h % 2000);
    }

    public static function memberBaseForGroup($groupId)
    {
        $groupId = (int)$groupId;
        if ($groupId <= 0) {
            return self::DEFAULT_BASE;
        }
        try {
            $r = RedisClient::conn();
            $v = $r->get(RedisClient::key(self::KEY_MEMBER_PREFIX . $groupId));
            if ($v !== false && $v !== null && $v !== '') {
                $n = (int)$v;
                if ($n > 0) {
                    return $n;
                }
            }
            $seed = self::seedFromDb($groupId);
            $r->set(RedisClient::key(self::KEY_MEMBER_PREFIX . $groupId), $seed);
            return $seed;
        } catch (\Throwable $e) {
            return self::seedFromDb($groupId);
        }
    }

    public static function memberCount($groupId, $base = null)
    {
        $groupId = (int)$groupId;
        if ($base === null || (int)$base <= 0) {
            $base = self::memberBaseForGroup($groupId);
        } else {
            $base = (int)$base;
        }
        return max(1, $base);
    }

    /** @deprecated 兼容旧调用：无 gid 时用默认 */
    public static function memberBase($groupId = 0)
    {
        $groupId = (int)$groupId;
        if ($groupId > 0) {
            return self::memberBaseForGroup($groupId);
        }
        return self::DEFAULT_BASE;
    }

    public static function onlineBase($groupId)
    {
        $groupId = (int)$groupId;
        $h = crc32('ob:' . $groupId);
        if ($h < 0) {
            $h = -$h;
        }
        return 2200 + ($h % 2700);
    }

    public static function onlineCount($groupId)
    {
        $groupId = (int)$groupId;
        return max(0, self::onlineBase($groupId) + self::floatDelta('oo:' . $groupId) + self::viewerCount($groupId));
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

    /**
     * 当前正在看该群的用户 ID（Redis Set，enter/leave + TTL）
     * @return int[]
     */
    public static function viewerUserIds($groupId)
    {
        $groupId = (int)$groupId;
        if ($groupId <= 0) {
            return [];
        }
        try {
            $ids = RedisClient::conn()->sMembers(RedisClient::key(self::KEY_VIEW_PREFIX . $groupId));
            if (!is_array($ids) || !$ids) {
                return [];
            }
            return array_values(array_unique(array_filter(array_map('intval', $ids))));
        } catch (\Throwable $e) {
            return [];
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
            CatchLog::quiet($e, 'Service.OfficialStatsService');
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
            CatchLog::quiet($e, 'Service.OfficialStatsService');
        }
        return self::onlineCount($groupId);
    }

    protected static function seedFromDb($groupId)
    {
        $groupId = (int)$groupId;
        try {
            $row = Db::fetch(
                'SELECT IFNULL(display_member_count,0) AS m FROM ' . Db::table('chat_groups') . ' WHERE id=? LIMIT 1',
                [$groupId]
            );
            $m = (int)($row['m'] ?? 0);
            if ($m >= 10000) {
                return $m;
            }
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.OfficialStatsService');
        }
        return self::uniqueSeedForGroup($groupId);
    }

    public static function isOfficialRecommend(array $group)
    {
        return (int)($group['is_recommend'] ?? 0) === 1;
    }
}
