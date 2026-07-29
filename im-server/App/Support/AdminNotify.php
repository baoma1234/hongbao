<?php

namespace Im\Support;

/**
 * 仅通知后台代聊账号（不向普通用户发系统通知）
 */
class AdminNotify
{
    public static function publish($type, array $data)
    {
        try {
            $r = RedisClient::conn();
            $r->lPush(RedisClient::key('notify_queue'), json_encode([
                'type'    => (string)$type,
                'message' => $data['message'] ?? null,
                'data'    => $data,
                'admin_only' => 1,
                'ts'      => time(),
            ], JSON_UNESCAPED_UNICODE));
            $r->lTrim(RedisClient::key('notify_queue'), 0, 999);
        } catch (\Throwable $e) {
        }
    }
}
