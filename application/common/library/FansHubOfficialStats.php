<?php

namespace app\common\library;

use think\Db;

/**
 * 官方社群展示人数（全端一致）
 * - 每个群各自成员基数（约 1.7万～1.8万；注册时各群 +1）
 * - 展示值 = 持久化基数（无秒级抖动）；定时任务每日小幅上浮，偶尔 -1/-2
 * - 在线人数已不再对用户展示
 */
class FansHubOfficialStats
{
    const REDIS_DB = 2;
    const REDIS_PREFIX = 'im:';
    const KEY_MEMBER_PREFIX = 'official:mbase:';
    const KEY_VIEW_PREFIX = 'official:view:';
    const KEY_DAILY_DRIFT = 'official:drift:';
    const DEFAULT_BASE = 17888;
    const FLOAT_BUCKET_SEC = 2;
    const FLOAT_MAX = 10;

    /** @var \Redis|null */
    protected static $redis;

    /** 相对基数的确定性偏移：[-10, 10]（兼容旧调用；展示人数已不再叠加） */
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

    /** 每个群固定成员基数（不含浮动） */
    public static function memberBaseForGroup($groupId)
    {
        $groupId = (int)$groupId;
        if ($groupId <= 0) {
            return self::DEFAULT_BASE;
        }
        try {
            $r = self::redis();
            if ($r) {
                $v = $r->get(self::REDIS_PREFIX . self::KEY_MEMBER_PREFIX . $groupId);
                if ($v !== false && $v !== null && $v !== '') {
                    $n = (int)$v;
                    if ($n > 0) {
                        return $n;
                    }
                }
            }
        } catch (\Throwable $e) {
        }
        $seed = self::seedBaseForGroup($groupId);
        try {
            $r = self::redis();
            if ($r) {
                $r->set(self::REDIS_PREFIX . self::KEY_MEMBER_PREFIX . $groupId, $seed);
            }
        } catch (\Throwable $e2) {
        }
        return $seed;
    }

    /** 展示总人数 = 持久化基数（全端一致，无秒级抖动） */
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

    /**
     * 每日漂移（幂等）：多数群 +2～+6，约 20% 概率再 -1 或 -2
     * @return int 处理群数
     */
    public static function applyDailyMemberDrift($ymd = null)
    {
        $ymd = $ymd !== null ? preg_replace('/\D+/', '', (string)$ymd) : date('Ymd');
        if ($ymd === '') {
            $ymd = date('Ymd');
        }
        $r = null;
        try {
            $r = self::redis();
        } catch (\Throwable $e) {
        }
        $lockKey = self::REDIS_PREFIX . self::KEY_DAILY_DRIFT . $ymd;
        if ($r) {
            try {
                if (!$r->set($lockKey, '1', ['nx', 'ex' => 86400 * 3])) {
                    return 0;
                }
            } catch (\Throwable $e2) {
            }
        }

        try {
            $rows = Db::name('chat_groups')
                ->where('status', 'in', [1, 3])
                ->where('is_recommend', 1)
                ->field('id,display_member_count')
                ->select();
        } catch (\Throwable $e3) {
            return 0;
        }
        $now = time();
        $n = 0;
        foreach ((array)$rows as $g) {
            $gid = (int)($g['id'] ?? 0);
            if ($gid <= 0) {
                continue;
            }
            $h = crc32('drift:' . $ymd . ':' . $gid);
            if ($h < 0) {
                $h = -$h;
            }
            $up = 2 + ($h % 5); // 2～6
            $down = 0;
            if (($h % 10) < 2) {
                $down = 1 + (($h >> 3) % 2); // 1 或 2
            }
            $delta = $up - $down;
            if ($delta === 0) {
                continue;
            }
            $cur = (int)($g['display_member_count'] ?? 0);
            if ($cur < 10000) {
                $cur = self::uniqueSeedForGroup($gid);
            }
            $base = max(1000, $cur + $delta);
            try {
                Db::name('chat_groups')->where('id', $gid)->update([
                    'display_member_count' => $base,
                    'updatetime'           => $now,
                ]);
            } catch (\Throwable $e4) {
                continue;
            }
            if ($r) {
                try {
                    $r->set(self::REDIS_PREFIX . self::KEY_MEMBER_PREFIX . $gid, $base);
                } catch (\Throwable $e5) {
                }
            }
            $n++;
        }
        if (class_exists(FansHubService::class) && method_exists(FansHubService::class, 'clearOfficialCommunityCache')) {
            FansHubService::clearOfficialCommunityCache();
        }
        return $n;
    }

    /** 新注册：所有官方群成员基数各 +1 */
    public static function bumpMembers($delta = 1)
    {
        $delta = (int)$delta;
        if ($delta === 0) {
            return;
        }
        try {
            $rows = Db::name('chat_groups')
                ->where('status', 'in', [1, 3])
                ->where('is_recommend', 1)
                ->field('id,display_member_count')
                ->select();
        } catch (\Throwable $e) {
            $rows = [];
        }
        $now = time();
        $r = null;
        try {
            $r = self::redis();
        } catch (\Throwable $e2) {
        }
        foreach ((array)$rows as $g) {
            $gid = (int)($g['id'] ?? 0);
            if ($gid <= 0) {
                continue;
            }
            $cur = (int)($g['display_member_count'] ?? 0);
            if ($cur < 10000) {
                $cur = self::uniqueSeedForGroup($gid);
            }
            $base = max(1, $cur + $delta);
            try {
                Db::name('chat_groups')->where('id', $gid)->update([
                    'display_member_count' => $base,
                    'updatetime'           => $now,
                ]);
            } catch (\Throwable $e3) {
            }
            if ($r) {
                try {
                    $r->set(self::REDIS_PREFIX . self::KEY_MEMBER_PREFIX . $gid, $base);
                } catch (\Throwable $e4) {
                }
            }
        }
        if (class_exists(FansHubService::class) && method_exists(FansHubService::class, 'clearOfficialCommunityCache')) {
            FansHubService::clearOfficialCommunityCache();
        } else {
            try {
                \think\Cache::rm('fanshub_official_communities_v1');
            } catch (\Throwable $e5) {
            }
        }
    }

    public static function onlineBase($groupId)
    {
        $groupId = (int)$groupId;
        $h = crc32('ob:' . $groupId);
        if ($h < 0) {
            $h = -$h;
        }
        // 约 2200～4899，每群固定不同
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
            $r = self::redis();
            if (!$r) {
                return 0;
            }
            $n = (int)$r->sCard(self::REDIS_PREFIX . self::KEY_VIEW_PREFIX . $groupId);
            return max(0, $n);
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
            $r = self::redis();
            if ($r) {
                $key = self::REDIS_PREFIX . self::KEY_VIEW_PREFIX . $groupId;
                $r->sAdd($key, (string)$userId);
                $r->expire($key, 90);
            }
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
            $r = self::redis();
            if ($r) {
                $key = self::REDIS_PREFIX . self::KEY_VIEW_PREFIX . $groupId;
                $r->sRem($key, (string)$userId);
            }
        } catch (\Throwable $e) {
        }
        return self::onlineCount($groupId);
    }

    public static function touchView($groupId, $userId)
    {
        return self::enterView($groupId, $userId);
    }

    /** 每群不同的默认基数：17000～18999 */
    public static function uniqueSeedForGroup($groupId)
    {
        $groupId = (int)$groupId;
        $h = crc32('mb:' . $groupId);
        if ($h < 0) {
            $h = -$h;
        }
        return 17000 + ($h % 2000);
    }

    protected static function seedBaseForGroup($groupId)
    {
        $groupId = (int)$groupId;
        try {
            $row = Db::name('chat_groups')->where('id', $groupId)->field('display_member_count')->find();
            $n = (int)($row['display_member_count'] ?? 0);
            if ($n >= 10000) {
                return $n;
            }
        } catch (\Throwable $e) {
        }
        return self::uniqueSeedForGroup($groupId);
    }

    /** 为所有官方群写入互不相同的 display_member_count */
    public static function diversifyOfficialMemberBases()
    {
        try {
            $rows = Db::name('chat_groups')
                ->where('status', 'in', [1, 3])
                ->where('is_recommend', 1)
                ->field('id,display_member_count')
                ->select();
        } catch (\Throwable $e) {
            return 0;
        }
        $now = time();
        $n = 0;
        $r = null;
        try {
            $r = self::redis();
        } catch (\Throwable $e2) {
        }
        $used = [];
        foreach ((array)$rows as $g) {
            $gid = (int)($g['id'] ?? 0);
            if ($gid <= 0) {
                continue;
            }
            $base = self::uniqueSeedForGroup($gid);
            // 避免偶发碰撞：同值则微调
            while (isset($used[$base])) {
                $base++;
            }
            $used[$base] = true;
            try {
                Db::name('chat_groups')->where('id', $gid)->update([
                    'display_member_count' => $base,
                    'updatetime'           => $now,
                ]);
            } catch (\Throwable $e3) {
            }
            if ($r) {
                try {
                    $r->set(self::REDIS_PREFIX . self::KEY_MEMBER_PREFIX . $gid, $base);
                } catch (\Throwable $e4) {
                }
            }
            $n++;
        }
        // 清掉旧的全局键
        if ($r) {
            try {
                $r->del(self::REDIS_PREFIX . 'official:mbase');
            } catch (\Throwable $e5) {
            }
        }
        if (class_exists(FansHubService::class) && method_exists(FansHubService::class, 'clearOfficialCommunityCache')) {
            FansHubService::clearOfficialCommunityCache();
        }
        return $n;
    }

    /** @return \Redis|null */
    public static function redisPublic()
    {
        return self::redis();
    }

    /** @return \Redis|null */
    protected static function redis()
    {
        if (self::$redis instanceof \Redis) {
            return self::$redis;
        }
        if (!class_exists('Redis')) {
            return null;
        }
        try {
            $host = '127.0.0.1';
            $port = 6379;
            $pass = '';
            $db = self::REDIS_DB;
            $rootEnv = dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . '.env';
            if (is_file($rootEnv)) {
                $ini = @parse_ini_file($rootEnv, true);
                if (is_array($ini) && !empty($ini['redis'])) {
                    $host = $ini['redis']['hostname'] ?? $host;
                    $port = (int)($ini['redis']['hostport'] ?? $port);
                    $pass = (string)($ini['redis']['password'] ?? $pass);
                }
            }
            $imLocal = dirname(dirname(dirname(__DIR__))) . '/im-server/config/local.php';
            if (is_file($imLocal)) {
                $local = include $imLocal;
                if (is_array($local) && !empty($local['redis'])) {
                    $host = $local['redis']['host'] ?? $host;
                    $port = (int)($local['redis']['port'] ?? $port);
                    $pass = (string)($local['redis']['password'] ?? $pass);
                    $db = (int)($local['redis']['db'] ?? $db);
                }
            }
            $r = new \Redis();
            if (!$r->connect($host, $port, 1.5)) {
                return null;
            }
            if ($pass !== '') {
                $r->auth($pass);
            }
            $r->select($db);
            self::$redis = $r;
            return $r;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
