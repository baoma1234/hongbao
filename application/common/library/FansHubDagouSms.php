<?php

namespace app\common\library;

use app\common\model\Sms as SmsModel;

/**
 * 大狗短信 V1.0.5 商户对接（中国区）
 */
class FansHubDagouSms
{
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
     * 发送验证码（中国区）
     *
     * @param string $storeMobile E.164 或规范手机号（入库）
     * @param string $national    11 位国内号
     * @param string $code        验证码
     */
    public static function send($storeMobile, $national, $code)
    {
        if (!self::enabled()) {
            return false;
        }
        $gateway = self::gatewayBase();
        $uname = trim((string)FansHubService::config('sms_dagou_uname'));
        $apikey = trim((string)FansHubService::config('sms_dagou_apikey'));
        $national = preg_replace('/\D+/', '', (string)$national);
        $code = (string)$code;
        if ($gateway === '' || $national === '' || $code === '') {
            return false;
        }

        $params = [
            'code'      => $code,
            'to'        => $national,
            'uname'     => $uname,
            'area_code' => '86',
        ];
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

        if ($response === false || $curlError !== '' || $httpCode < 200 || $httpCode >= 300) {
            \think\Log::write(sprintf(
                '[dagou-sms] send fail http=%s curl=%s body=%s url=%s',
                $httpCode,
                $curlError,
                mb_substr((string)$response, 0, 500),
                $url
            ), 'error');
            return false;
        }
        $json = json_decode((string)$response, true);
        if (!is_array($json) || (int)($json['status'] ?? 0) !== 1) {
            \think\Log::write(sprintf(
                '[dagou-sms] send reject body=%s url=%s',
                mb_substr((string)$response, 0, 500),
                $url
            ), 'error');
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
            'ip'         => request()->ip(),
            'createtime' => time(),
        ]);
        return true;
    }

    public static function getBalance()
    {
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

        \think\Log::write(sprintf(
            '[dagou-sms] balance http=%s curl=%s body=%s url=%s',
            $httpCode,
            $curlError,
            mb_substr((string)$response, 0, 500),
            $url
        ), 'info');

        if ($response === false || $curlError !== '') {
            throw new \Exception('余额查询失败：' . $curlError);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \Exception('余额查询 HTTP ' . $httpCode . '，请检查网关根地址（不要带 /api/sms）');
        }
        $json = json_decode((string)$response, true);
        if (!is_array($json) || (int)($json['status'] ?? 0) !== 1) {
            $msg = isset($json['msg']) ? (string)$json['msg'] : '接口返回失败';
            throw new \Exception($msg);
        }
        return $json['data'] ?? [];
    }
}
