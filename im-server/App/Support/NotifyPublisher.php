<?php

namespace Im\Support;

/**
 * 统一写入 notify_queue；Redis 失败时走本机 WS 内部推送
 */
class NotifyPublisher
{
    public static function publish($type, array $message, $adminOnly = false, array $cfg = null)
    {
        if ($cfg === null) {
            $cfg = require dirname(__DIR__, 2) . '/config/app.php';
        }
        RedisClient::init($cfg['redis']);
        if (self::publishRedis($type, $message, $adminOnly)) {
            return true;
        }
        return LocalWsPush::notify($type, $message, $adminOnly, $cfg);
    }

    protected static function publishRedis($type, array $message, $adminOnly = false)
    {
        try {
            $r = RedisClient::conn();
            $payload = [
                'type'        => (string)$type,
                'message'     => $message,
                'admin_only'  => $adminOnly ? 1 : 0,
                'ts'          => time(),
            ];
            $key = RedisClient::key('notify_queue');
            $r->lPush($key, json_encode($payload, JSON_UNESCAPED_UNICODE));
            $r->lTrim($key, 0, 999);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
