<?php

namespace app\common\library;

use think\Config;
use think\Exception;

/**
 * IM Admin Bridge HTTP 客户端
 */
class FansHubImBridge
{
    /**
     * @throws Exception
     */
    public static function post($path, array $body = [])
    {
        $cfg = Config::get('fanshub') ?: [];
        $im = isset($cfg['im_admin']) && is_array($cfg['im_admin']) ? $cfg['im_admin'] : [];
        $base = rtrim((string)($im['bridge_url'] ?? 'http://127.0.0.1:17273'), '/');
        $key = (string)($im['bridge_key'] ?? 'change-me-im-admin');
        $body['admin_key'] = $key;

        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            throw new Exception('IM 桥接不可达: ' . $err);
        }
        $json = json_decode((string)$raw, true);
        if ($code >= 400) {
            $msg = '操作失败';
            if (is_array($json) && isset($json['message'])) {
                $msg = is_string($json['message']) ? $json['message'] : json_encode($json['message'], JSON_UNESCAPED_UNICODE);
            }
            throw new Exception($msg);
        }
        return is_array($json) ? $json : ['raw' => (string)$raw];
    }
}
