<?php

namespace app\common\library;

use app\common\model\Sms as SmsModel;

/**
 * Universe Action (UNAL/AboSEND) 国际短信 V2
 * 文档: https://apidoc.universeaction.com/web/#/20/335
 */
class FansHubUnaSms
{
    public static function enabled()
    {
        return !empty(FansHubService::config('sms_una_enabled'))
            && trim((string)FansHubService::config('sms_una_gateway', '')) !== ''
            && trim((string)FansHubService::config('sms_una_org_code', '')) !== ''
            && trim((string)FansHubService::config('sms_una_md5_key', '')) !== '';
    }

    public static function gatewayBase()
    {
        return rtrim(trim((string)FansHubService::config('sms_una_gateway')), '/');
    }

    public static function makeSendSign($orgCode, $content, $rand, $md5Key)
    {
        return strtoupper(md5($orgCode . $content . $rand . $md5Key));
    }

    public static function makeBalanceSign($orgCode, $rand, $md5Key)
    {
        return strtoupper(md5($orgCode . $rand . $md5Key));
    }

    public static function randomNonce()
    {
        return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public static function formatMobile($mobileE164)
    {
        $mobile = FansHubMobile::canonical($mobileE164);
        if ($mobile === '') {
            $mobile = trim((string)$mobileE164);
        }
        if ($mobile !== '' && $mobile[0] !== '+') {
            $mobile = '+' . preg_replace('/\D+/', '', $mobile);
        }
        return $mobile;
    }

    public static function buildContent($code)
    {
        $tpl = (string)FansHubService::config(
            'sms_una_content_template',
            'Your verification code is {code}'
        );
        return str_replace('{code}', (string)$code, $tpl);
    }

    /**
     * 发送国际验证码
     */
    public static function send($storeMobileE164, $code)
    {
        if (!self::enabled()) {
            return false;
        }
        $orgCode = trim((string)FansHubService::config('sms_una_org_code'));
        $md5Key = trim((string)FansHubService::config('sms_una_md5_key'));
        $content = self::buildContent($code);
        $rand = self::randomNonce();
        $sign = self::makeSendSign($orgCode, $content, $rand, $md5Key);
        $mobiles = self::formatMobile($storeMobileE164);
        if ($mobiles === '') {
            return false;
        }

        $payload = [
            'orgCode' => $orgCode,
            'mobiles' => $mobiles,
            'content' => $content,
            'rand'    => $rand,
            'sign'    => $sign,
        ];
        $oaNumber = trim((string)FansHubService::config('sms_una_oa_number', ''));
        $notifyUrl = trim((string)FansHubService::config('sms_una_notify_url', ''));
        if ($oaNumber !== '') {
            $payload['oaNumber'] = $oaNumber;
        }
        if ($notifyUrl !== '') {
            $payload['notifyUrl'] = $notifyUrl;
        }

        $useV2 = FansHubService::config('sms_una_use_v2') !== false;
        $path = $useV2 ? '/v2/api/sendSMS' : '/api/sendSMS';
        $url = self::gatewayBase() . $path;
        $timeout = max(1, min(30, (int)FansHubService::config('sms_una_timeout', 10)));

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_POST, true);

        if ($useV2) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json',
            ]);
        } else {
            $form = $payload;
            $form['mobileArea'] = '+0';
            $encodedContent = urlencode(urlencode($content));
            $form['content'] = $encodedContent;
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
                'Accept: application/json',
            ]);
        }

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $curlError !== '' || $httpCode < 200 || $httpCode >= 300) {
            return false;
        }
        $json = json_decode((string)$response, true);
        if (!is_array($json) || (int)($json['code'] ?? 0) !== 200) {
            return false;
        }

        $canonical = self::formatMobile($storeMobileE164);
        SmsModel::create([
            'event'      => 'fanshub_login',
            'mobile'     => $canonical,
            'code'       => (string)$code,
            'ip'         => FansHubClientIp::get(),
            'createtime' => time(),
        ]);
        return true;
    }

    public static function getBalance()
    {
        if (!self::enabled()) {
            throw new \Exception('Universe Action 国际短信未配置完整');
        }
        $orgCode = trim((string)FansHubService::config('sms_una_org_code'));
        $md5Key = trim((string)FansHubService::config('sms_una_md5_key'));
        $rand = self::randomNonce();
        $sign = self::makeBalanceSign($orgCode, $rand, $md5Key);
        $url = self::gatewayBase() . '/api/viewOrgBalance';
        $timeout = max(1, min(30, (int)FansHubService::config('sms_una_timeout', 10)));

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'orgCode' => $orgCode,
            'rand'    => $rand,
            'sign'    => $sign,
        ]));
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
        if (!is_array($json) || (int)($json['code'] ?? 0) !== 200) {
            $msg = isset($json['message']) ? (string)$json['message'] : '接口返回失败';
            throw new \Exception($msg);
        }
        return $json['data'] ?? [];
    }
}
