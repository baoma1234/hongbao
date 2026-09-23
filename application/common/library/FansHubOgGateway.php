<?php

namespace app\common\library;

/**
 * OG视讯 · 转账钱包模式网关
 *
 * 签名：参数键 a→z → key=value&… → md5(串+私钥) → signature
 * Header：key=公匙，operator-name=运营商名称
 * register：form-urlencoded（官方 curl）
 * deposit：application/json（官方 curl）
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
            'merchant_code'    => trim((string)($cfg['og_merchant_code'] ?? '')),
            'agent_id'         => trim((string)($cfg['og_agent_id'] ?? '')),
            'api_key'          => trim((string)($cfg['og_api_key'] ?? '')),
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
     * @param array<string,mixed> $params
     */
    public static function sign(array $params, $privateKey = null)
    {
        $secret = $privateKey !== null
            ? (string)$privateKey
            : (string)(self::config()['api_secret'] ?? '');
        return md5(self::buildSignString($params) . $secret);
    }

    /**
     * @param array<string,mixed> $params
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
        return hash_equals(strtolower(self::sign($params, $privateKey)), strtolower($got));
    }

    /**
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
     * player_id / nickname：字母数字下划线，8–64
     */
    public static function formatPlayerToken($raw, $prefix = 'u')
    {
        $s = preg_replace('/[^A-Za-z0-9_]/', '', (string)$raw);
        if ($s === '') {
            $s = $prefix . 'guest';
        }
        if (strlen($s) < 8) {
            // 左侧补 0，不再重复加前缀（避免 u12 → u0000u12）
            $s = str_pad($s, 8, '0', STR_PAD_LEFT);
        }
        if (strlen($s) > 64) {
            $s = substr($s, 0, 64);
        }
        return $s;
    }

    /**
     * transaction_id：小写字母+数字，8–64，全局唯一
     */
    public static function formatTransactionId($raw)
    {
        $s = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string)$raw));
        if (strlen($s) < 8) {
            $s = $s . substr(md5($s . microtime(true)), 0, 8);
        }
        if (strlen($s) > 64) {
            $s = substr($s, 0, 64);
        }
        return $s;
    }

    /**
     * 金额格式 (34,2) 字符串，用于签名与展示
     */
    public static function formatAmount($amount)
    {
        return number_format(round((float)$amount, 2), 2, '.', '');
    }

    /**
     * 注册玩家（form-urlencoded，官方示例无 signature）
     * S-100 成功；S-121 已存在 → 成功
     *
     * @return array{ok:bool,rs_code:string,rs_message:string,player_id?:string,nickname?:string,raw?:mixed}
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

        $ret = self::request('POST', '/api/v2/platform/transfer-wallet/register', $body, [
            'sign'         => false,
            'content_type' => 'form',
        ]);
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
     * 玩家转账 · 存入（JSON + signature）
     *
     * 官方：
     * - S-100 success + balance
     * - S-101 transaction is duplicated（同 transaction_id 已成功过）
     * - S-104 player not available（需先注册）
     *
     * @return array{ok:bool,rs_code:string,rs_message:string,balance?:string,transaction_id?:string,transfer_amount?:string,raw?:mixed,duplicate?:bool,player_missing?:bool}
     */
    public static function deposit($playerId, $amount, $transactionId)
    {
        self::$lastError = '';
        self::$lastResponse = null;
        self::$lastRequest = null;

        if (!self::credentialsReady()) {
            self::$lastError = 'OG 商户配置不完整（运营商名称/公匙/私钥/网关）';
            return ['ok' => false, 'rs_code' => '', 'rs_message' => self::$lastError];
        }

        $amt = round((float)$amount, 2);
        if ($amt <= 0) {
            self::$lastError = 'transfer_amount 必须大于 0';
            return ['ok' => false, 'rs_code' => '', 'rs_message' => self::$lastError];
        }

        $pid = self::formatPlayerToken($playerId);
        $txid = self::formatTransactionId($transactionId);
        $amtStr = self::formatAmount($amt);
        $ts = (string)time();

        // 签名用全字符串；JSON 体里 timestamp/transfer_amount 按官方示例用数字
        $signParams = [
            'player_id'       => $pid,
            'timestamp'       => $ts,
            'transaction_id'  => $txid,
            'transfer_amount' => $amtStr,
        ];
        $signParams = self::withSignature($signParams);

        $jsonBody = [
            'player_id'       => $pid,
            'timestamp'       => (int)$ts,
            'transaction_id'  => $txid,
            'transfer_amount' => (float)$amtStr,
            'signature'       => $signParams['signature'],
        ];

        $ret = self::request('POST', '/api/v2/platform/transfer-wallet/deposit', $jsonBody, [
            'sign'         => false, // 已签好
            'content_type' => 'json',
            'sign_params'  => $signParams,
        ]);
        $code = (string)($ret['rs_code'] ?? '');
        $msg = (string)($ret['rs_message'] ?? '');
        $balance = isset($ret['balance']) ? (string)$ret['balance'] : '';
        // S-100 成功；S-101 流水号已存在（视为已入账，勿退款）
        $ok = ($code === 'S-100' || $code === 'S-101');
        if (!$ok && $msg === '' && self::$lastError !== '') {
            $msg = self::$lastError;
        }
        if ($msg === '' && $ok) {
            $msg = $code === 'S-101' ? 'transaction is duplicated' : 'success';
        }
        return [
            'ok'              => $ok,
            'rs_code'         => $code,
            'rs_message'      => $msg !== '' ? $msg : 'deposit failed',
            'balance'         => $balance,
            'player_id'       => $pid,
            'transaction_id'  => $txid,
            'transfer_amount' => $amtStr,
            'duplicate'       => $code === 'S-101',
            'player_missing'  => $code === 'S-104',
            'raw'             => $ret,
        ];
    }

    /**
     * 玩家转账 · 提出（JSON + signature）
     * POST /api/v2/platform/transfer-wallet/withdraw
     *
     * - S-100 success + balance（OG 剩余余额）
     * - S-101 transaction is duplicated（视为已提出成功）
     * - S-103 insufficient balance
     * - S-104 player not available
     *
     * @return array{ok:bool,rs_code:string,rs_message:string,balance?:string,insufficient?:bool,duplicate?:bool,player_missing?:bool,raw?:mixed}
     */
    public static function withdraw($playerId, $amount, $transactionId)
    {
        self::$lastError = '';
        self::$lastResponse = null;
        self::$lastRequest = null;

        if (!self::credentialsReady()) {
            self::$lastError = 'OG 商户配置不完整（运营商名称/公匙/私钥/网关）';
            return ['ok' => false, 'rs_code' => '', 'rs_message' => self::$lastError];
        }

        $amt = round((float)$amount, 2);
        if ($amt <= 0) {
            self::$lastError = 'transfer_amount 必须大于 0';
            return ['ok' => false, 'rs_code' => '', 'rs_message' => self::$lastError];
        }

        $pid = self::formatPlayerToken($playerId);
        $txid = self::formatTransactionId($transactionId);
        $amtStr = self::formatAmount($amt);
        $ts = (string)time();

        $signParams = [
            'player_id'       => $pid,
            'timestamp'       => $ts,
            'transaction_id'  => $txid,
            'transfer_amount' => $amtStr,
        ];
        $signParams = self::withSignature($signParams);

        $jsonBody = [
            'player_id'       => $pid,
            'timestamp'       => (int)$ts,
            'transaction_id'  => $txid,
            'transfer_amount' => (float)$amtStr,
            'signature'       => $signParams['signature'],
        ];

        $ret = self::request('POST', '/api/v2/platform/transfer-wallet/withdraw', $jsonBody, [
            'sign'         => false,
            'content_type' => 'json',
            'sign_params'  => $signParams,
        ]);
        $code = (string)($ret['rs_code'] ?? '');
        $msg = (string)($ret['rs_message'] ?? '');
        $balance = isset($ret['balance']) ? (string)$ret['balance'] : '';
        $ok = ($code === 'S-100' || $code === 'S-101');
        if (!$ok && $msg === '' && self::$lastError !== '') {
            $msg = self::$lastError;
        }
        if ($msg === '' && $ok) {
            $msg = $code === 'S-101' ? 'transaction is duplicated' : 'success';
        }
        return [
            'ok'              => $ok,
            'rs_code'         => $code,
            'rs_message'      => $msg !== '' ? $msg : 'withdraw failed',
            'balance'         => $balance,
            'player_id'       => $pid,
            'transaction_id'  => $txid,
            'transfer_amount' => $amtStr,
            'duplicate'       => $code === 'S-101',
            'insufficient'    => $code === 'S-103',
            'player_missing'  => $code === 'S-104',
            'raw'             => $ret,
        ];
    }

    /**
     * 转账历史 GET /api/v2/platform/transaction/transfer-history
     *
     * - S-100 success + records + last_fetch_id
     * - S-115 no data found → 空列表视为成功
     *
     * @param array{fetch_id?:int|string,limit?:int|string,transaction_id?:string,player_id?:string} $query
     * @return array{ok:bool,rs_code:string,rs_message:string,last_fetch_id:int,records:array,raw?:mixed}
     */
    public static function transferHistory(array $query = [])
    {
        self::$lastError = '';
        self::$lastResponse = null;
        self::$lastRequest = null;

        if (!self::credentialsReady()) {
            self::$lastError = 'OG 商户配置不完整（运营商名称/公匙/私钥/网关）';
            return [
                'ok'            => false,
                'rs_code'       => '',
                'rs_message'    => self::$lastError,
                'last_fetch_id' => 0,
                'records'       => [],
            ];
        }

        $params = [];
        if (array_key_exists('fetch_id', $query) && $query['fetch_id'] !== '' && $query['fetch_id'] !== null) {
            $params['fetch_id'] = max(1, (int)$query['fetch_id']);
        }
        if (array_key_exists('limit', $query) && $query['limit'] !== '' && $query['limit'] !== null) {
            $lim = (int)$query['limit'];
            if ($lim < 1) {
                $lim = 1;
            }
            if ($lim > 8000) {
                $lim = 8000;
            }
            $params['limit'] = (string)$lim;
        }
        $txid = trim((string)($query['transaction_id'] ?? ''));
        if ($txid !== '') {
            $params['transaction_id'] = self::formatTransactionId($txid);
        }
        $pid = trim((string)($query['player_id'] ?? ''));
        if ($pid !== '') {
            $params['player_id'] = self::formatPlayerToken($pid);
        }

        $ret = self::request('GET', '/api/v2/platform/transaction/transfer-history', $params, [
            'sign'         => false,
            'content_type' => 'query',
        ]);
        $code = (string)($ret['rs_code'] ?? '');
        $msg = (string)($ret['rs_message'] ?? '');
        $records = [];
        if (!empty($ret['records']) && is_array($ret['records'])) {
            foreach ($ret['records'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $records[] = [
                    'fetch_id'          => (int)($row['fetch_id'] ?? 0),
                    'player_id'         => (string)($row['player_id'] ?? ''),
                    'transaction_id'    => (string)($row['transaction_id'] ?? ''),
                    'transaction_time'  => (int)($row['transaction_time'] ?? 0),
                    'transaction_type'  => strtolower(trim((string)($row['transaction_type'] ?? ''))),
                    'currency'          => (string)($row['currency'] ?? ''),
                    'transfer_amount'   => (string)($row['transfer_amount'] ?? ''),
                    'operator_name'     => (string)($row['operator_name'] ?? ''),
                ];
            }
        }
        $lastFetch = (int)($ret['last_fetch_id'] ?? 0);
        if ($lastFetch <= 0 && $records) {
            $ids = array_column($records, 'fetch_id');
            $lastFetch = $ids ? (int)max($ids) : 0;
        }
        // S-115 无数据：当成功空列表，方便前端分页
        $ok = ($code === 'S-100' || $code === 'S-115');
        if (!$ok && $msg === '' && self::$lastError !== '') {
            $msg = self::$lastError;
        }
        if ($msg === '' && $ok) {
            $msg = $code === 'S-115' ? 'no data found' : 'success';
        }
        return [
            'ok'            => $ok,
            'rs_code'       => $code,
            'rs_message'    => $msg !== '' ? $msg : 'transfer-history failed',
            'last_fetch_id' => $lastFetch,
            'records'       => $records,
            'raw'           => $ret,
        ];
    }

    /**
     * 游戏列表 GET /api/v2/platform/game/game-list
     *
     * 注意：正式/沙箱 game_id 不同；可用性会变，应定期拉取。
     *
     * @param array{game_id?:int|string,game_name?:string,game_type?:string} $query
     * @return array{ok:bool,rs_code:string,rs_message:string,records:array,raw?:mixed}
     */
    public static function gameList(array $query = [])
    {
        self::$lastError = '';
        self::$lastResponse = null;
        self::$lastRequest = null;

        if (!self::credentialsReady()) {
            self::$lastError = 'OG 商户配置不完整（运营商名称/公匙/私钥/网关）';
            return [
                'ok'         => false,
                'rs_code'    => '',
                'rs_message' => self::$lastError,
                'records'    => [],
            ];
        }

        $params = [];
        if (array_key_exists('game_id', $query) && $query['game_id'] !== '' && $query['game_id'] !== null) {
            $params['game_id'] = (int)$query['game_id'];
        }
        $gname = trim((string)($query['game_name'] ?? ''));
        if ($gname !== '') {
            $params['game_name'] = $gname;
        }
        $gtype = trim((string)($query['game_type'] ?? ''));
        if ($gtype !== '') {
            $params['game_type'] = $gtype;
        }

        $ret = self::request('GET', '/api/v2/platform/game/game-list', $params, [
            'sign'         => false,
            'content_type' => 'query',
        ]);
        $code = (string)($ret['rs_code'] ?? '');
        $msg = (string)($ret['rs_message'] ?? '');
        $records = [];
        if (!empty($ret['records']) && is_array($ret['records'])) {
            foreach ($ret['records'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $records[] = [
                    'game_id'   => (int)($row['game_id'] ?? 0),
                    'game_type' => (string)($row['game_type'] ?? ''),
                    'game_name' => (string)($row['game_name'] ?? ''),
                    'image'     => (string)($row['image'] ?? ''),
                ];
            }
        }
        $ok = ($code === 'S-100');
        if (!$ok && $msg === '' && self::$lastError !== '') {
            $msg = self::$lastError;
        }
        if ($msg === '' && $ok) {
            $msg = 'success';
        }
        return [
            'ok'         => $ok,
            'rs_code'    => $code,
            'rs_message' => $msg !== '' ? $msg : 'game-list failed',
            'records'    => $records,
            'sandbox'    => !empty(self::config()['sandbox']),
            'raw'        => $ret,
        ];
    }

    /**
     * @param array<string,mixed> $body
     * @param array{sign?:bool,content_type?:string,sign_params?:array} $opts
     * @return array<string,mixed>
     */
    public static function request($method, $path, array $body = [], array $opts = [])
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

        $sign = !array_key_exists('sign', $opts) || !empty($opts['sign']);
        $contentType = strtolower((string)($opts['content_type'] ?? 'form'));
        if ($sign) {
            $body = self::withSignature($body, $c['api_secret']);
        }

        $url = $base . '/' . ltrim((string)$path, '/');
        $methodUp = strtoupper((string)$method);
        $headers = [
            'key: ' . $c['api_key'],
            'operator-name: ' . $c['merchant_code'],
            'Accept: application/json',
        ];

        $payload = '';
        if ($methodUp === 'GET' || $contentType === 'query') {
            if ($body) {
                $qs = http_build_query($body);
                $url .= (strpos($url, '?') !== false ? '&' : '?') . $qs;
            }
        } elseif ($contentType === 'json') {
            $headers[] = 'Content-Type: application/json';
            $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $payload = http_build_query($body);
        }

        self::$lastRequest = [
            'method'       => $methodUp,
            'url'          => $url,
            'content_type' => $contentType,
            'headers'      => [
                'key'           => $c['api_key'],
                'operator-name' => $c['merchant_code'],
            ],
            'body'         => $body,
            'sign_params'  => $opts['sign_params'] ?? null,
        ];

        $timeout = (int)$c['timeout'];
        $ch = curl_init();
        $curlOpts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => $headers,
        ];
        if ($methodUp === 'POST') {
            $curlOpts[CURLOPT_POST] = true;
            $curlOpts[CURLOPT_POSTFIELDS] = $payload;
        } elseif ($methodUp === 'GET') {
            $curlOpts[CURLOPT_HTTPGET] = true;
        } else {
            $curlOpts[CURLOPT_CUSTOMREQUEST] = $methodUp;
            if ($payload !== '') {
                $curlOpts[CURLOPT_POSTFIELDS] = $payload;
            }
        }
        curl_setopt_array($ch, $curlOpts);
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
