<?php

namespace app\common\library;

use think\Config;

/**
 * 波场（TRON）区块查询客户端（TronGrid）
 * - getnowblock：Redis 短缓存
 * - getblockbynum：历史块长缓存 + 同高度单飞锁
 * 注意：不在此做 sleep；延迟由队列 / Crontab 负责。
 */
class TronBlockClient
{
    const API_BASE_DEFAULT = 'https://api.trongrid.io';

    /** @var array{num:int,at:float}|null */
    protected static $memNow = null;

    /**
     * 最新区块高度
     * @return int
     */
    public static function getNowBlockNum($timeout = 3)
    {
        $ttl = self::nowCacheTtl();
        $now = microtime(true);
        if (self::$memNow !== null && ($now - (float)self::$memNow['at']) < $ttl) {
            return (int)self::$memNow['num'];
        }

        try {
            list($r, $prefix) = self::redis();
            $cached = $r->get($prefix . 'tron:now_num');
            if ($cached !== false && $cached !== null && (int)$cached > 0) {
                $num = (int)$cached;
                self::$memNow = ['num' => $num, 'at' => $now];
                return $num;
            }
        } catch (\Throwable $e) {
        }

        $json = self::post('/wallet/getnowblock', new \stdClass(), $timeout);
        $num = $json['block_header']['raw_data']['number'] ?? null;
        if ($num === null || (int)$num <= 0) {
            throw new \RuntimeException('tron getnowblock: missing block number');
        }
        $num = (int)$num;
        self::$memNow = ['num' => $num, 'at' => $now];
        try {
            list($r, $prefix) = self::redis();
            $r->setex($prefix . 'tron:now_num', max(1, (int)ceil($ttl)), (string)$num);
        } catch (\Throwable $e) {
        }
        return $num;
    }

    /**
     * 按高度取区块
     * @return array{block_id:string,block_num:int,confirmed:bool,raw?:array}
     */
    public static function getBlockByNum($blockNum, $timeout = 5)
    {
        $blockNum = (int)$blockNum;
        if ($blockNum <= 0) {
            throw new \InvalidArgumentException('invalid block num');
        }

        $cached = self::cacheGetBlock($blockNum);
        if ($cached !== null) {
            return $cached;
        }

        $gotLock = false;
        $lockKey = null;
        try {
            list($r, $prefix) = self::redis();
            $lockKey = $prefix . 'tron:lock:block:' . $blockNum;
            $gotLock = (bool)$r->set($lockKey, (string)time(), ['nx', 'ex' => 8]);
        } catch (\Throwable $e) {
            $gotLock = true;
        }

        if (!$gotLock) {
            $cached = self::cacheGetBlock($blockNum);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $json = self::post('/wallet/getblockbynum', ['num' => $blockNum], $timeout);
            $blockId = strtolower(trim((string)($json['blockID'] ?? $json['block_id'] ?? '')));
            if ($blockId === '') {
                throw new \RuntimeException('tron getblockbynum: empty blockID for #' . $blockNum);
            }
            $confirmed = true;
            if (array_key_exists('confirmed', $json)) {
                $confirmed = (bool)$json['confirmed'];
            }
            if (!$confirmed) {
                throw new \RuntimeException('tron block #' . $blockNum . ' not confirmed yet');
            }
            $num = (int)($json['block_header']['raw_data']['number'] ?? $blockNum);
            $out = [
                'block_id'  => $blockId,
                'block_num' => $num,
                'confirmed' => $confirmed,
                'raw'       => $json,
            ];
            self::cachePutBlock($out);
            return $out;
        } finally {
            if ($gotLock && $lockKey) {
                try {
                    list($r, ) = self::redis();
                    $r->del($lockKey);
                } catch (\Throwable $e) {
                }
            }
        }
    }

    /**
     * @param int[] $blockNums
     * @return array<int,array>
     */
    public static function prefetchBlocks(array $blockNums, $timeout = 6)
    {
        $uniq = [];
        foreach ($blockNums as $n) {
            $n = (int)$n;
            if ($n > 0) {
                $uniq[$n] = true;
            }
        }
        $out = [];
        foreach (array_keys($uniq) as $n) {
            try {
                $out[$n] = self::getBlockByNum($n, $timeout);
            } catch (\Throwable $e) {
            }
        }
        return $out;
    }

    public static function luckyFromBlockId($blockId)
    {
        $blockId = trim((string)$blockId);
        if ($blockId === '') {
            return '';
        }
        return strtoupper(substr($blockId, -1));
    }

    public static function luckyDigitFromBlockId($blockId)
    {
        $c = strtolower(substr(trim((string)$blockId), -1));
        if ($c !== '' && $c >= '0' && $c <= '9') {
            return (int)$c;
        }
        if ($c >= 'a' && $c <= 'f') {
            return hexdec($c) % 10;
        }
        return 0;
    }

    protected static function cacheGetBlock($blockNum)
    {
        try {
            list($r, $prefix) = self::redis();
            $raw = $r->get($prefix . 'tron:block:' . (int)$blockNum);
            if ($raw) {
                $j = json_decode($raw, true);
                if (is_array($j) && !empty($j['block_id']) && !empty($j['block_num'])) {
                    return [
                        'block_id'  => (string)$j['block_id'],
                        'block_num' => (int)$j['block_num'],
                        'confirmed' => !empty($j['confirmed']),
                    ];
                }
            }
        } catch (\Throwable $e) {
        }
        return null;
    }

    protected static function cachePutBlock(array $block)
    {
        $num = (int)($block['block_num'] ?? 0);
        if ($num <= 0 || empty($block['block_id'])) {
            return;
        }
        try {
            list($r, $prefix) = self::redis();
            $r->setex($prefix . 'tron:block:' . $num, self::blockCacheTtl(), json_encode([
                'block_id'  => (string)$block['block_id'],
                'block_num' => $num,
                'confirmed' => !empty($block['confirmed']),
            ], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
        }
    }

    protected static function nowCacheTtl()
    {
        $fanshub = Config::get('fanshub') ?: [];
        $n = (float)($fanshub['tron_now_cache_ttl'] ?? 2);
        return max(1.0, min(10.0, $n));
    }

    protected static function blockCacheTtl()
    {
        $fanshub = Config::get('fanshub') ?: [];
        $n = (int)($fanshub['tron_block_cache_ttl'] ?? 86400);
        return max(300, min(604800, $n));
    }

    /**
     * @return array{\Redis,string}
     */
    protected static function redis()
    {
        // 与 IM / GrabSlider 共用同一 Redis，便于跨进程命中缓存
        return FansHubGrabSlider::conn();
    }

    protected static function apiBase()
    {
        $fanshub = Config::get('fanshub') ?: [];
        $base = trim((string)($fanshub['trongrid_api_url'] ?? ''));
        return $base !== '' ? rtrim($base, '/') : self::API_BASE_DEFAULT;
    }

    protected static function apiKey()
    {
        $fanshub = Config::get('fanshub') ?: [];
        $key = trim((string)($fanshub['trongrid_api_key'] ?? ''));
        if ($key !== '') {
            return $key;
        }
        // 回落到 IM 配置
        try {
            $app = include dirname(__DIR__, 2) . '/im-server/config/app.php';
            return trim((string)($app['tron']['api_key'] ?? ''));
        } catch (\Throwable $e) {
            return '';
        }
    }

    protected static function post($path, $body, $timeout = 5)
    {
        $url = self::apiBase() . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl init failed');
        }
        $payload = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        $apiKey = self::apiKey();
        if ($apiKey !== '') {
            $headers[] = 'TRON-PRO-API-KEY: ' . $apiKey;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_CONNECTTIMEOUT => min(3, (int)$timeout),
            CURLOPT_TIMEOUT        => (int)$timeout,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $raw === '') {
            throw new \RuntimeException('tron http fail: ' . ($err ?: 'empty body'));
        }
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException('tron http ' . $code . ': ' . substr($raw, 0, 180));
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new \RuntimeException('tron invalid json');
        }
        return $json;
    }
}
