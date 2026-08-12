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
     * 写入短信发送日志（后台可查验证码）
     */
    public static function writeLog($mobile, $code, $event = 'fanshub_login', $channel = 'default')
    {
        $mobile = trim((string)$mobile);
        $code = trim((string)$code);
        if ($mobile === '' || $code === '') {
            return;
        }
        try {
            \app\common\model\fanshub\SmsLog::create([
                'event'      => substr((string)$event, 0, 32),
                'mobile'     => substr($mobile, 0, 32),
                'code'       => substr($code, 0, 16),
                'channel'    => substr((string)$channel, 0, 32),
                'ip'         => substr((string)request()->ip(), 0, 64),
                'status'     => 'sent',
                'createtime' => time(),
                'usedtime'   => null,
            ]);
        } catch (\Throwable $e) {
            // 日志失败不影响发短信
        }
    }

    /**
     * 验证成功 / flush 时标记最近一条为已使用
     */
    public static function markLogUsed($mobile, $event = 'fanshub_login')
    {
        $mobile = trim((string)$mobile);
        if ($mobile === '') {
            return;
        }
        try {
            $row = \app\common\model\fanshub\SmsLog::where([
                'mobile' => $mobile,
                'event'  => $event,
                'status' => 'sent',
            ])->order('id', 'DESC')->find();
            if ($row) {
                $row->save(['status' => 'used', 'usedtime' => time()]);
            }
            // 兼容网关入库号与 E.164 不一致
            $alt = FansHubMobile::smsRecipient($mobile);
            if ($alt !== '' && $alt !== $mobile) {
                $row2 = \app\common\model\fanshub\SmsLog::where([
                    'mobile' => $alt,
                    'event'  => $event,
                    'status' => 'sent',
                ])->order('id', 'DESC')->find();
                if ($row2) {
                    $row2->save(['status' => 'used', 'usedtime' => time()]);
                }
            }
        } catch (\Throwable $e) {
        }
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
            $ok = FansHubDagouSms::send($mobileE164, $national, $code);
            if ($ok) {
                self::writeLog($mobileE164, $code, 'fanshub_login', 'dagou');
            } else {
                \think\Log::write('[fanshub-sms] dagou fail mobile=' . $mobileE164 . ' err=' . FansHubDagouSms::getLastError(), 'error');
            }
            return $ok;
        }

        if ($country === 'CN' && !FansHubDagouSms::enabled()) {
            \think\Log::write('[fanshub-sms] CN mobile but dagou disabled, fallback channels', 'warning');
        }

        if ($country !== 'CN' && FansHubUnaSms::enabled()) {
            $ok = FansHubUnaSms::send($mobileE164, $code);
            if ($ok) {
                self::writeLog($mobileE164, $code, 'fanshub_login', 'una');
            }
            return $ok;
        }

        if (FansHubService::config('sms_http_enabled')) {
            $ok = self::sendHttp($mobileE164, $gatewayMobile, $code);
            if ($ok) {
                self::writeLog($mobileE164, $code, 'fanshub_login', 'http');
            }
            return $ok;
        }

        $ok = Sms::send($gatewayMobile, $code, 'fanshub_login');
        if ($ok) {
            self::writeLog($mobileE164 !== '' ? $mobileE164 : $gatewayMobile, $code, 'fanshub_login', 'default');
        } else {
            \think\Log::write('[fanshub-sms] all channels failed mobile=' . $mobileE164 . ' country=' . $country, 'error');
        }
        return $ok;
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
