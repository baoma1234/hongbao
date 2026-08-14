<?php

namespace Im\Support;

use Redis;
use RuntimeException;

class RedisClient
{
    /** @var Redis|null */
    protected static $redis;
    /** @var array */
    protected static $cfg = [];

    public static function init(array $cfg)
    {
        self::$cfg = $cfg;
        self::$redis = null;
    }

    public static function conn()
    {
        if (self::$redis instanceof Redis) {
            return self::$redis;
        }
        if (!class_exists('Redis')) {
            throw new RuntimeException('ext-redis required');
        }
        $c = self::$cfg;
        $r = new Redis();
        if (!$r->connect($c['host'], (int)$c['port'], 2.0)) {
            throw new RuntimeException('redis connect failed');
        }
        if (!empty($c['password'])) {
            $r->auth($c['password']);
        }
        $r->select((int)($c['db'] ?? 0));
        // 短超时，避免阻塞事件循环
        @$r->setOption(Redis::OPT_READ_TIMEOUT, 2);
        self::$redis = $r;
        return self::$redis;
    }

    /** 连接异常后重置，下次 conn() 重建 */
    public static function reset()
    {
        try {
            if (self::$redis instanceof Redis) {
                self::$redis->close();
            }
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Support.RedisClient');
        }
        self::$redis = null;
    }

    public static function key($suffix)
    {
        return (self::$cfg['prefix'] ?? 'im:') . $suffix;
    }

    public static function evalFile($relativeLua, array $keys = [], array $args = [])
    {
        $path = dirname(__DIR__, 2) . '/App/Support/Lua/' . ltrim($relativeLua, '/');
        if (!is_file($path)) {
            throw new RuntimeException('lua missing: ' . $relativeLua);
        }
        $script = file_get_contents($path);
        if ($script === false || $script === '') {
            throw new RuntimeException('lua empty: ' . $relativeLua);
        }
        $r = self::conn();
        // 缓存 SHA，避免每次读盘 + EVAL 全文
        static $shaMap = [];
        $cacheKey = $path . ':' . md5($script);
        if (!isset($shaMap[$cacheKey])) {
            $shaMap[$cacheKey] = $r->script('load', $script);
        }
        try {
            return $r->evalSha($shaMap[$cacheKey], array_merge($keys, $args), count($keys));
        } catch (\RedisException $e) {
            // NOSCRIPT：脚本被 flush 后重载
            if (stripos($e->getMessage(), 'NOSCRIPT') !== false) {
                $shaMap[$cacheKey] = $r->script('load', $script);
                return $r->evalSha($shaMap[$cacheKey], array_merge($keys, $args), count($keys));
            }
            throw $e;
        }
    }
}
