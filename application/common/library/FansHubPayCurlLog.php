<?php

namespace app\common\library;

/**
 * 充值/提现 — 仅记录 PHP 服务端发起的 CURL（不含浏览器跳转/表单提交）
 * 日志：runtime/log/pay_curl/recharge_YYYYMMDD.log | withdraw_YYYYMMDD.log
 */
class FansHubPayCurlLog
{
    const SCENE_RECHARGE = 'recharge';
    const SCENE_WITHDRAW = 'withdraw';

    protected static $maskKeys = [
        'sign',
        'pay_md5sign',
        'key',
        'password',
        'private_key',
        'merchant_key',
        'rsa_private_key',
        'rsa_public_key',
    ];

    /**
     * 服务端 POST JSON
     *
     * @param array $logMeta scene,gateway,order_no,action（有 scene 才写日志）
     */
    public static function postJson($url, array $params, array $logMeta = [], array $options = [])
    {
        $body = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $started = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => (int)($options['connect_timeout'] ?? 12),
            CURLOPT_TIMEOUT        => (int)($options['timeout'] ?? 30),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json; charset=utf-8',
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        self::write($logMeta, 'POST', $url, $params, 'json', $raw, $err, $httpCode, $started);
        if ($raw === false) {
            $prefix = (string)($options['error_prefix'] ?? '支付网关请求失败');
            throw new \RuntimeException($prefix . '：' . $err);
        }
        return (string)$raw;
    }

    /**
     * 服务端 POST form-urlencoded
     */
    public static function postForm($url, array $params, array $logMeta = [], array $options = [])
    {
        $started = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => (int)($options['connect_timeout'] ?? 12),
            CURLOPT_TIMEOUT        => (int)($options['timeout'] ?? 30),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded; charset=utf-8',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        self::write($logMeta, 'POST', $url, $params, 'form', $raw, $err, $httpCode, $started);
        if ($raw === false) {
            $prefix = (string)($options['error_prefix'] ?? '支付网关请求失败');
            throw new \RuntimeException($prefix . '：' . $err);
        }
        return (string)$raw;
    }

    public static function logMeta($scene, $gateway, $orderNo, $action = '')
    {
        return [
            'scene'    => $scene,
            'gateway'  => $gateway,
            'order_no' => (string)$orderNo,
            'action'   => $action,
        ];
    }

    public static function recordServerRequest(array $logMeta, $method, $url, array $params, $contentType, $raw, $err, $httpCode, $started)
    {
        self::write($logMeta, $method, $url, $params, $contentType, $raw, $err, $httpCode, $started);
    }

    protected static function write(array $logMeta, $method, $url, array $params, $contentType, $raw, $err, $httpCode, $started)
    {
        if (empty($logMeta['scene'])) {
            return;
        }
        try {
            self::logRequest($logMeta['scene'], [
                'source'       => 'server',
                'gateway'      => (string)($logMeta['gateway'] ?? ''),
                'order_no'     => (string)($logMeta['order_no'] ?? ''),
                'action'       => (string)($logMeta['action'] ?? ''),
                'method'       => $method,
                'url'          => $url,
                'content_type' => $contentType,
                'params'       => $params,
                'http_code'    => $httpCode,
                'duration_ms'  => (int)round((microtime(true) - $started) * 1000),
                'error'        => $err !== '' ? $err : '',
                'response'     => $raw === false ? '' : (string)$raw,
            ]);
        } catch (\Throwable $e) {
        }
    }

    public static function logRequest($scene, array $meta)
    {
        $scene = self::normalizeScene($scene);
        if ($scene === '') {
            return;
        }

        $dir = self::logDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $lines = [];
        $lines[] = '========== ' . date('Y-m-d H:i:s') . ' ==========';
        $lines[] = 'scene: ' . $scene;
        $lines[] = 'source: server';
        foreach (['gateway', 'order_no', 'action', 'method', 'url', 'http_code', 'duration_ms', 'error'] as $key) {
            if (!empty($meta[$key]) || (isset($meta[$key]) && $meta[$key] === 0)) {
                $lines[] = $key . ': ' . $meta[$key];
            }
        }

        if (!empty($meta['params']) && is_array($meta['params'])) {
            $masked = self::maskParams($meta['params']);
            $lines[] = 'request_params: ' . self::encode($masked);
            $lines[] = 'curl: ' . self::buildCurlCommand(
                (string)($meta['method'] ?? 'POST'),
                (string)($meta['url'] ?? ''),
                $masked,
                (string)($meta['content_type'] ?? 'json')
            );
        }

        if (array_key_exists('response', $meta)) {
            $lines[] = 'response: ' . self::clip((string)$meta['response'], 8000);
        }

        $lines[] = '';
        @file_put_contents(self::logFile($scene), implode(PHP_EOL, $lines) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public static function maskParams(array $params)
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

    public static function buildCurlCommand($method, $url, array $params, $contentType = 'json')
    {
        $method = strtoupper(trim($method));
        if ($method === '') {
            $method = 'POST';
        }
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if ($contentType === 'form') {
            $body = http_build_query($params);
            $header = 'Content-Type: application/x-www-form-urlencoded';
        } else {
            $body = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $header = 'Content-Type: application/json';
        }

        return "curl -X {$method} " . escapeshellarg($url)
            . " -H " . escapeshellarg($header)
            . " -d " . escapeshellarg($body);
    }

    protected static function normalizeScene($scene)
    {
        $scene = strtolower(trim((string)$scene));
        if ($scene === self::SCENE_RECHARGE || $scene === self::SCENE_WITHDRAW) {
            return $scene;
        }
        return '';
    }

    protected static function logDir()
    {
        $base = defined('RUNTIME_PATH') ? RUNTIME_PATH : (dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR);
        return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'pay_curl';
    }

    protected static function logFile($scene)
    {
        return self::logDir() . DIRECTORY_SEPARATOR . $scene . '_' . date('Ymd') . '.log';
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
