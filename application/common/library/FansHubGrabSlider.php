<?php

namespace app\common\library;

/**
 * 抢包滑块挑战（写入 IM Redis，与 GrabGuard 共用）
 */
class FansHubGrabSlider
{
    public static function redisCfg()
    {
        $root = dirname(__DIR__, 3); // application/common/library -> project root
        $file = $root . '/im-server/config/local.php';
        if (is_file($file)) {
            $local = include $file;
            if (is_array($local) && !empty($local['redis']) && is_array($local['redis'])) {
                return $local['redis'];
            }
        }
        $file2 = $root . '/im-server/config/app.php';
        if (is_file($file2)) {
            $app = include $file2;
            if (is_array($app) && !empty($app['redis']) && is_array($app['redis'])) {
                return $app['redis'];
            }
        }
        return [
            'host'     => '127.0.0.1',
            'port'     => 6379,
            'password' => '',
            'db'       => 2,
            'prefix'   => 'im:',
        ];
    }

    public static function conn()
    {
        if (!class_exists('\\Redis')) {
            throw new \RuntimeException('ext-redis required');
        }
        $c = self::redisCfg();
        $r = new \Redis();
        if (!$r->connect($c['host'], (int)$c['port'], 2.0)) {
            throw new \RuntimeException('redis connect failed');
        }
        if (!empty($c['password'])) {
            $r->auth($c['password']);
        }
        $r->select((int)($c['db'] ?? 0));
        return [$r, (string)($c['prefix'] ?? 'im:')];
    }

    public static function create($width = 280)
    {
        $width = max(200, (int)$width);
        $token = bin2hex(random_bytes(16));
        list($r, $prefix) = self::conn();
        $r->setex($prefix . 'grab_slider:' . $token, 300, json_encode([
            't' => time(),
            'w' => $width,
        ], JSON_UNESCAPED_UNICODE));
        return [
            'enabled' => true,
            'mode'    => 'slide',
            'token'   => $token,
            'width'   => $width,
            'pass_ratio' => 0.82,
            'hint'    => FansHubService::h5CopyText('slider_modal_hint') ?: '请按住滑块，拖动到最右侧',
        ];
    }
}
