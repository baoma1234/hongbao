<?php

namespace app\common\library;

use think\Db;

/**
 * 官方社群展示人数（全端一致）
 * - 每个群各自成员基数（约 1.7万～1.8万；注册时各群 +1）
 * - 展示值 = 持久化基数（无秒级抖动）；定时任务每日小幅上浮，偶尔 -1/-2
 * - 在线人数：全站合计约 15000 ±2000，按桶刷新后随机分到各官方群，群间相差 ≤500
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

    /** 在线合计中枢与振幅 */
    const ONLINE_TOTAL_BASE = 15000;
    const ONLINE_TOTAL_AMPLITUDE = 2000;
    /** 任意两官方群在线相差上限 */
    const ONLINE_MAX_GROUP_DIFF = 500;
    /** 在线刷新桶（秒），与大厅/社群轮询接近 */
    const ONLINE_BUCKET_SEC = 20;

    /** @var \Redis|null */
    protected static $redis;

    /** @var array|null */
    protected static $officialIdsCache;
    /** @var int */
    protected static $officialIdsCacheAt = 0;
    /** @var array<int,array<int,int>> */
    protected static $onlineMapMemo = [];


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
        // 非官方群兜底：约 2200～4899
        return 2200 + ($h % 2700);
    }

    public static function onlineBucket($time = null)
    {
        $t = $time !== null ? (int)$time : time();
        return (int)floor($t / self::ONLINE_BUCKET_SEC);
    }

    /** 当前桶的在线合计：15000 ± 2000（桶内全端一致） */
    public static function onlineTotalForBucket($bucket = null)
    {
        $bucket = $bucket !== null ? (int)$bucket : self::onlineBucket();
        $h = crc32('ot:' . $bucket);
        if ($h < 0) {
            $h = -$h;
        }
        $span = self::ONLINE_TOTAL_AMPLITUDE * 2 + 1;
        $delta = ($h % $span) - self::ONLINE_TOTAL_AMPLITUDE;
        return max(1000, self::ONLINE_TOTAL_BASE + $delta);
    }

    /** 官方推荐群 id 列表（短缓存） */
    public static function officialRecommendIds()
    {
        $now = time();
        if (is_array(self::$officialIdsCache) && ($now - self::$officialIdsCacheAt) < 60) {
            return self::$officialIdsCache;
        }
        $ids = [];
        try {
            $rows = Db::name('chat_groups')
                ->where('status', 'in', [1, 3])
                ->where('is_recommend', 1)
                ->order('weigh', 'desc')
                ->order('id', 'asc')
                ->column('id');
            foreach ((array)$rows as $id) {
                $id = (int)$id;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        } catch (\Throwable $e) {
            $ids = [];
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        self::$officialIdsCache = $ids;
        self::$officialIdsCacheAt = $now;
        return $ids;
    }

    /**
     * 将合计随机分到各官方群：群间相差 ≤ ONLINE_MAX_GROUP_DIFF，且求和 = total
     * @return array<int,int> gid => online
     */
    public static function onlineCountMap($bucket = null)
    {
        $bucket = $bucket !== null ? (int)$bucket : self::onlineBucket();
        if (isset(self::$onlineMapMemo[$bucket])) {
            return self::$onlineMapMemo[$bucket];
        }

        $ids = self::officialRecommendIds();
        $n = count($ids);
        if ($n <= 0) {
            self::$onlineMapMemo[$bucket] = [];
            return [];
        }

        $total = self::onlineTotalForBucket($bucket);
        $half = (int)floor(self::ONLINE_MAX_GROUP_DIFF / 2);
        $base = (int)floor($total / $n);
        $rem = (int)($total % $n);

        $raw = [];
        foreach ($ids as $i => $gid) {
            $h = crc32('og:' . $bucket . ':' . $gid);
            if ($h < 0) {
                $h = -$h;
            }
            // [-half, +half]，保证任意两群原始偏移差 ≤ MAX
            $off = ($h % (self::ONLINE_MAX_GROUP_DIFF + 1)) - $half;
            $raw[$gid] = $base + $off + ($i < $rem ? 1 : 0);
        }

        // 偏移后求和可能偏离 total，按稳定顺序抹平
        $sum = 0;
        foreach ($raw as $v) {
            $sum += (int)$v;
        }
        $diff = $sum - $total;
        if ($diff !== 0) {
            $i = 0;
            $step = $diff > 0 ? 1 : -1;
            $left = abs($diff);
            while ($left > 0) {
                $gid = $ids[$i % $n];
                $raw[$gid] -= $step;
                $left--;
                $i++;
            }
        }

        foreach ($raw as $gid => $v) {
            $raw[$gid] = max(80, (int)$v);
        }

        // 再压一遍极差（抹平后偶发超限）
        $min = min($raw);
        $max = max($raw);
        if (($max - $min) > self::ONLINE_MAX_GROUP_DIFF) {
            $mid = (int)round(($min + $max) / 2);
            foreach ($raw as $gid => $v) {
                $raw[$gid] = max($mid - $half, min($mid + $half, (int)$v));
            }
            $sum2 = 0;
            foreach ($raw as $v) {
                $sum2 += (int)$v;
            }
            $diff2 = $sum2 - $total;
            if ($diff2 !== 0) {
                $i = 0;
                $step = $diff2 > 0 ? 1 : -1;
                $left = abs($diff2);
                while ($left > 0) {
                    $gid = $ids[$i % $n];
                    $next = (int)$raw[$gid] - $step;
                    if ($next >= ($mid - $half) && $next <= ($mid + $half)) {
                        $raw[$gid] = $next;
                        $left--;
                    }
                    $i++;
                    if ($i > $n * self::ONLINE_MAX_GROUP_DIFF + 10) {
                        break;
                    }
                }
            }
        }

        // 只保留最近几个桶，避免常驻内存膨胀
        if (count(self::$onlineMapMemo) > 4) {
            self::$onlineMapMemo = [];
        }
        self::$onlineMapMemo[$bucket] = $raw;
        return $raw;
    }

    public static function onlineCount($groupId)
    {
        $groupId = (int)$groupId;
        if ($groupId <= 0) {
            return 0;
        }
        $map = self::onlineCountMap();
        if (isset($map[$groupId])) {
            return (int)$map[$groupId];
        }
        // 非官方推荐群：沿用旧兜底
        return max(0, self::onlineBase($groupId) + self::floatDelta('oo:' . $groupId));
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
