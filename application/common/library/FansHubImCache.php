<?php

namespace app\common\library;

/**
 * Bust IM WalletService Redis balance cache after HTTP-side hongbao writes.
 * IM uses redis db + prefix from .env [redis] (default db=2, prefix=im:).
 */
class FansHubImCache
{
    /** @var \Redis|null */
    protected static $redis;

    public static function bustWallet($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return;
        }
        try {
            $r = self::conn();
            if (!$r) {
                return;
            }
            $prefix = self::prefix();
            $r->del($prefix . 'wallet:bal:' . $userId);
            $r->del($prefix . 'wallet:frozen:' . $userId);
        } catch (\Throwable $e) {
            try {
                \think\Log::write('FansHubImCache bust fail uid=' . $userId . ' ' . $e->getMessage(), 'warning');
            } catch (\Throwable $ignore) {
            }
        }
    }

    /** 首充标记写入后刷新 IM 缓存（设为已充值） */
    public static function markHasRecharged($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return;
        }
        try {
            $r = self::conn();
            if (!$r) {
                return;
            }
            $r->setex(self::prefix() . 'has_recharged:' . $userId, 86400 * 7, '1');
        } catch (\Throwable $e) {
        }
    }

    /** 绑定/改绑邀请人后清 IM 侧 inviter 缓存 */
    public static function bustInviter($inviteeUserId)
    {
        $inviteeUserId = (int)$inviteeUserId;
        if ($inviteeUserId <= 0) {
            return;
        }
        try {
            $r = self::conn();
            if (!$r) {
                return;
            }
            $r->del(self::prefix() . 'inviter:' . $inviteeUserId);
        } catch (\Throwable $e) {
        }
    }

    protected static function prefix()
    {
        $cfg = self::redisCfg();
        return (string)($cfg['prefix'] ?? 'im:');
    }

    protected static function redisCfg()
    {
        static $cfg;
        if (is_array($cfg)) {
            return $cfg;
        }
        $cfg = [
            'host'     => '127.0.0.1',
            'port'     => 6379,
            'password' => '',
            'db'       => 2,
            'prefix'   => 'im:',
        ];
        $env = dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . '.env';
        if (is_file($env)) {
            $ini = @parse_ini_file($env, true);
            if (is_array($ini) && !empty($ini['redis'])) {
                $r = $ini['redis'];
                $cfg['host'] = $r['host'] ?? $cfg['host'];
                $cfg['port'] = (int)($r['port'] ?? $cfg['port']);
                $cfg['password'] = $r['password'] ?? $cfg['password'];
                $cfg['db'] = (int)($r['select'] ?? $r['db'] ?? $cfg['db']);
                $cfg['prefix'] = $r['prefix'] ?? $cfg['prefix'];
            }
        }
        return $cfg;
    }

    /** @return \Redis|null */
    protected static function conn()
    {
        if (self::$redis instanceof \Redis) {
            return self::$redis;
        }
        if (!class_exists('Redis')) {
            return null;
        }
        $c = self::redisCfg();
        $r = new \Redis();
        if (!$r->connect($c['host'], (int)$c['port'], 1.5)) {
            return null;
        }
        if ($c['password'] !== '' && $c['password'] !== null) {
            $r->auth($c['password']);
        }
        $r->select((int)$c['db']);
        self::$redis = $r;
        return self::$redis;
    }
}
