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
        return self::rkey('fh:yxx:bh:' . (int)$roundIndex);
    }

    public static function liveKey($roundIndex)
    {
        return self::rkey('fh:yxx:lv:' . (int)$roundIndex);
    }

    public static function statsKey($roundIndex)
    {
        return self::rkey('fh:yxx:st:' . (int)$roundIndex);
    }

    public static function snapKey()
    {
        return self::rkey('fh:yxx:snap');
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

        $rows = Cache::get('fh:yxx:bets:' . (int)$roundIndex);
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
        Cache::set('fh:yxx:bets:' . (int)$roundIndex, $rows, 120);
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
        $rows = Cache::get('fh:yxx:bets:' . (int)$roundIndex);
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
        $rows = Cache::get('fh:yxx:bets:' . (int)$roundIndex);
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
        $data = Cache::get('fh:yxx:snap');
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
        Cache::set('fh:yxx:snap', $payload, $ttl);
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
        Cache::rm('fh:yxx:snap');
    }

    /**
     * 结算后把带 won/payout 的行写回 HASH，供个人结果读取。
     */
    public static function writeBets($roundIndex, array $rows, $ttl = 86400)
    {
        $ttl = max(60, (int)$ttl);
        $redis = self::redis();
        if (!$redis) {
            Cache::set('fh:yxx:bets:' . (int)$roundIndex, $rows, $ttl);
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

    /**
     * 红包雨扇出：每用户两条 SETEX，pipeline 分片。
     *
     * @param array $items [ ['uid'=>, 'pop'=>array, 'pay'=>array], ... ]
     */
    public static function fanoutRain(array $items)
    {
        $redis = self::redis();
        if (!$redis) {
            foreach ($items as $item) {
                $uid = (int)($item['uid'] ?? 0);
                if ($uid <= 0) {
                    continue;
                }
                if (!empty($item['pop'])) {
                    Cache::set('fh:yxx:rainpop:' . $uid, $item['pop'], 180);
                }
                if (!empty($item['pay'])) {
                    Cache::set('fh:yxx:rainpay:' . $uid, $item['pay'], 3600);
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
                            180,
                            json_encode($item['pop'], JSON_UNESCAPED_UNICODE)
                        );
                    }
                    if (!empty($item['pay'])) {
                        $redis->setex(
                            self::rkey('fh:yxx:rainpay:' . $uid),
                            3600,
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
                        Cache::set('fh:yxx:rainpop:' . $uid, $item['pop'], 180);
                    }
                    if (!empty($item['pay'])) {
                        Cache::set('fh:yxx:rainpay:' . $uid, $item['pay'], 3600);
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
}
