<?php

namespace app\common\library;

use think\Config;
use think\Db;

/**
 * 官方社群展示人数 / 在线人数（全端一致）
 * - 所有官方群 member_count 共用同一基数（约 1.7万～1.8万，注册 +1）
 * - online_count = 时间桶确定性浮动（千级）+ 当前正在看群的人数
 */
class FansHubOfficialStats
{
    const REDIS_DB = 2;
    const REDIS_PREFIX = 'im:';
    const KEY_MEMBER_BASE = 'official:mbase';
    const KEY_VIEW_PREFIX = 'official:view:';
    const DEFAULT_BASE = 17888;
    const ONLINE_BUCKET_SEC = 7;

    /** @var \Redis|null */
    protected static $redis;

    public static function memberBase()
    {
        try {
            $r = self::redis();
            if ($r) {
                $v = $r->get(self::REDIS_PREFIX . self::KEY_MEMBER_BASE);
                if ($v !== false && $v !== null && $v !== '') {
                    $n = (int)$v;
                    if ($n > 0) {
                        return $n;
                    }
                }
                $seed = self::seedBaseFromDb();
                $r->set(self::REDIS_PREFIX . self::KEY_MEMBER_BASE, $seed);
                return $seed;
            }
        } catch (\Throwable $e) {
        }
        return self::seedBaseFromDb();
    }

    public static function bumpMembers($delta = 1)
    {
        $delta = (int)$delta;
        if ($delta === 0) {
            return self::memberBase();
        }
        $base = self::memberBase() + $delta;
        if ($base < 1) {
            $base = 1;
        }
        try {
            $r = self::redis();
            if ($r) {
                $r->set(self::REDIS_PREFIX . self::KEY_MEMBER_BASE, $base);
            }
        } catch (\Throwable $e) {
        }
        self::syncDisplayMemberCount($base);
        if (class_exists(FansHubService::class) && method_exists(FansHubService::class, 'clearOfficialCommunityCache')) {
            FansHubService::clearOfficialCommunityCache();
        } else {
            try {
                \think\Cache::rm('fanshub_official_communities_v1');
            } catch (\Throwable $e2) {
            }
        }
        return $base;
    }

    public static function onlineCount($groupId)
    {
        $groupId = (int)$groupId;
        $float = self::floatingOnline($groupId);
        $viewers = self::viewerCount($groupId);
        return $float + $viewers;
    }

    public static function floatingOnline($groupId)
    {
        $groupId = (int)$groupId;
        $bucket = (int)floor(time() / self::ONLINE_BUCKET_SEC);
        // 全端同一公式 → 同一时间桶大家看到的数字一致
        $h = crc32('og:' . $groupId . ':' . $bucket);
        if ($h < 0) {
            $h = -$h;
        }
        // 约 2200～4899
        return 2200 + ($h % 2700);
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

    protected static function seedBaseFromDb()
    {
        try {
            $max = (int)Db::name('chat_groups')
                ->where('status', 'in', [1, 3])
                ->where('is_recommend', 1)
                ->max('display_member_count');
            if ($max >= 10000) {
                return $max;
            }
            $max2 = (int)Db::name('chat_groups')->max('display_member_count');
            if ($max2 >= 10000) {
                return $max2;
            }
        } catch (\Throwable $e) {
        }
        return self::DEFAULT_BASE;
    }

    public static function syncDisplayMemberCount($base)
    {
        $base = max(1, (int)$base);
        try {
            Db::name('chat_groups')
                ->where('status', 'in', [1, 3])
                ->where('is_recommend', 1)
                ->update([
                    'display_member_count' => $base,
                    'updatetime'           => time(),
                ]);
        } catch (\Throwable $e) {
            try {
                Db::execute(
                    'UPDATE ' . Db::getTable('chat_groups')
                    . ' SET display_member_count=?, updatetime=? WHERE status IN (1,3) AND IFNULL(is_recommend,0)=1',
                    [$base, time()]
                );
            } catch (\Throwable $e2) {
            }
        }
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
            // IM local override
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
