<?php

namespace app\common\library;

use think\Db;

/**
 * 账户业务限制（HTTP 侧）：禁止返佣 / 禁止领红包雨
 */
class FansHubAccountRestrict
{
    /**
     * @return array{deny_rebate:bool,deny_rp_rain:bool}
     */
    public static function getFlags($userId)
    {
        $userId = (int)$userId;
        $empty = ['deny_rebate' => false, 'deny_rp_rain' => false];
        if ($userId <= 0) {
            return $empty;
        }
        try {
            $row = Db::name('fans_account')
                ->where('user_id', $userId)
                ->field('deny_rebate,deny_rp_rain')
                ->find();
            if (!$row) {
                return $empty;
            }
            return [
                'deny_rebate'  => !empty($row['deny_rebate']),
                'deny_rp_rain' => !empty($row['deny_rp_rain']),
            ];
        } catch (\Throwable $e) {
            return $empty;
        }
    }

    public static function isDenyRebate($userId)
    {
        return !empty(self::getFlags($userId)['deny_rebate']);
    }

    public static function isDenyRpRain($userId)
    {
        return !empty(self::getFlags($userId)['deny_rp_rain']);
    }

    /**
     * @throws \RuntimeException
     */
    public static function assertCanClaimRpRain($userId)
    {
        if (self::isDenyRpRain($userId)) {
            throw new \RuntimeException('账号已被禁止领取红包雨');
        }
    }

    /**
     * 同步 IM Redis 缓存（后台切换后立即生效）
     */
    public static function syncRedis($userId, $denyRebate, $denyRpRain)
    {
        $userId = (int)$userId;
        if ($userId <= 0 || !class_exists('\Redis')) {
            return;
        }
        $redisCfg = [
            'host'     => '127.0.0.1',
            'port'     => 6379,
            'password' => '',
            'db'       => 2,
            'prefix'   => 'im:',
        ];
        $imApp = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'im-server' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
        if (!is_file($imApp)) {
            $imApp = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'im-server' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
        }
        if (is_file($imApp)) {
            $app = include $imApp;
            if (is_array($app) && !empty($app['redis']) && is_array($app['redis'])) {
                $redisCfg = array_merge($redisCfg, $app['redis']);
            }
        }
        try {
            $redis = new \Redis();
            $redis->connect($redisCfg['host'], (int)$redisCfg['port'], 1.5);
            if (!empty($redisCfg['password'])) {
                $redis->auth($redisCfg['password']);
            }
            if (isset($redisCfg['db'])) {
                $redis->select((int)$redisCfg['db']);
            }
            $key = ((string)($redisCfg['prefix'] ?? 'im:')) . 'acct_restrict:' . $userId;
            $denyRebate = !empty($denyRebate);
            $denyRpRain = !empty($denyRpRain);
            if ($denyRebate || $denyRpRain) {
                $redis->setex($key, 86400 * 7, json_encode([
                    'deny_rebate'  => $denyRebate ? 1 : 0,
                    'deny_rp_rain' => $denyRpRain ? 1 : 0,
                ], JSON_UNESCAPED_UNICODE));
            } else {
                $redis->del($key);
            }
        } catch (\Throwable $e) {
        }
    }
}
