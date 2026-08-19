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
                'timeout'       => 18,
                'ignore_errors' => true,
                'header'        => "X-Fanshub-Locale: zh-CN\r\n",
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        $line = '';
        if (!empty($http_response_header[0])) {
            $line = (string)$http_response_header[0];
        }
        if ($line !== '' && strpos($line, '200') === false) {
            error_log('[CRON][YXX] tick HTTP ' . $line . ' url=' . $url);
        }
        unset($raw);
    }
}
