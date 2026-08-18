<?php

namespace app\common\library;

use think\Cache;
use think\Config;

/**
 * 鱼虾蟹万人场：Redis HASH 下注 + 大厅 1s 快照。
 * 热路径禁止把整桌 PHP 数组 GET/SET。
 */
class FansHubYxxStore
{
    /** 0=大厅；>0 时下注/快照/派彩 key 带 g{id}: 前缀 */
    protected static $groupId = 0;

    public static function useGroup($groupId)
    {
        self::$groupId = max(0, (int)$groupId);
    }

    public static function groupId()
    {
        return (int)self::$groupId;
    }

    protected static function gpre()
    {
        $g = (int)self::$groupId;
        return $g > 0 ? ('g' . $g . ':') : '';
    }

    /** 不含 cache.prefix 的逻辑名 */
    public static function cacheName($short)
    {
        return 'fh:yxx:' . self::gpre() . $short;
    }

    public static function redis()
    {
        try {
            $driver = Cache::init();
            if (!is_object($driver) || !method_exists($driver, 'handler')) {
                return null;
            }
            $h = $driver->handler();
            if (!is_object($h) || !method_exists($h, 'hSet')) {
                return null;
            }
            return $h;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function rkey($name)
    {
        static $prefix = null;
        if ($prefix === null) {
            $prefix = (string)Config::get('cache.prefix');
        }
        return $prefix . $name;
    }

    public static function betHashKey($roundIndex)
    {
        return self::rkey(self::cacheName('bh:' . (int)$roundIndex));
    }

    public static function liveKey($roundIndex)
    {
        return self::rkey(self::cacheName('lv:' . (int)$roundIndex));
    }

    public static function statsKey($roundIndex)
    {
        return self::rkey(self::cacheName('st:' . (int)$roundIndex));
    }

    public static function snapKey()
    {
        return self::rkey(self::cacheName('snap'));
    }

    /**
     * SET key NX EX ttl。成功返回 true。
     */
    public static function acquireLock($name, $ttl = 8)
    {
        $redis = self::redis();
        $key = self::rkey($name);
        if (!$redis) {
            if (Cache::get($name)) {
                return false;
            }
            Cache::set($name, 1, (int)$ttl);
            return true;
        }
        try {
            $ok = $redis->set($key, '1', ['nx', 'ex' => max(1, (int)$ttl)]);
            if ($ok) {
                return true;
            }
            return false;
        } catch (\Throwable $e) {
            try {
                if ($redis->setnx($key, '1')) {
                    $redis->expire($key, max(1, (int)$ttl));
                    return true;
                }
            } catch (\Throwable $e2) {
            }
            return false;
        }
    }

    public static function releaseLock($name)
    {
        $redis = self::redis();
        if ($redis) {
            try {
                $redis->del(self::rkey($name));
            } catch (\Throwable $e) {
            }
            return;
        }
        Cache::rm($name);
    }

    /**
     * 写入/更新一笔下注。同一 uid 覆盖，stake 按差额计入桌面总额。
     */
    public static function putBet($roundIndex, array $row)
    {
        self::migrateLegacy($roundIndex);
        $uid = (int)($row['uid'] ?? 0);
        $stake = (int)($row['stake'] ?? 0);
        $json = json_encode($row, JSON_UNESCAPED_UNICODE);
        $redis = self::redis();
        $hk = self::betHashKey($roundIndex);
        $sk = self::statsKey($roundIndex);
        $lk = self::liveKey($roundIndex);
        $ttl = 180;

        if ($redis) {
            $oldJson = $redis->hGet($hk, (string)$uid);
            $oldStake = 0;
            $isNew = !$oldJson;
            if ($oldJson) {
                $old = json_decode($oldJson, true);
                $oldStake = (int)($old['stake'] ?? 0);
            }
            $delta = $stake - $oldStake;
            $redis->hSet($hk, (string)$uid, $json);
            $redis->expire($hk, $ttl);
            if ($delta !== 0) {
                $redis->hIncrBy($sk, 'stake', $delta);
            }
            if ($isNew) {
                $redis->hIncrBy($sk, 'players', 1);
                if (!empty($row['bot']) || $uid <= 0) {
                    $redis->hIncrBy($sk, 'bots', 1);
                }
            }
            $redis->expire($sk, $ttl);
            $redis->lPush($lk, $json);
            $redis->lTrim($lk, 0, 15);
            $redis->expire($lk, $ttl);
            self::clearSnap();
            return;
        }

        $rows = Cache::get(self::cacheName('bets:' . (int)$roundIndex));
        if (!is_array($rows)) {
            $rows = [];
        }
        $found = false;
        for ($i = 0; $i < count($rows); $i++) {
            if ((int)($rows[$i]['uid'] ?? 0) === $uid) {
                $rows[$i] = $row;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $rows[] = $row;
        }
        Cache::set(self::cacheName('bets:' . (int)$roundIndex), $rows, 120);
        self::clearSnap();
    }

    public static function getBet($roundIndex, $uid)
    {
        self::migrateLegacy($roundIndex);
        $uid = (int)$uid;
        $redis = self::redis();
        if ($redis) {
            $raw = $redis->hGet(self::betHashKey($roundIndex), (string)$uid);
            if (!$raw) {
                return null;
            }
            $row = json_decode($raw, true);
            return is_array($row) ? $row : null;
        }
        $rows = Cache::get(self::cacheName('bets:' . (int)$roundIndex));
        if (!is_array($rows)) {
            return null;
        }
        foreach ($rows as $row) {
            if ((int)($row['uid'] ?? 0) === $uid) {
                return $row;
            }
        }
        return null;
    }

    public static function hasBet($roundIndex, $uid)
    {
        self::migrateLegacy($roundIndex);
        $uid = (int)$uid;
        $redis = self::redis();
        if ($redis) {
            return (bool)$redis->hExists(self::betHashKey($roundIndex), (string)$uid);
        }
        return self::getBet($roundIndex, $uid) !== null;
    }

    /**
     * 结算用：整桌。万人局约 10k HGETALL，每 20s 一次可接受。
     */
    public static function loadBets($roundIndex)
    {
        self::migrateLegacy($roundIndex);
        $redis = self::redis();
        if ($redis) {
            $map = $redis->hGetAll(self::betHashKey($roundIndex));
            if (!is_array($map) || !$map) {
                return [];
            }
            $rows = [];
            foreach ($map as $raw) {
                $row = json_decode($raw, true);
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
            return $rows;
        }
        $rows = Cache::get(self::cacheName('bets:' . (int)$roundIndex));
        return is_array($rows) ? $rows : [];
    }

    public static function liveFeed($roundIndex, $limit = 16)
    {
        self::migrateLegacy($roundIndex);
        $limit = max(1, (int)$limit);
        $redis = self::redis();
        if ($redis) {
            $raws = $redis->lRange(self::liveKey($roundIndex), 0, $limit - 1);
            if (!is_array($raws)) {
                return [];
            }
            $out = [];
            foreach ($raws as $raw) {
                $row = json_decode($raw, true);
                if (is_array($row)) {
                    $out[] = $row;
                }
            }
            return $out;
        }
        $rows = self::loadBets($roundIndex);
        if (count($rows) > $limit) {
            $rows = array_slice($rows, -$limit);
        }
        return array_reverse($rows);
    }

    public static function stats($roundIndex)
    {
        self::migrateLegacy($roundIndex);
        $redis = self::redis();
        if ($redis) {
            $s = $redis->hGetAll(self::statsKey($roundIndex));
            return [
                'stake'   => (int)($s['stake'] ?? 0),
                'players' => (int)($s['players'] ?? 0),
                'bots'    => (int)($s['bots'] ?? 0),
            ];
        }
        $rows = self::loadBets($roundIndex);
        $stake = 0;
        foreach ($rows as $row) {
            $stake += (int)($row['stake'] ?? 0);
        }
        return ['stake' => $stake, 'players' => count($rows)];
    }

    public static function getSnap()
    {
        $redis = self::redis();
        if ($redis) {
            $raw = $redis->get(self::snapKey());
            if (!$raw) {
                return null;
            }
            $data = json_decode($raw, true);
            return is_array($data) ? $data : null;
        }
        $data = Cache::get(self::cacheName('snap'));
        return is_array($data) ? $data : null;
    }

    public static function setSnap(array $payload, $ttl = 1)
    {
        $ttl = max(1, (int)$ttl);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $redis = self::redis();
        if ($redis) {
            $redis->setex(self::snapKey(), $ttl, $json);
            return;
        }
        Cache::set(self::cacheName('snap'), $payload, $ttl);
    }

    public static function clearSnap()
    {
        $redis = self::redis();
        if ($redis) {
            try {
                $redis->del(self::snapKey());
            } catch (\Throwable $e) {
            }
            return;
        }
        Cache::rm(self::cacheName('snap'));
    }

    /**
     * 结算后把带 won/payout 的行写回 HASH，供个人结果读取。
     */
    public static function writeBets($roundIndex, array $rows, $ttl = 86400)
    {
        $ttl = max(60, (int)$ttl);
        $redis = self::redis();
        if (!$redis) {
            Cache::set(self::cacheName('bets:' . (int)$roundIndex), $rows, $ttl);
            self::clearSnap();
            return;
        }
        $hk = self::betHashKey($roundIndex);
        try {
            $redis->multi(\Redis::PIPELINE);
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $uid = (int)($row['uid'] ?? 0);
                $redis->hSet($hk, (string)$uid, json_encode($row, JSON_UNESCAPED_UNICODE));
            }
            $redis->expire($hk, $ttl);
            $redis->exec();
        } catch (\Throwable $e) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $uid = (int)($row['uid'] ?? 0);
                $redis->hSet($hk, (string)$uid, json_encode($row, JSON_UNESCAPED_UNICODE));
            }
            $redis->expire($hk, $ttl);
        }
        self::clearSnap();
    }

    public static function clearRoundBets($roundIndex)
    {
        $roundIndex = (int)$roundIndex;
        $redis = self::redis();
        if ($redis) {
            try {
                $redis->del(self::betHashKey($roundIndex));
            } catch (\Throwable $e) {
            }
            try {
                $redis->del(self::liveKey($roundIndex));
            } catch (\Throwable $e) {
            }
            try {
                $redis->del(self::statsKey($roundIndex));
            } catch (\Throwable $e) {
            }
        }
        try {
            Cache::rm(self::cacheName('bets:' . $roundIndex));
        } catch (\Throwable $e) {
        }
        self::clearSnap();
    }

    /**
     * 结算后惰性派彩：只写 Redis，不在当局循环里打钱包。
     *
     * @param array $items [ ['uid'=>, 'pay'=>array], ... ]
     */
    public static function fanoutWin(array $items)
    {
        $redis = self::redis();
        if (!$redis) {
            foreach ($items as $item) {
                $uid = (int)($item['uid'] ?? 0);
                if ($uid <= 0 || empty($item['pay'])) {
                    continue;
                }
                Cache::set(self::cacheName('winpay:' . $uid), $item['pay'], 3600);
            }
            return;
        }
        $qkey = self::rkey(self::cacheName('winq'));
        $chunks = array_chunk($items, 400);
        foreach ($chunks as $chunk) {
            try {
                $redis->multi(\Redis::PIPELINE);
                foreach ($chunk as $item) {
                    $uid = (int)($item['uid'] ?? 0);
                    if ($uid <= 0 || empty($item['pay'])) {
                        continue;
                    }
                    $redis->setex(
                        self::rkey(self::cacheName('winpay:' . $uid)),
                        3600,
                        json_encode($item['pay'], JSON_UNESCAPED_UNICODE)
                    );
                    $redis->rPush($qkey, (string)$uid);
                }
                $redis->exec();
            } catch (\Throwable $e) {
                foreach ($chunk as $item) {
                    $uid = (int)($item['uid'] ?? 0);
                    if ($uid <= 0 || empty($item['pay'])) {
                        continue;
                    }
                    Cache::set(self::cacheName('winpay:' . $uid), $item['pay'], 3600);
                }
            }
        }
        try {
            $redis->expire($qkey, 3600);
        } catch (\Throwable $e) {
        }
    }

    /**
     * cron 分批入账：每次弹出最多 $n 个中奖 uid。
     * @return int[]
     */
    public static function popWinQueue($n = 120)
    {
        $n = max(1, min(400, (int)$n));
        $redis = self::redis();
        if (!$redis) {
            return [];
        }
        $key = self::rkey(self::cacheName('winq'));
        $uids = [];
        try {
            $redis->multi(\Redis::PIPELINE);
            for ($i = 0; $i < $n; $i++) {
                $redis->lPop($key);
            }
            $raw = $redis->exec();
            if (is_array($raw)) {
                foreach ($raw as $v) {
                    if ($v === false || $v === null || $v === '') {
                        continue;
                    }
                    $uid = (int)$v;
                    if ($uid > 0) {
                        $uids[] = $uid;
                    }
                }
            }
        } catch (\Throwable $e) {
            return [];
        }
        return $uids;
    }

    /**
     * 红包雨扇出：每用户两条 SETEX，pipeline 分片。
     *
     * @param array $items [ ['uid'=>, 'pop'=>array, 'pay'=>array], ... ]
     */
    public static function fanoutRain(array $items, $popTtl = 90, $payTtl = 300)
    {
        $popTtl = max(20, min(600, (int)$popTtl));
        $payTtl = max($popTtl, min(3600, (int)$payTtl));
        $redis = self::redis();
        if (!$redis) {
            foreach ($items as $item) {
                $uid = (int)($item['uid'] ?? 0);
                if ($uid <= 0) {
                    continue;
                }
                if (!empty($item['pop'])) {
                    Cache::set('fh:yxx:rainpop:' . $uid, $item['pop'], $popTtl);
                }
                if (!empty($item['pay'])) {
                    Cache::set('fh:yxx:rainpay:' . $uid, $item['pay'], $payTtl);
                }
            }
            return;
        }
        $chunks = array_chunk($items, 400);
        foreach ($chunks as $chunk) {
            try {
                $redis->multi(\Redis::PIPELINE);
                foreach ($chunk as $item) {
                    $uid = (int)($item['uid'] ?? 0);
                    if ($uid <= 0) {
                        continue;
                    }
                    if (!empty($item['pop'])) {
                        $redis->setex(
                            self::rkey('fh:yxx:rainpop:' . $uid),
                            $popTtl,
                            json_encode($item['pop'], JSON_UNESCAPED_UNICODE)
                        );
                    }
                    if (!empty($item['pay'])) {
                        $redis->setex(
                            self::rkey('fh:yxx:rainpay:' . $uid),
                            $payTtl,
                            json_encode($item['pay'], JSON_UNESCAPED_UNICODE)
                        );
                    }
                }
                $redis->exec();
            } catch (\Throwable $e) {
                foreach ($chunk as $item) {
                    $uid = (int)($item['uid'] ?? 0);
                    if ($uid <= 0) {
                        continue;
                    }
                    if (!empty($item['pop'])) {
                        Cache::set('fh:yxx:rainpop:' . $uid, $item['pop'], $popTtl);
                    }
                    if (!empty($item['pay'])) {
                        Cache::set('fh:yxx:rainpay:' . $uid, $item['pay'], $payTtl);
                    }
                }
            }
        }
    }

    /**
     * 上线当局：旧版整桌 PHP 数组迁到 HASH，避免热切丢注。
     */
    protected static function migrateLegacy($roundIndex)
    {
        $roundIndex = (int)$roundIndex;
        static $done = [];
        if (isset($done[$roundIndex])) {
            return;
        }
        $redis = self::redis();
        if (!$redis) {
            $done[$roundIndex] = 1;
            return;
        }
        try {
            if ($redis->exists(self::betHashKey($roundIndex))) {
                $done[$roundIndex] = 1;
                return;
            }
        } catch (\Throwable $e) {
        }
        $legacy = Cache::get('fh:yxx:bets:' . $roundIndex);
        if (is_array($legacy) && $legacy) {
            $stake = 0;
            $players = 0;
            $bots = 0;
            foreach ($legacy as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $uid = (int)($row['uid'] ?? 0);
                $redis->hSet(self::betHashKey($roundIndex), (string)$uid, json_encode($row, JSON_UNESCAPED_UNICODE));
                $stake += (int)($row['stake'] ?? 0);
                $players++;
                if (!empty($row['bot']) || $uid <= 0) {
                    $bots++;
                }
                $redis->lPush(self::liveKey($roundIndex), json_encode($row, JSON_UNESCAPED_UNICODE));
            }
            $redis->lTrim(self::liveKey($roundIndex), 0, 15);
            $sk = self::statsKey($roundIndex);
            $redis->hSet($sk, 'stake', (string)$stake);
            $redis->hSet($sk, 'players', (string)$players);
            $redis->hSet($sk, 'bots', (string)$bots);
            $redis->expire(self::betHashKey($roundIndex), 180);
            $redis->expire(self::liveKey($roundIndex), 180);
            $redis->expire($sk, 180);
        }
        $done[$roundIndex] = 1;
    }

    public static function getJson($name)
    {
        $redis = self::redis();
        if ($redis) {
            $raw = $redis->get(self::rkey($name));
            if (!$raw) {
                return null;
            }
            if (is_array($raw)) {
                return $raw;
            }
            $data = json_decode($raw, true);
            return is_array($data) ? $data : null;
        }
        $data = Cache::get($name);
        return is_array($data) ? $data : null;
    }

    public static function delName($name)
    {
        $redis = self::redis();
        if ($redis) {
            try {
                $redis->del(self::rkey($name));
            } catch (\Throwable $e) {
            }
            return;
        }
        Cache::rm($name);
    }

    public static function setJson($name, array $data, $ttl)
    {
        $ttl = max(5, (int)$ttl);
        $redis = self::redis();
        $raw = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($redis) {
            try {
                $redis->setex(self::rkey($name), $ttl, $raw);
                return;
            } catch (\Throwable $e) {
            }
        }
        Cache::set($name, $data, $ttl);
    }

    public static function rainElKey($eventId)
    {
        return self::rkey('fh:yxx:rainel:' . (int)$eventId);
    }

    public static function rainGotKey($eventId)
    {
        return self::rkey('fh:yxx:raingot:' . (int)$eventId);
    }

    public static function rainLeftKey()
    {
        return self::rkey('fh:yxx:rlive:left');
    }

    public static function rainWtKey($eventId)
    {
        return self::rkey('fh:yxx:rainwt:' . (int)$eventId);
    }

    public static function addRainWeights($eventId, array $weights, $ttl)
    {
        $redis = self::redis();
        if (!$redis) {
            return;
        }
        $key = self::rainWtKey($eventId);
        $ttl = max(20, (int)$ttl);
        foreach (array_chunk($weights, 400, true) as $chunk) {
            try {
                $redis->multi(\Redis::PIPELINE);
                foreach ($chunk as $uid => $w) {
                    $uid = (int)$uid;
                    if ($uid > 0) {
                        $redis->hSet($key, (string)$uid, (string)max(1, (int)$w));
                    }
                }
                $redis->exec();
            } catch (\Throwable $e) {
            }
        }
        try {
            $redis->expire($key, $ttl);
        } catch (\Throwable $e) {
        }
    }

    public static function rainWeight($eventId, $uid)
    {
        $uid = (int)$uid;
        $redis = self::redis();
        if (!$redis || $uid <= 0) {
            return 0;
        }
        try {
            $v = $redis->hGet(self::rainWtKey($eventId), (string)$uid);
            return max(0, (int)$v);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function addRainEligible($eventId, array $uids, $ttl)
    {
        $redis = self::redis();
        if (!$redis) {
            return;
        }
        $key = self::rainElKey($eventId);
        $ttl = max(20, (int)$ttl);
        foreach (array_chunk($uids, 400) as $chunk) {
            try {
                $redis->multi(\Redis::PIPELINE);
                foreach ($chunk as $uid) {
                    $uid = (int)$uid;
                    if ($uid > 0) {
                        $redis->sAdd($key, (string)$uid);
                    }
                }
                $redis->exec();
            } catch (\Throwable $e) {
            }
        }
        try {
            $redis->expire($key, $ttl);
        } catch (\Throwable $e) {
        }
    }

    public static function rainIsEligible($eventId, $uid)
    {
        $uid = (int)$uid;
        $redis = self::redis();
        if (!$redis || $uid <= 0) {
            return false;
        }
        try {
            return (bool)$redis->sIsMember(self::rainElKey($eventId), (string)$uid);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function rainHasGot($eventId, $uid)
    {
        $uid = (int)$uid;
        $redis = self::redis();
        if (!$redis || $uid <= 0) {
            return false;
        }
        try {
            return (bool)$redis->sIsMember(self::rainGotKey($eventId), (string)$uid);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** @return bool true = 新抢到名额 */
    public static function rainMarkGot($eventId, $uid, $ttl)
    {
        $uid = (int)$uid;
        $redis = self::redis();
        if (!$redis || $uid <= 0) {
            return false;
        }
        try {
            $n = $redis->sAdd(self::rainGotKey($eventId), (string)$uid);
            $redis->expire(self::rainGotKey($eventId), max(20, (int)$ttl));
            return $n === 1 || $n === true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function rainUnmarkGot($eventId, $uid)
    {
        $redis = self::redis();
        if (!$redis) {
            return;
        }
        try {
            $redis->sRem(self::rainGotKey($eventId), (string)$uid);
        } catch (\Throwable $e) {
        }
    }

    public static function rainTake($want)
    {
        $want = max(0, (int)$want);
        if ($want <= 0) {
            return 0;
        }
        $redis = self::redis();
        if (!$redis) {
            return 0;
        }
        try {
            $left = (int)$redis->decrBy(self::rainLeftKey(), $want);
            if ($left >= 0) {
                return $want;
            }
            $actual = $want + $left;
            $redis->set(self::rainLeftKey(), 0);
            return max(0, $actual);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function rainGiveBack($n)
    {
        $n = (int)$n;
        if ($n <= 0) {
            return;
        }
        $redis = self::redis();
        if (!$redis) {
            return;
        }
        try {
            $redis->incrBy(self::rainLeftKey(), $n);
        } catch (\Throwable $e) {
        }
    }

    public static function rainRefundLeft()
    {
        $redis = self::redis();
        if (!$redis) {
            return 0;
        }
        try {
            $n = (int)$redis->get(self::rainLeftKey());
            $redis->set(self::rainLeftKey(), 0);
            return max(0, $n);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function clearRainLive($eventId)
    {
        $eventId = (int)$eventId;
        self::delName('fh:yxx:rlive');
        $redis = self::redis();
        if (!$redis) {
            return;
        }
        try {
            $redis->del(self::rainLeftKey(), self::rainElKey($eventId), self::rainGotKey($eventId), self::rainWtKey($eventId));
        } catch (\Throwable $e) {
        }
    }
}
