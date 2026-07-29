<?php

namespace app\common\library;

/**
 * SugarCRM 接口请求日志
 * 路径：runtime/log/sugarcrm/sugarcrm_YYYYMMDD.log
 */
class SugarCrmLog
{
    protected static $maskKeys = ['sign', 'skey', 'sKey'];

    public static function record($method, $url, array $headers, array $params, $raw, $err, $httpCode, $started, array $extra = [])
    {
        try {
            $dir = self::logDir();
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            $masked = self::maskParams($params);
            $lines = [];
            $lines[] = '========== ' . date('Y-m-d H:i:s') . ' ==========';
            $lines[] = 'source: server';
            $lines[] = 'action: ' . (string)($extra['action'] ?? 'plist');
            if (!empty($extra['playername'])) {
                $lines[] = 'playername: ' . (string)$extra['playername'];
            }
            if (!empty($extra['user_id'])) {
                $lines[] = 'user_id: ' . (int)$extra['user_id'];
            }
            if (!empty($extra['trigger'])) {
                $lines[] = 'trigger: ' . (string)$extra['trigger'];
            }
            if (!empty($extra['x_env'])) {
                $lines[] = 'x_env: ' . (string)$extra['x_env'];
            }
            if (!empty($extra['sign_rule'])) {
                $lines[] = 'sign_rule: ' . (string)$extra['sign_rule'];
            }
            if (isset($extra['sign_base']) && $extra['sign_base'] !== '') {
                $lines[] = 'sign_base: ' . (string)$extra['sign_base'];
            }
            if (!empty($extra['sign_string_mask'])) {
                $lines[] = 'sign_string: ' . (string)$extra['sign_string_mask'];
            }
            if (isset($extra['sign_ok_sample'])) {
                $lines[] = 'sign_ok_sample: ' . (string)$extra['sign_ok_sample'];
            }
            $lines[] = 'method: ' . strtoupper(trim((string)$method));
            $lines[] = 'url: ' . trim((string)$url);
            if ($headers) {
                $lines[] = 'headers: ' . self::encode($headers);
            }
            $lines[] = 'http_code: ' . (int)$httpCode;
            $lines[] = 'duration_ms: ' . (int)round((microtime(true) - $started) * 1000);
            if ($err !== '') {
                $lines[] = 'error: ' . $err;
            }
            $lines[] = 'request_params: ' . self::encode($masked);
            $lines[] = 'curl: ' . self::buildCurlCommand($method, $url, $headers, $masked);
            $lines[] = 'response: ' . self::clip($raw === false ? '' : (string)$raw, 8000);
            $lines[] = '';

            @file_put_contents(self::logFile(), implode(PHP_EOL, $lines) . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
        }
    }

    public static function buildCurlCommand($method, $url, array $headers, array $params)
    {
        $method = strtoupper(trim((string)$method));
        if ($method === '') {
            $method = 'POST';
        }
        $url = trim((string)$url);
        if ($url === '') {
            return '';
        }
        $parts = ['curl -X ' . $method, escapeshellarg($url)];
        foreach ($headers as $header) {
            $parts[] = '-H ' . escapeshellarg((string)$header);
        }
        $parts[] = '-d ' . escapeshellarg(http_build_query($params, '', '&', PHP_QUERY_RFC3986));
        return implode(' ', $parts);
    }

    protected static function maskParams(array $params)
    {
        $out = [];
        foreach ($params as $key => $value) {
            $k = strtolower((string)$key);
            if (in_array($k, self::$maskKeys, true)) {
                $out[$key] = self::maskValue($value);
                continue;
            }
            if (is_array($value)) {
                $out[$key] = self::maskParams($value);
                continue;
            }
            $out[$key] = $value;
        }
        return $out;
    }

    protected static function maskValue($value)
    {
        $value = (string)$value;
        if ($value === '') {
            return '';
        }
        $len = strlen($value);
        if ($len <= 8) {
            return '***';
        }
        return substr($value, 0, 4) . '***' . substr($value, -4);
    }

    protected static function logDir()
    {
        $base = defined('RUNTIME_PATH') ? RUNTIME_PATH : (dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR);
        return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'sugarcrm';
    }

    protected static function logFile()
    {
        return self::logDir() . DIRECTORY_SEPARATOR . 'sugarcrm_' . date('Ymd') . '.log';
    }

    protected static function encode(array $data)
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    protected static function clip($text, $max)
    {
        $text = trim((string)$text);
        if ($text === '') {
            return '';
        }
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        return mb_substr($text, 0, $max) . '...(truncated)';
    }
}
