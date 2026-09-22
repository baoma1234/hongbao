<?php

namespace app\common\library;

/**
 * OG视讯 · 转账钱包模式网关
 *
 * 签名规则（官方）：
 * 1. 请求参数键按 a→z 排序（不含 signature）
 * 2. 按 key=value 用 & 拼接
 * 3. 字符串末尾直接拼接私钥，MD5（小写 hex）→ signature
 *
 * Header：key=公匙，operator-name=运营商名称
 * Content-Type：application/x-www-form-urlencoded
 */
class FansHubOgGateway
{
    /** @var string */
    protected static $lastError = '';

    /** @var array|null */
    protected static $lastResponse = null;

    /** @var array|null */
    protected static $lastRequest = null;

    /**
     * @return array<string,mixed>
     */
    public static function config()
    {
        $cfg = FansHubService::config();
        if (!is_array($cfg)) {
            $cfg = [];
        }
        return [
            'enabled'          => !empty($cfg['og_enabled']),
            'sandbox'          => !empty($cfg['og_sandbox']),
            // operator-name
            'merchant_code'    => trim((string)($cfg['og_merchant_code'] ?? '')),
            'agent_id'         => trim((string)($cfg['og_agent_id'] ?? '')),
            // public key → header key
            'api_key'          => trim((string)($cfg['og_api_key'] ?? '')),
            // private key → signature
            'api_secret'       => trim((string)($cfg['og_api_secret'] ?? '')),
            'api_base_url'     => rtrim(trim((string)($cfg['og_api_base_url'] ?? '')), '/'),
            'sandbox_base_url' => rtrim(trim((string)($cfg['og_sandbox_base_url'] ?? '')), '/'),
            'currency'         => strtoupper(trim((string)($cfg['og_currency'] ?? 'CNY'))) ?: 'CNY',
            'language'         => trim((string)($cfg['og_language'] ?? 'zh')) ?: 'zh',
            'callback_url'     => trim((string)($cfg['og_callback_url'] ?? '')),
            'return_url'       => trim((string)($cfg['og_return_url'] ?? '')),
            'timeout'          => max(3, min(120, (int)($cfg['og_timeout'] ?? 15))),
            'remark'           => trim((string)($cfg['og_remark'] ?? '')),
        ];
    }

    public static function isEnabled()
    {
        return !empty(self::config()['enabled']);
    }

    public static function baseUrl()
    {
        $c = self::config();
        if (!empty($c['sandbox']) && $c['sandbox_base_url'] !== '') {
            return $c['sandbox_base_url'];
        }
        return $c['api_base_url'];
    }

    public static function credentialsReady()
    {
        $c = self::config();
        if ($c['merchant_code'] === '' || $c['api_key'] === '' || $c['api_secret'] === '') {
            return false;
        }
        return self::baseUrl() !== '';
    }

    public static function getLastError()
    {
        return self::$lastError;
    }

    public static function getLastResponse()
    {
        return self::$lastResponse;
    }

    public static function getLastRequest()
    {
        return self::$lastRequest;
    }

    /**
     * 拼接待签字符串：键 a→z，key=value&key=value（不含 signature）
     *
     * @param array<string,mixed> $params
     */
    public static function buildSignString(array $params)
    {
        unset($params['signature'], $params['Signature'], $params['SIGNATURE']);
        $flat = [];
        foreach ($params as $k => $v) {
            $key = (string)$k;
            if ($key === '') {
                continue;
            }
            if (is_bool($v)) {
                $flat[$key] = $v ? '1' : '0';
            } elseif (is_array($v) || is_object($v)) {
                $flat[$key] = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif ($v === null) {
                $flat[$key] = '';
            } else {
                $flat[$key] = (string)$v;
            }
        }
        ksort($flat, SORT_STRING);
        $parts = [];
        foreach ($flat as $k => $v) {
            $parts[] = $k . '=' . $v;
        }
        return implode('&', $parts);
    }

    /**
     * 生成 signature = md5(signString + privateKey)
     *
     * @param array<string,mixed> $params
     */
    public static function sign(array $params, $privateKey = null)
    {
        $secret = $privateKey !== null
            ? (string)$privateKey
            : (string)(self::config()['api_secret'] ?? '');
        $base = self::buildSignString($params);
        return md5($base . $secret);
    }

    /**
     * 校验对方回调/通知中的 signature
     *
     * @param array<string,mixed> $params 含 signature
     */
    public static function verifySign(array $params, $privateKey = null)
    {
        $got = '';
        foreach (['signature', 'Signature', 'SIGNATURE'] as $k) {
            if (isset($params[$k]) && (string)$params[$k] !== '') {
                $got = (string)$params[$k];
                break;
            }
        }
        if ($got === '') {
            return false;
        }
        $expect = self::sign($params, $privateKey);
        return hash_equals(strtolower($expect), strtolower($got));
    }

    /**
     * 给参数补上 signature
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public static function withSignature(array $params, $privateKey = null)
    {
        unset($params['signature'], $params['Signature'], $params['SIGNATURE']);
        $params['signature'] = self::sign($params, $privateKey);
        return $params;
    }

    /**
     * 规范 player_id / nickname：字母数字下划线，8–64
     */
    public static function formatPlayerToken($raw, $prefix = 'u')
    {
        $s = preg_replace('/[^A-Za-z0-9_]/', '', (string)$raw);
        if ($s === '') {
            $s = $prefix . 'guest';
        }
        if (strlen($s) < 8) {
            $s = $prefix . str_pad($s, 7, '0', STR_PAD_LEFT);
        }
        if (strlen($s) > 64) {
            $s = substr($s, 0, 64);
        }
        return $s;
    }

    /**
     * 注册玩家 POST /api/v2/platform/transfer-wallet/register
     * 官方示例 body：player_id / nickname / timestamp（无 signature）
     * S-100 成功；S-121 已存在 → 视为成功
     *
     * @return array{ok:bool,rs_code:string,rs_message:string,raw?:mixed}
     */
    public static function registerPlayer($playerId, $nickname = null)
    {
        self::$lastError = '';
        self::$lastResponse = null;
        self::$lastRequest = null;

        if (!self::credentialsReady()) {
            self::$lastError = 'OG 商户配置不完整（运营商名称/公匙/私钥/网关）';
            return ['ok' => false, 'rs_code' => '', 'rs_message' => self::$lastError];
        }

        $pid = self::formatPlayerToken($playerId);
        $nick = self::formatPlayerToken($nickname !== null && $nickname !== '' ? $nickname : $pid, 'n');

        $body = [
            'player_id' => $pid,
            'nickname'  => $nick,
            'timestamp' => (string)time(),
        ];
        // 注册接口官方 curl 未带 signature；若后续文档要求可改为 withSignature($body)

        $ret = self::request('POST', '/api/v2/platform/transfer-wallet/register', $body, false);
        $code = (string)($ret['rs_code'] ?? '');
        $msg = (string)($ret['rs_message'] ?? '');
        $ok = ($code === 'S-100' || $code === 'S-121');
        if (!$ok && $msg === '' && self::$lastError !== '') {
            $msg = self::$lastError;
        }
        return [
            'ok'         => $ok,
            'rs_code'    => $code,
            'rs_message' => $msg !== '' ? $msg : ($ok ? 'success' : 'register failed'),
            'player_id'  => $pid,
            'nickname'   => $nick,
            'raw'        => $ret,
        ];
    }

    /**
     * 通用 form 请求；$signBody=true 时自动附加 signature
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public static function request($method, $path, array $body = [], $signBody = true)
    {
        self::$lastError = '';
        self::$lastResponse = null;
        self::$lastRequest = null;

        $c = self::config();
        $base = self::baseUrl();
        if ($base === '') {
            self::$lastError = 'OG 网关地址未配置';
            return [];
        }

        if ($signBody) {
            $body = self::withSignature($body, $c['api_secret']);
        }

        $url = $base . '/' . ltrim((string)$path, '/');
        $headers = [
            'key: ' . $c['api_key'],
            'operator-name: ' . $c['merchant_code'],
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ];

        self::$lastRequest = [
            'method'  => strtoupper((string)$method),
            'url'     => $url,
            'headers' => [
                'key'           => $c['api_key'],
                'operator-name' => $c['merchant_code'],
            ],
            'body'    => $body,
        ];

        $timeout = (int)$c['timeout'];
        $ch = curl_init();
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => $headers,
        ];
        if (strtoupper((string)$method) === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($body);
        } else {
            $opts[CURLOPT_CUSTOMREQUEST] = strtoupper((string)$method);
            if ($body) {
                $opts[CURLOPT_POSTFIELDS] = http_build_query($body);
            }
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            self::$lastError = 'curl#' . $errno . ' ' . $err;
            self::$lastResponse = ['http' => $http, 'raw' => $raw];
            return [];
        }

        $decoded = [];
        if (is_string($raw) && $raw !== '') {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $decoded = $json;
            } else {
                self::$lastError = '响应非 JSON：' . mb_substr($raw, 0, 200);
            }
        } else {
            self::$lastError = '空响应 HTTP ' . $http;
        }
        self::$lastResponse = [
            'http' => $http,
            'raw'  => $raw,
            'data' => $decoded,
        ];
        if ($http >= 400 && self::$lastError === '') {
            self::$lastError = 'HTTP ' . $http;
        }
        return $decoded;
    }
}
