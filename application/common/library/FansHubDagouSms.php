<?php

namespace app\common\library;

use app\common\model\Sms as SmsModel;

/**
 * 大狗短信 V1.0.5 商户对接（中国区）
 *
 * 文档要点：
 * - POST application/x-www-form-urlencoded
 * - 发送 /api/sms：code,to,uname[,area_code],sign
 * - 余额 /api/get_balance：uname,timestamp,sign
 * - 签名：非空非 sign 字段 ASCII 排序后 key=value&...&key=Api密钥，MD5 大写
 * - 商户需配置 IP 白名单，未加白常见返回：访问受限,请联系客服
 */
class FansHubDagouSms
{
    /** @var string 最近一次失败原因（供后台测试展示） */
    protected static $lastError = '';

    public static function getLastError()
    {
        return self::$lastError;
    }

    protected static function setError($msg)
    {
        self::$lastError = trim((string)$msg);
    }

    public static function enabled()
    {
        return !empty(FansHubService::config('sms_dagou_enabled'))
            && trim((string)FansHubService::config('sms_dagou_gateway', '')) !== ''
            && trim((string)FansHubService::config('sms_dagou_uname', '')) !== ''
            && trim((string)FansHubService::config('sms_dagou_apikey', '')) !== '';
    }

    /**
     * 网关根地址（允许误填 .../api/sms，自动剥掉）
     */
    public static function gatewayBase()
    {
        $gateway = rtrim(trim((string)FansHubService::config('sms_dagou_gateway')), '/');
        if ($gateway === '') {
            return '';
        }
        $gateway = preg_replace('#/api/sms$#i', '', $gateway);
        $gateway = preg_replace('#/api/get_balance$#i', '', $gateway);
        return rtrim((string)$gateway, '/');
    }

    public static function makeSign(array $data, $apikey)
    {
        ksort($data);
        $signStr = '';
        foreach ($data as $k => $val) {
            if ($k === 'sign' || $k === 'sign_type' || $val === null || $val === '') {
                continue;
            }
            $signStr .= $k . '=' . $val . '&';
        }
        $signStr = substr($signStr, 0, -1);
        $signStr .= '&key=' . $apikey;
        return strtoupper(md5($signStr));
    }

    /**
     * 写大狗专用日志：runtime/log/dagou_sms_YYYYMMDD.log + ThinkPHP Log
     */
    public static function writeDiag($level, $message, array $ctx = [])
    {
        $line = '[' . date('Y-m-d H:i:s') . '] [' . strtoupper((string)$level) . '] ' . $message;
        if ($ctx) {
            $safe = $ctx;
            if (isset($safe['apikey'])) {
                $safe['apikey'] = '***';
            }
            if (isset($safe['sign_raw']) && is_string($safe['sign_raw'])) {
                $safe['sign_raw'] = preg_replace('/key=.+$/', 'key=***', $safe['sign_raw']);
            }
            $line .= ' ' . json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $line .= "\n";
        $runtime = defined('RUNTIME_PATH') ? RUNTIME_PATH : (dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR);
        $dir = rtrim($runtime, '\\/') . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($dir . 'dagou_sms_' . date('Ymd') . '.log', $line, FILE_APPEND | LOCK_EX);
        try {
            \think\Log::write('[dagou-sms] ' . $message . ($ctx ? ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE) : ''), $level === 'error' ? 'error' : 'info');
        } catch (\Throwable $e) {
        }
    }

    protected static function signRaw(array $data, $apikey)
    {
        ksort($data);
        $signStr = '';
        foreach ($data as $k => $val) {
            if ($k === 'sign' || $k === 'sign_type' || $val === null || $val === '') {
                continue;
            }
            $signStr .= $k . '=' . $val . '&';
        }
        return substr($signStr, 0, -1) . '&key=' . $apikey;
    }

    protected static function outboundIpHint()
    {
        // 仅作日志参考，不保证与出网 IP 完全一致
        try {
            return (string)request()->server('SERVER_ADDR', '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * 解析大狗响应：对方有时返回双重 JSON 字符串（外层带引号）
     */
    protected static function decodeResponse($response)
    {
        $raw = (string)$response;
        if ($raw === '') {
            return null;
        }
        // 去 BOM / 空白
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $raw = trim($raw);
        $json = json_decode($raw, true);
        // 双重编码："{\"status\":1,...}"
        if (is_string($json)) {
            $json = json_decode(trim($json), true);
        }
        // 偶发外层再包一层引号
        if (!is_array($json) && strlen($raw) >= 2 && $raw[0] === '"' && substr($raw, -1) === '"') {
            $inner = stripcslashes(substr($raw, 1, -1));
            $json = json_decode($inner, true);
            if (is_string($json)) {
                $json = json_decode(trim($json), true);
            }
        }
        return is_array($json) ? $json : null;
    }

    /**
     * 发送验证码（中国区）
     *
     * @param string $storeMobile E.164 或规范手机号（入库）
     * @param string $national    11 位国内号
     * @param string $code        验证码
     */
    public static function send($storeMobile, $national, $code)
    {
        self::setError('');
        if (!self::enabled()) {
            self::setError('大狗短信未启用或配置不完整');
            self::writeDiag('error', 'send skipped: not enabled', [
                'enabled_flag' => !empty(FansHubService::config('sms_dagou_enabled')),
                'gateway'      => (string)FansHubService::config('sms_dagou_gateway', ''),
                'uname'        => (string)FansHubService::config('sms_dagou_uname', ''),
            ]);
            return false;
        }
        $gateway = self::gatewayBase();
        $uname = trim((string)FansHubService::config('sms_dagou_uname'));
        $apikey = trim((string)FansHubService::config('sms_dagou_apikey'));
        $national = preg_replace('/\D+/', '', (string)$national);
        $code = (string)$code;
        if ($gateway === '' || $national === '' || $code === '') {
            self::setError('网关/手机号/验证码为空');
            self::writeDiag('error', 'send skipped: empty params', compact('gateway', 'national') + ['code_len' => strlen($code)]);
            return false;
        }

        // 文档：area_code 为空则不要传；传了必须参与签名。中国固定传 86。
        $params = [
            'code'      => $code,
            'to'        => $national,
            'uname'     => $uname,
            'area_code' => '86',
        ];
        $signRaw = self::signRaw($params, $apikey);
        $params['sign'] = self::makeSign($params, $apikey);

        $url = $gateway . '/api/sms';
        $timeout = max(1, min(30, (int)FansHubService::config('sms_dagou_timeout', 10)));
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            'Accept: application/json',
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $ctx = [
            'url'         => $url,
            'http'        => $httpCode,
            'curl'        => $curlError,
            'to'          => $national,
            'uname'       => $uname,
            'server_addr' => self::outboundIpHint(),
            'sign_raw'    => $signRaw,
            'body'        => mb_substr((string)$response, 0, 800),
        ];

        if ($response === false || $curlError !== '' || $httpCode < 200 || $httpCode >= 300) {
            $msg = $curlError !== '' ? ('网络错误: ' . $curlError) : ('HTTP ' . $httpCode);
            self::setError($msg);
            self::writeDiag('error', 'send transport fail', $ctx);
            return false;
        }
        $json = self::decodeResponse($response);
        if (!is_array($json) || (int)($json['status'] ?? 0) !== 1) {
            $apiMsg = is_array($json) ? (string)($json['msg'] ?? '接口返回失败') : '非JSON响应';
            // 文档：需添加 IP 白名单；实测常见 msg=访问受限,请联系客服
            if (strpos($apiMsg, '访问受限') !== false || strpos($apiMsg, '白名单') !== false) {
                $apiMsg .= '（多为出口 IP 未加入大狗白名单，请把服务器公网 IP 发给商务加白）';
            }
            self::setError($apiMsg);
            self::writeDiag('error', 'send api reject', $ctx + ['api_msg' => $apiMsg]);
            return false;
        }

        $canonical = FansHubMobile::canonical($storeMobile);
        if ($canonical === '') {
            $canonical = '+86' . $national;
        }
        SmsModel::create([
            'event'      => 'fanshub_login',
            'mobile'     => $canonical,
            'code'       => $code,
            'ip'         => FansHubClientIp::get(),
            'createtime' => time(),
        ]);
        self::writeDiag('info', 'send ok', [
            'to'      => $national,
            'send_id' => (string)($json['send_id'] ?? ''),
            'url'     => $url,
        ]);
        return true;
    }

    public static function getBalance()
    {
        self::setError('');
        if (!self::enabled()) {
            throw new \Exception('大狗短信未配置完整');
        }
        $gateway = self::gatewayBase();
        $uname = trim((string)FansHubService::config('sms_dagou_uname'));
        $apikey = trim((string)FansHubService::config('sms_dagou_apikey'));
        $params = [
            'uname'     => $uname,
            'timestamp' => (string)time(),
        ];
        $signRaw = self::signRaw($params, $apikey);
        $params['sign'] = self::makeSign($params, $apikey);

        $url = $gateway . '/api/get_balance';
        $timeout = max(1, min(30, (int)FansHubService::config('sms_dagou_timeout', 10)));
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            'Accept: application/json',
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $ctx = [
            'url'         => $url,
            'http'        => $httpCode,
            'curl'        => $curlError,
            'uname'       => $uname,
            'server_addr' => self::outboundIpHint(),
            'sign_raw'    => $signRaw,
            'body'        => mb_substr((string)$response, 0, 800),
        ];
        self::writeDiag('info', 'balance response', $ctx);

        if ($response === false || $curlError !== '') {
            throw new \Exception('余额查询失败：' . $curlError);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \Exception('余额查询 HTTP ' . $httpCode . '，请检查网关根地址（不要带 /api/sms）');
        }
        $json = self::decodeResponse($response);
        if (!is_array($json) || (int)($json['status'] ?? 0) !== 1) {
            $msg = is_array($json) ? (string)($json['msg'] ?? '接口返回失败') : '非JSON响应';
            if (strpos($msg, '访问受限') !== false || strpos($msg, '白名单') !== false) {
                $msg .= '（出口 IP 未加白，请联系大狗商务把服务器公网 IP 加入白名单）';
            }
            self::setError($msg);
            throw new \Exception($msg);
        }
        return $json['data'] ?? [];
    }
}
