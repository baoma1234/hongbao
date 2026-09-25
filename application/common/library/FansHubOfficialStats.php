<?php

namespace app\common\library;

use think\Db;

/**
 * 官方社群展示人数（全端一致）
 * - 每个群各自成员基数（约 1.7万～1.8万；注册时各群 +1）
 * - 展示值 = 持久化基数（无秒级抖动）；定时任务每日小幅上浮，偶尔 -1/-2
 * - 在线人数：全站合计 16000～20000；08:00–22:00 中枢约 20000，22:00–08:00 中枢约 16000；
 *   每分钟 ±10～30 游走并缓缓拉向当前时段中枢；约 20% 分给真人视讯，其余分到各官方群
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

    /** 在线合计：夜间中枢 / 日间中枢 / 硬上下限 */
    const ONLINE_MIN = 16000;
    const ONLINE_MAX = 20000;
    const ONLINE_NIGHT_CENTER = 16000;
    const ONLINE_DAY_CENTER = 20000;
    /** 兼容旧常量名（取日夜中点） */
    const ONLINE_TOTAL_BASE = 18000;
    const ONLINE_STEP_MIN = 10;
    const ONLINE_STEP_MAX = 30;
    /** @deprecated 改用 ONLINE_MIN/MAX；保留避免外部引用报错 */
    const ONLINE_DRIFT_MAX = 2500;
    /** @deprecated 新分摊不再限制群间极差；保留常量避免外部引用报错 */
    const ONLINE_MAX_GROUP_DIFF = 500;
    /** 在线刷新桶：1 分钟 */
    const ONLINE_BUCKET_SEC = 60;
    /** 早晚过渡时长（分钟）：08:00 起升、22:00 前降 */
    const ONLINE_RAMP_MINUTES = 90;
    /** 在线合计中「头部群」占比（群 11 + 17 合计约 40%，相对群侧预算） */
    const ONLINE_FOCUS_SHARE = 0.40;
    /** 头部群 id：扫雷 11、指定 17（不在列表则由其余头部均分） */
    const ONLINE_FOCUS_GROUP_IDS = [11, 17];
    /** 其余群相对均分的抖动幅度（约 ±12%） */
    const ONLINE_OTHER_JITTER_RATIO = 0.12;
    /** 频道/影音群不参与大厅在线分摊 */
    const ONLINE_EXCLUDE_GROUP_IDS = [70, 71, 72, 77];
    /** 全站在线中划给真人视讯的比例 */
    const ONLINE_LIVE_SHARE = 0.20;
    /** 真人视讯大厅游戏 key（与 fa_fans_lobby_games.game_key 一致） */
    const ONLINE_LIVE_GAME_KEYS = ['og_baccarat', 'og_dragon', 'og_roulette', 'og_niuniu'];

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

    /** 某一分钟桶的确定性步长：±ONLINE_STEP_MIN～MAX */
    public static function onlineStepForBucket($bucket)
    {
        $h = crc32('otstep:' . (int)$bucket);
        if ($h < 0) {
            $h = -$h;
        }
        $span = self::ONLINE_STEP_MAX - self::ONLINE_STEP_MIN + 1;
        $mag = self::ONLINE_STEP_MIN + ($h % $span);
        $sign = ($h & 1) ? 1 : -1;
        return $sign * $mag;
    }

    /** smoothstep：0～1 缓入缓出 */
    protected static function onlineSmoothstep($x)
    {
        $x = max(0.0, min(1.0, (float)$x));
        return $x * $x * (3.0 - 2.0 * $x);
    }

    /**
     * 按时段给出在线中枢：08:00–22:00 → 20000，22:00–08:00 → 16000（边界 90 分钟平滑过渡）
     */
    public static function onlineCenterForBucket($bucket)
    {
        $t = (int)$bucket * self::ONLINE_BUCKET_SEC;
        $mins = ((int)date('G', $t)) * 60 + (int)date('i', $t);
        $dayOn = 8 * 60;
        $dayOff = 22 * 60;
        $ramp = max(1, (int)self::ONLINE_RAMP_MINUTES);
        $lo = (float)self::ONLINE_NIGHT_CENTER;
        $hi = (float)self::ONLINE_DAY_CENTER;

        $p = 0.0;
        if ($mins >= $dayOn && $mins < $dayOff) {
            if ($mins < $dayOn + $ramp) {
                $p = self::onlineSmoothstep(($mins - $dayOn) / $ramp);
            } elseif ($mins > $dayOff - $ramp) {
                $p = self::onlineSmoothstep(($dayOff - $mins) / $ramp);
            } else {
                $p = 1.0;
            }
        }

        return (int)round($lo + ($hi - $lo) * $p);
    }

    /**
     * 当前分钟的在线合计：围绕时段中枢做 ±10～30 游走，并缓缓拉回中枢；硬夹在 16000～20000
     */
    public static function onlineTotalForBucket($bucket = null)
    {
        $bucket = $bucket !== null ? (int)$bucket : self::onlineBucket();
        static $memo = [];
        if (isset($memo[$bucket])) {
            return $memo[$bucket];
        }

        $dayStart = (int)(floor($bucket / 1440) * 1440);
        $prev = $bucket - 1;
        if ($prev >= $dayStart && isset($memo[$prev])) {
            $v = (int)$memo[$prev] + self::onlineStepForBucket($bucket);
            $center = self::onlineCenterForBucket($bucket);
            $err = $center - $v;
            if (abs($err) > 20) {
                $v += (int)round($err / 80);
            }
        } else {
            $v = self::onlineCenterForBucket($dayStart);
            $dayH = crc32('otday:' . $dayStart);
            if ($dayH < 0) {
                $dayH = -$dayH;
            }
            $v += ($dayH % 61) - 30;
            $v = max(self::ONLINE_MIN, min(self::ONLINE_MAX, $v));
            for ($b = $dayStart + 1; $b <= $bucket; $b++) {
                $v += self::onlineStepForBucket($b);
                $center = self::onlineCenterForBucket($b);
                $err = $center - $v;
                if (abs($err) > 20) {
                    $v += (int)round($err / 80);
                }
                if ($v < self::ONLINE_MIN) {
                    $v = self::ONLINE_MIN + 5;
                } elseif ($v > self::ONLINE_MAX) {
                    $v = self::ONLINE_MAX - 5;
                }
            }
        }

        if ($v < self::ONLINE_MIN) {
            $v = self::ONLINE_MIN + 5;
        } elseif ($v > self::ONLINE_MAX) {
            $v = self::ONLINE_MAX - 5;
        }

        $out = max(self::ONLINE_MIN, min(self::ONLINE_MAX, (int)$v));
        $memo[$bucket] = $out;
        if (count($memo) > 16) {
            $memo = [$bucket => $out];
        }
        return $out;
    }

    /** 官方推荐群 id 列表（短缓存；排除频道影音群） */
    public static function officialRecommendIds()
    {
        $now = time();
        if (is_array(self::$officialIdsCache) && ($now - self::$officialIdsCacheAt) < 60) {
            return self::$officialIdsCache;
        }
        $ids = [];
        $exclude = array_fill_keys(self::ONLINE_EXCLUDE_GROUP_IDS, true);
        try {
            $hasType = false;
            try {
                $hasType = !empty(Db::query("SHOW COLUMNS FROM `fa_chat_groups` LIKE 'group_type'"));
            } catch (\Throwable $eCol) {
                $hasType = false;
            }
            $query = Db::name('chat_groups')
                ->where('status', 'in', [1, 3])
                ->where('is_recommend', 1);
            if (self::hasMaintenanceColumn()) {
                $query->where(function ($q) {
                    $q->whereNull('maintenance')->whereOr('maintenance', 0);
                });
            }
            if ($hasType) {
                $query->where(function ($q) {
                    $q->whereNull('group_type')->whereOr('group_type', 'in', ['', 'group']);
                });
            }
            $rows = $query->order('weigh', 'desc')->order('id', 'asc')->column('id');
            foreach ((array)$rows as $id) {
                $id = (int)$id;
                if ($id > 0 && empty($exclude[$id])) {
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
     * 将合计分到各官方群：11+17 合计约 40%，其余群均分并带抖动；求和 = total
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

        $totalAll = self::onlineTotalForBucket($bucket);
        $liveBudget = (int)round($totalAll * self::ONLINE_LIVE_SHARE);
        if ($liveBudget < 400) {
            $liveBudget = 400;
        }
        if ($liveBudget > (int)floor($totalAll * 0.35)) {
            $liveBudget = (int)floor($totalAll * 0.35);
        }
        $total = max(0, $totalAll - $liveBudget);
        $focusWant = self::ONLINE_FOCUS_GROUP_IDS;
        $focus = [];
        $others = [];
        foreach ($ids as $gid) {
            if (in_array($gid, $focusWant, true)) {
                $focus[] = $gid;
            } else {
                $others[] = $gid;
            }
        }

        $raw = [];
        if (!$focus) {
            // 无头部群：全部均分+抖动
            $raw = self::splitWithJitter($ids, $total, $bucket, 'all');
        } else {
            $focusBudget = (int)round($total * self::ONLINE_FOCUS_SHARE);
            if ($focusBudget < count($focus) * 80) {
                $focusBudget = count($focus) * 80;
            }
            if ($focusBudget > $total - max(0, count($others)) * 80) {
                $focusBudget = max(0, $total - max(0, count($others)) * 80);
            }
            $otherBudget = $total - $focusBudget;
            foreach (self::splitWithJitter($focus, $focusBudget, $bucket, 'focus') as $gid => $v) {
                $raw[$gid] = $v;
            }
            if ($others) {
                foreach (self::splitWithJitter($others, $otherBudget, $bucket, 'other') as $gid => $v) {
                    $raw[$gid] = $v;
                }
            } elseif ($otherBudget !== 0 && $focus) {
                // 无「其余」时余数塞回头部第一个
                $raw[$focus[0]] = (int)$raw[$focus[0]] + $otherBudget;
            }
        }

        foreach ($raw as $gid => $v) {
            $raw[$gid] = max(80, (int)$v);
        }

        // 抹平求和误差
        $sum = 0;
        foreach ($raw as $v) {
            $sum += (int)$v;
        }
        $diff = $sum - $total;
        if ($diff !== 0 && $ids) {
            $i = 0;
            $step = $diff > 0 ? 1 : -1;
            $left = abs($diff);
            while ($left > 0) {
                $gid = $ids[$i % $n];
                $next = (int)$raw[$gid] - $step;
                if ($next >= 80) {
                    $raw[$gid] = $next;
                    $left--;
                }
                $i++;
                if ($i > $n * 200 + 10) {
                    break;
                }
            }
        }

        if (count(self::$onlineMapMemo) > 4) {
            self::$onlineMapMemo = [];
        }
        self::$onlineMapMemo[$bucket] = $raw;
        return $raw;
    }

    /** 真人视讯侧预算（全站合计 × ONLINE_LIVE_SHARE） */
    public static function liveOnlineBudget($bucket = null)
    {
        $bucket = $bucket !== null ? (int)$bucket : self::onlineBucket();
        $total = self::onlineTotalForBucket($bucket);
        $budget = (int)round($total * self::ONLINE_LIVE_SHARE);
        if ($budget < 400) {
            $budget = 400;
        }
        if ($budget > (int)floor($total * 0.35)) {
            $budget = (int)floor($total * 0.35);
        }
        return max(0, $budget);
    }

    /**
     * 真人视讯各游戏在线人数（game_key => count），求和 = liveOnlineBudget
     * @return array<string,int>
     */
    public static function liveOnlineMap($bucket = null)
    {
        $bucket = $bucket !== null ? (int)$bucket : self::onlineBucket();
        static $memo = [];
        if (isset($memo[$bucket])) {
            return $memo[$bucket];
        }
        $keys = self::ONLINE_LIVE_GAME_KEYS;
        $n = count($keys);
        if ($n <= 0) {
            $memo[$bucket] = [];
            return [];
        }
        $budget = self::liveOnlineBudget($bucket);
        $fakeIds = [];
        $idToKey = [];
        foreach ($keys as $i => $key) {
            $fid = $i + 1;
            $fakeIds[] = $fid;
            $idToKey[$fid] = $key;
        }
        $parts = self::splitWithJitter($fakeIds, $budget, $bucket, 'live');
        $out = [];
        foreach ($parts as $fid => $v) {
            $out[$idToKey[(int)$fid]] = max(80, (int)$v);
        }
        $sum = 0;
        foreach ($out as $v) {
            $sum += (int)$v;
        }
        $diff = $sum - $budget;
        if ($diff !== 0 && $keys) {
            $i = 0;
            $step = $diff > 0 ? 1 : -1;
            $left = abs($diff);
            while ($left > 0) {
                $k = $keys[$i % $n];
                $next = (int)$out[$k] - $step;
                if ($next >= 80) {
                    $out[$k] = $next;
                    $left--;
                }
                $i++;
                if ($i > $n * 200 + 10) {
                    break;
                }
            }
        }
        if (count($memo) > 4) {
            $memo = [];
        }
        $memo[$bucket] = $out;
        return $out;
    }

    public static function liveOnlineCount($gameKey)
    {
        $gameKey = strtolower(trim((string)$gameKey));
        if ($gameKey === '') {
            return 0;
        }
        $map = self::liveOnlineMap();
        return isset($map[$gameKey]) ? (int)$map[$gameKey] : 0;
    }

    /**
     * 均分 + 确定性抖动（同分钟同群稳定；求和尽量贴近 budget）
     * @param int[] $ids
     * @return array<int,int>
     */
    protected static function splitWithJitter(array $ids, $budget, $bucket, $tag)
    {
        $ids = array_values($ids);
        $n = count($ids);
        $budget = (int)$budget;
        if ($n <= 0) {
            return [];
        }
        if ($n === 1) {
            return [$ids[0] => max(80, $budget)];
        }

        $base = (int)floor($budget / $n);
        $raw = [];
        $weights = [];
        $wSum = 0.0;
        foreach ($ids as $gid) {
            $h = crc32($tag . ':ogj:' . (int)$gid . ':' . (int)$bucket);
            if ($h < 0) {
                $h = -$h;
            }
            // 0.88～1.12 相对权重
            $ratio = 1.0 + ((((int)($h % 1000)) / 1000.0) * 2.0 - 1.0) * self::ONLINE_OTHER_JITTER_RATIO;
            if ($ratio < 0.5) {
                $ratio = 0.5;
            }
            $weights[$gid] = $ratio;
            $wSum += $ratio;
        }
        $assigned = 0;
        $last = $ids[$n - 1];
        foreach ($ids as $gid) {
            if ($gid === $last) {
                continue;
            }
            $v = (int)round($budget * ($weights[$gid] / $wSum));
            $v = max(80, $v);
            $raw[$gid] = $v;
            $assigned += $v;
        }
        $raw[$last] = max(80, $budget - $assigned);
        // 若 last 被夹到 80 导致偏差，从最大的群回补
        $sum = 0;
        foreach ($raw as $v) {
            $sum += (int)$v;
        }
        $diff = $sum - $budget;
        if ($diff !== 0) {
            arsort($raw);
            foreach ($raw as $gid => $v) {
                if ($diff === 0) {
                    break;
                }
                $step = $diff > 0 ? 1 : -1;
                $next = (int)$v - $step;
                if ($next >= 80) {
                    $raw[$gid] = $next;
                    $diff -= $step;
                }
            }
        }
        return $raw;
    }

    public static function onlineCount($groupId)
    {
        $groupId = (int)$groupId;
        if ($groupId <= 0) {
            return 0;
        }
        if (self::isMaintenanceGroup($groupId)) {
            return 0;
        }
        $map = self::onlineCountMap();
        if (isset($map[$groupId])) {
            return (int)$map[$groupId];
        }
        // 非官方推荐群：沿用旧兜底（按分钟桶小幅浮动，避免秒级乱跳）
        return max(0, self::onlineBase($groupId) + self::floatDelta('oo:' . $groupId, self::onlineBucket()));
    }

    public static function hasMaintenanceColumn()
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        try {
            $cached = !empty(Db::query("SHOW COLUMNS FROM `fa_chat_groups` LIKE 'maintenance'"));
        } catch (\Throwable $e) {
            $cached = false;
        }
        return $cached;
    }

    public static function isMaintenanceGroup($groupId)
    {
        $groupId = (int)$groupId;
        if ($groupId <= 0 || !self::hasMaintenanceColumn()) {
            return false;
        }
        static $memo = [];
        static $memoAt = 0;
        $now = time();
        if (($now - $memoAt) > 30) {
            $memo = [];
            $memoAt = $now;
        }
        if (array_key_exists($groupId, $memo)) {
            return $memo[$groupId];
        }
        try {
            $v = (int)Db::name('chat_groups')->where('id', $groupId)->value('maintenance');
            $memo[$groupId] = ($v === 1);
        } catch (\Throwable $e) {
            $memo[$groupId] = false;
        }
        return $memo[$groupId];
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
