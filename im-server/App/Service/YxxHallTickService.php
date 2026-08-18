<?php

namespace Im\Service;

/**
 * 鱼虾蟹大厅机器人：HTTP 轻量踢一脚 PHP 大厅（错峰入注在 FansHubYxx::tickBots）。
 */
class YxxHallTickService
{
    public static function tick($url)
    {
        $url = trim((string)$url);
        if ($url === '') {
            return;
        }
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => 8,
                'ignore_errors' => true,
                'header'        => "X-Fanshub-Locale: zh-CN\r\n",
            ],
        ]);
        @file_get_contents($url, false, $ctx);
    }
}
