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
        $gateway = rtrim(trim((string)FansHubService::config('sms_dagou_gateway')), '/');
        $uname = trim((string)FansHubService::config('sms_dagou_uname'));
        $apikey = trim((string)FansHubService::config('sms_dagou_apikey'));
        $national = preg_replace('/\D+/', '', (string)$national);
        $code = (string)$code;
        if ($national === '' || $code === '') {
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
            return false;
        }
        $json = json_decode((string)$response, true);
        if (!is_array($json) || (int)($json['status'] ?? 0) !== 1) {
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
        $gateway = rtrim(trim((string)FansHubService::config('sms_dagou_gateway')), '/');
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

        if ($response === false || $curlError !== '') {
            throw new \Exception('余额查询失败：' . $curlError);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \Exception('余额查询 HTTP ' . $httpCode);
        }
        $json = json_decode((string)$response, true);
        if (!is_array($json) || (int)($json['status'] ?? 0) !== 1) {
            $msg = isset($json['msg']) ? (string)$json['msg'] : '接口返回失败';
            throw new \Exception($msg);
        }
        return $json['data'] ?? [];
    }
}
