<?php

namespace Im\Service;

use Im\Support\CatchLog;

use Im\Support\Db;
use Im\Support\RedisClient;

/**
 * 官方社群展示人数（与 PHP FansHubOfficialStats 一致）
 * - 成员：持久化基数，无秒级抖动
 * - 在线：全站合计 16000～20000；08:00–22:00 中枢约 20000，22:00–08:00 中枢约 16000；约 20% 分给真人视讯
 */
class OfficialStatsService
{
    const KEY_MEMBER_PREFIX = 'official:mbase:';
    const KEY_VIEW_PREFIX = 'official:view:';
    const DEFAULT_BASE = 17888;
    const FLOAT_BUCKET_SEC = 2;
    const FLOAT_MAX = 10;

    const ONLINE_MIN = 16000;
    const ONLINE_MAX = 20000;
    const ONLINE_NIGHT_CENTER = 16000;
    const ONLINE_DAY_CENTER = 20000;
    const ONLINE_STEP_MIN = 10;
    const ONLINE_STEP_MAX = 30;
    const ONLINE_MAX_GROUP_DIFF = 500;
    const ONLINE_BUCKET_SEC = 60;
    const ONLINE_RAMP_MINUTES = 90;
    const ONLINE_FOCUS_SHARE = 0.40;
    const ONLINE_FOCUS_GROUP_IDS = [11, 17];
    const ONLINE_OTHER_JITTER_RATIO = 0.12;
    const ONLINE_EXCLUDE_GROUP_IDS = [70, 71, 72, 77];
    const ONLINE_LIVE_SHARE = 0.20;

    /** @var array|null */
    protected static $officialIdsCache;
    /** @var int */
    protected static $officialIdsCacheAt = 0;
    /** @var array<int,array<int,int>> */
    protected static $onlineMapMemo = [];

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

    public static function onlineBucket($time = null)
    {
        $t = $time !== null ? (int)$time : time();
        return (int)floor($t / self::ONLINE_BUCKET_SEC);
    }

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

    protected static function onlineSmoothstep($x)
    {
        $x = max(0.0, min(1.0, (float)$x));
        return $x * $x * (3.0 - 2.0 * $x);
    }

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

    public static function officialRecommendIds()
    {
        $now = time();
        if (is_array(self::$officialIdsCache) && ($now - self::$officialIdsCacheAt) < 60) {
            return self::$officialIdsCache;
        }
        $ids = [];
        $exclude = array_fill_keys(self::ONLINE_EXCLUDE_GROUP_IDS, true);
        try {
            $table = Db::table('chat_groups');
            $rows = Db::fetchAll(
                'SELECT id, group_type FROM ' . $table
                . ' WHERE status IN (1,3) AND is_recommend=1 ORDER BY weigh DESC, id ASC'
            );
            foreach ((array)$rows as $row) {
                $id = (int)($row['id'] ?? 0);
                if ($id <= 0 || !empty($exclude[$id])) {
                    continue;
                }
                $gt = strtolower(trim((string)($row['group_type'] ?? '')));
                if ($gt === 'channel') {
                    continue;
                }
                $ids[] = $id;
            }
        } catch (\Throwable $e) {
            // 无 group_type 列时回退
            try {
                $rows = Db::fetchAll(
                    'SELECT id FROM ' . Db::table('chat_groups')
                    . ' WHERE status IN (1,3) AND is_recommend=1 ORDER BY weigh DESC, id ASC'
                );
                foreach ((array)$rows as $row) {
                    $id = (int)($row['id'] ?? 0);
                    if ($id > 0 && empty($exclude[$id])) {
                        $ids[] = $id;
                    }
                }
            } catch (\Throwable $e2) {
                CatchLog::quiet($e2, 'Service.OfficialStatsService');
                $ids = [];
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        self::$officialIdsCache = $ids;
        self::$officialIdsCacheAt = $now;
        return $ids;
    }

    /** @return array<int,int> */
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
                $raw[$focus[0]] = (int)$raw[$focus[0]] + $otherBudget;
            }
        }

        foreach ($raw as $gid => $v) {
            $raw[$gid] = max(80, (int)$v);
        }

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

    /**
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

        $raw = [];
        $weights = [];
        $wSum = 0.0;
        foreach ($ids as $gid) {
            $h = crc32($tag . ':ogj:' . (int)$gid . ':' . (int)$bucket);
            if ($h < 0) {
                $h = -$h;
            }
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
        $map = self::onlineCountMap();
        if (isset($map[$groupId])) {
            return (int)$map[$groupId];
        }
        return max(0, self::onlineBase($groupId) + self::floatDelta('oo:' . $groupId, self::onlineBucket()));
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
