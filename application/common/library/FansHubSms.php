<?php

namespace app\common\library;

use app\common\model\Sms as SmsModel;
use fast\Random;
use think\Hook;

/**
 * 福利大厅短信（国际号 + 可选 HTTP 网关）
 */
class FansHubSms
{
    public static function boot()
    {
        static $booted = false;
        if ($booted) {
            return;
        }
        $booted = true;
        Hook::add('sms_send', function ($sms) {
            if (!$sms || (string)$sms->event !== 'fanshub_login') {
                return null;
            }
            if (!FansHubService::config('sms_http_enabled')) {
                return null;
            }
            return self::dispatchHttp($sms);
        }, 0);
    }

    /**
     * 发送登录验证码（E.164 入库，网关格式外发）
     */
    public static function sendLoginCode($mobileE164, $code = null)
    {
        self::boot();
        $mobileE164 = trim((string)$mobileE164);
        if ($mobileE164 === '') {
            return false;
        }
        $code = $code !== null ? (string)$code : Random::numeric((int)config('captcha.length', 6));
        $country = FansHubMobile::detectCountryFromMobile($mobileE164);
        $gatewayMobile = FansHubMobile::smsRecipient($mobileE164);

        if ($country === 'CN' && FansHubDagouSms::enabled()) {
            $national = FansHubMobile::stripToNational($mobileE164, 'CN');
            return FansHubDagouSms::send($mobileE164, $national, $code);
        }

        if ($country !== 'CN' && FansHubUnaSms::enabled()) {
            return FansHubUnaSms::send($mobileE164, $code);
        }

        if (FansHubService::config('sms_http_enabled')) {
            return self::sendHttp($mobileE164, $gatewayMobile, $code);
        }

        return Sms::send($gatewayMobile, $code, 'fanshub_login');
    }

    protected static function dispatchHttp($sms)
    {
        $mobile = trim((string)$sms->mobile);
        if ($mobile === '') {
            return false;
        }
        $canonical = FansHubMobile::canonical($mobile);
        if ($canonical === '') {
            $canonical = $mobile;
        }
        return self::sendHttp($canonical, FansHubMobile::smsRecipient($canonical), (string)$sms->code);
    }

    protected static function sendHttp($storeMobile, $gatewayMobile, $code)
    {
        $url = trim((string)FansHubService::config('sms_http_url', ''));
        if ($url === '') {
            return false;
        }
        $method = strtoupper((string)FansHubService::config('sms_http_method', 'POST'));
        $timeout = max(1, min(30, (int)FansHubService::config('sms_http_timeout', 10)));
        $apiKey = trim((string)FansHubService::config('sms_http_api_key', ''));
        $country = FansHubMobile::detectCountryFromMobile($storeMobile);
        $template = (string)FansHubService::config('sms_http_template', '{"mobile":"{mobile}","code":"{code}","country":"{country}"}');
        $body = str_replace(
            ['{mobile}', '{code}', '{country}', '{event}'],
            [$gatewayMobile, $code, $country, 'fanshub_login'],
            $template
        );

        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
            $headers[] = 'X-Api-Key: ' . $apiKey;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $curlError !== '' || $httpCode < 200 || $httpCode >= 300) {
            return false;
        }

        $time = time();
        $ip = request()->ip();
        SmsModel::create([
            'event'      => 'fanshub_login',
            'mobile'     => $storeMobile,
            'code'       => $code,
            'ip'         => $ip,
            'createtime' => $time,
        ]);
        return true;
    }
}
