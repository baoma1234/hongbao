<?php

namespace app\common\library;

use think\Db;

/**
 * 充值/提现第三方请求/回调详情日志
 * - runtime/log/pay_curl/recharge_YYYYMMDD.log | withdraw_YYYYMMDD.log
 * - runtime/log/pay_curl/order/{订单号}.log （按单号检索）
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

    public static function logMeta($scene, $gateway, $orderNo, $action = '', array $extra = [])
    {
        return array_merge([
            'scene'    => $scene,
            'gateway'  => $gateway,
            'order_no' => (string)$orderNo,
            'action'   => $action,
        ], $extra);
    }

    public static function pickOrderNo(array $params)
    {
        foreach (['order_no', 'merchant_order_no', 'merchantOrderNo', 'pay_orderid', 'out_trade_no', 'mchOrderNo'] as $key) {
            $v = trim((string)($params[$key] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }
        return '';
    }

    public static function recordServerRequest(array $logMeta, $method, $url, array $params, $contentType, $raw, $err, $httpCode, $started)
    {
        self::write($logMeta, $method, $url, $params, $contentType, $raw, $err, $httpCode, $started);
    }

    /**
     * 第三方回调 / 反查（入站）
     */
    public static function logInbound($scene, array $meta)
    {
        $scene = self::normalizeScene($scene);
        if ($scene === '') {
            return;
        }
        try {
            $meta['scene'] = $scene;
            $meta['source'] = 'inbound';
            if (empty($meta['action'])) {
                $meta['action'] = 'notify';
            }
            if (empty($meta['order_no']) && !empty($meta['params']) && is_array($meta['params'])) {
                $meta['order_no'] = self::pickOrderNo($meta['params']);
            }
            self::logRequest($scene, self::enrichFromOrder($meta));
        } catch (\Throwable $e) {
        }
    }

    protected static function write(array $logMeta, $method, $url, array $params, $contentType, $raw, $err, $httpCode, $started)
    {
        if (empty($logMeta['scene'])) {
            return;
        }
        try {
            $meta = array_merge($logMeta, [
                'source'       => (string)($logMeta['source'] ?? 'server'),
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
            self::logRequest($meta['scene'], self::enrichFromOrder($meta));
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

        $source = (string)($meta['source'] ?? 'server');
        $lines = [];
        $lines[] = '========== ' . date('Y-m-d H:i:s') . ' ==========';
        $lines[] = 'scene: ' . $scene;
        $lines[] = 'source: ' . ($source !== '' ? $source : 'server');
        foreach ([
            'gateway', 'action', 'order_no', 'order_id', 'user_id', 'channel_id', 'amount',
            'order_status', 'ip', 'method', 'url', 'http_code', 'duration_ms', 'result', 'error',
        ] as $key) {
            if (!empty($meta[$key]) || (isset($meta[$key]) && $meta[$key] === 0) || (isset($meta[$key]) && $meta[$key] === '0')) {
                $lines[] = $key . ': ' . $meta[$key];
            }
        }

        if (!empty($meta['params']) && is_array($meta['params'])) {
            $masked = self::maskParams($meta['params']);
            $lines[] = 'request_params: ' . self::encode($masked);
            if ($source !== 'inbound') {
                $cmd = self::buildCurlCommand(
                    (string)($meta['method'] ?? 'POST'),
                    (string)($meta['url'] ?? ''),
                    $masked,
                    (string)($meta['content_type'] ?? 'json')
                );
                if ($cmd !== '') {
                    $lines[] = 'curl: ' . $cmd;
                }
            }
        }

        if (array_key_exists('raw_body', $meta) && (string)$meta['raw_body'] !== '') {
            $lines[] = 'raw_body: ' . self::clip((string)$meta['raw_body'], 16000);
        }
        if (array_key_exists('response', $meta)) {
            $lines[] = 'response: ' . self::clip((string)$meta['response'], 16000);
        }
        if (!empty($meta['note'])) {
            $lines[] = 'note: ' . self::clip((string)$meta['note'], 2000);
        }

        $lines[] = '';
        $chunk = implode(PHP_EOL, $lines) . PHP_EOL;
        @file_put_contents(self::logFile($scene), $chunk, FILE_APPEND | LOCK_EX);
        $orderFile = self::orderLogFile((string)($meta['order_no'] ?? ''));
        if ($orderFile !== '') {
            $orderDir = dirname($orderFile);
            if (!is_dir($orderDir)) {
                @mkdir($orderDir, 0755, true);
            }
            @file_put_contents($orderFile, $chunk, FILE_APPEND | LOCK_EX);
        }
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

    protected static function orderLogFile($orderNo)
    {
        $orderNo = trim((string)$orderNo);
        if ($orderNo === '' || strtolower($orderNo) === 'balance') {
            return '';
        }
        if (!preg_match('/^[A-Za-z0-9_\-]{6,80}$/', $orderNo)) {
            return '';
        }
        return self::logDir() . DIRECTORY_SEPARATOR . 'order' . DIRECTORY_SEPARATOR . $orderNo . '.log';
    }

    protected static function enrichFromOrder(array $meta)
    {
        $orderNo = trim((string)($meta['order_no'] ?? ''));
        $scene = self::normalizeScene($meta['scene'] ?? '');
        if ($orderNo === '' || $scene === '' || strtolower($orderNo) === 'balance') {
            return $meta;
        }
        if (!empty($meta['order_id']) && !empty($meta['user_id'])) {
            return $meta;
        }
        try {
            $table = $scene === self::SCENE_WITHDRAW ? 'fans_withdraw_order' : 'fans_recharge_order';
            $row = Db::name($table)->where('order_no', $orderNo)->find();
            if (!$row) {
                return $meta;
            }
            if (empty($meta['order_id'])) {
                $meta['order_id'] = (int)$row['id'];
            }
            if (empty($meta['user_id'])) {
                $meta['user_id'] = (int)$row['user_id'];
            }
            if (empty($meta['channel_id'])) {
                $meta['channel_id'] = (int)$row['channel_id'];
            }
            if (!isset($meta['amount']) || $meta['amount'] === '' || $meta['amount'] === null) {
                $meta['amount'] = $row['amount'];
            }
            if (empty($meta['order_status'])) {
                $meta['order_status'] = (string)($row['status'] ?? '');
            }
        } catch (\Throwable $e) {
        }
        return $meta;
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
