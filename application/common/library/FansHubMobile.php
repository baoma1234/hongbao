<?php

namespace app\common\library;

use think\Validate;

/**
 * 福利大厅多国家手机号
 */
class FansHubMobile
{
    public static function countries()
    {
        return [
            'CN' => [
                'code'    => 'CN',
                'dial'    => '86',
                'label'   => '中国',
                'label_en'=> 'China',
                'pattern' => '/^1[3-9]\d{9}$/',
                'maxlen'  => 11,
                'placeholder_key' => 'login_phone_placeholder',
            ],
            'PH' => [
                'code'    => 'PH',
                'dial'    => '63',
                'label'   => '菲律宾',
                'label_en'=> 'Philippines',
                'pattern' => '/^9\d{9}$/',
                'maxlen'  => 10,
                'placeholder_key' => 'login_phone_placeholder_ph',
            ],
            'KH' => [
                'code'    => 'KH',
                'dial'    => '855',
                'label'   => '柬埔寨',
                'label_en'=> 'Cambodia',
                'pattern' => '/^\d{8,9}$/',
                'maxlen'  => 9,
                'placeholder_key' => 'login_phone_placeholder_kh',
            ],
            'ID' => [
                'code'    => 'ID',
                'dial'    => '62',
                'label'   => '印尼',
                'label_en'=> 'Indonesia',
                'pattern' => '/^8\d{8,11}$/',
                'maxlen'  => 12,
                'placeholder_key' => 'login_phone_placeholder_id',
            ],
            'VN' => [
                'code'    => 'VN',
                'dial'    => '84',
                'label'   => '越南',
                'label_en'=> 'Vietnam',
                'pattern' => '/^[35789]\d{8}$/',
                'maxlen'  => 10,
                'placeholder_key' => 'login_phone_placeholder_vn',
            ],
            'MY' => [
                'code'    => 'MY',
                'dial'    => '60',
                'label'   => '马来西亚',
                'label_en'=> 'Malaysia',
                'pattern' => '/^1\d{8,9}$/',
                'maxlen'  => 10,
                'placeholder_key' => 'login_phone_placeholder_my',
            ],
            'AE' => [
                'code'    => 'AE',
                'dial'    => '971',
                'label'   => '阿联酋',
                'label_en'=> 'United Arab Emirates',
                'pattern' => '/^5\d{8}$/',
                'maxlen'  => 9,
                'placeholder_key' => 'login_phone_placeholder_ae',
            ],
            'TR' => [
                'code'    => 'TR',
                'dial'    => '90',
                'label'   => '土耳其',
                'label_en'=> 'Turkey',
                'pattern' => '/^5\d{9}$/',
                'maxlen'  => 10,
                'placeholder_key' => 'login_phone_placeholder_tr',
            ],
            'RU' => [
                'code'    => 'RU',
                'dial'    => '7',
                'label'   => '俄罗斯',
                'label_en'=> 'Russia',
                'pattern' => '/^9\d{9}$/',
                'maxlen'  => 10,
                'placeholder_key' => 'login_phone_placeholder_ru',
            ],
            'JP' => [
                'code'    => 'JP',
                'dial'    => '81',
                'label'   => '日本',
                'label_en'=> 'Japan',
                'pattern' => '/^[789]0\d{8}$/',
                'maxlen'  => 10,
                'placeholder_key' => 'login_phone_placeholder_jp',
            ],
            'KR' => [
                'code'    => 'KR',
                'dial'    => '82',
                'label'   => '韩国',
                'label_en'=> 'South Korea',
                'pattern' => '/^10\d{7,8}$/',
                'maxlen'  => 11,
                'placeholder_key' => 'login_phone_placeholder_kr',
            ],
        ];
    }

    /**
     * 按区号从长到短匹配 E.164，避免 +7 误伤 +971 等
     */
    protected static function matchCountryCodeByE164($mobile)
    {
        $mobile = trim((string)$mobile);
        if ($mobile === '' || $mobile[0] !== '+') {
            return '';
        }
        $best = '';
        $bestLen = -1;
        foreach (self::countries() as $code => $item) {
            $prefix = '+' . $item['dial'];
            $len = strlen($prefix);
            if (strpos($mobile, $prefix) === 0 && $len > $bestLen) {
                $best = $code;
                $bestLen = $len;
            }
        }
        return $best;
    }

    public static function country($code)
    {
        $code = strtoupper(trim((string)$code));
        $all = self::countries();
        return $all[$code] ?? $all['CN'];
    }

    public static function stripToNational($mobile, $countryCode = 'CN')
    {
        $mobile = preg_replace('/\D+/', '', (string)$mobile);
        $country = self::country($countryCode);
        $dial = (string)$country['dial'];
        if ($mobile !== '' && strpos($mobile, $dial) === 0) {
            $mobile = substr($mobile, strlen($dial));
        }
        if ($countryCode === 'CN' && strlen($mobile) === 13 && strpos($mobile, '86') === 0) {
            $mobile = substr($mobile, 2);
        }
        // 非中国：去掉国内拨号冠号 0（09… / 08… / 01…）
        if (strtoupper((string)$countryCode) !== 'CN' && $mobile !== '' && $mobile[0] === '0') {
            $mobile = ltrim($mobile, '0');
        }
        return $mobile;
    }

    public static function normalize($mobile, $countryCode = 'CN')
    {
        $countryCode = strtoupper(trim((string)$countryCode));
        if (!isset(self::countries()[$countryCode])) {
            $countryCode = 'CN';
        }
        $national = self::stripToNational($mobile, $countryCode);
        if ($national === '') {
            return '';
        }
        if (!self::isValidNational($national, $countryCode)) {
            return '';
        }
        return '+' . self::country($countryCode)['dial'] . $national;
    }

    public static function isValidNational($national, $countryCode = 'CN')
    {
        $country = self::country($countryCode);
        $national = self::stripToNational($national, $countryCode);
        return $national !== '' && preg_match($country['pattern'], $national) === 1;
    }

    public static function isValid($mobile, $countryCode = '')
    {
        $mobile = trim((string)$mobile);
        if ($mobile === '') {
            return false;
        }
        if ($mobile[0] === '+') {
            $code = self::matchCountryCodeByE164($mobile);
            if ($code === '') {
                return false;
            }
            $prefix = '+' . self::country($code)['dial'];
            $national = substr($mobile, strlen($prefix));
            return self::isValidNational($national, $code);
        }
        $countryCode = $countryCode !== '' ? $countryCode : 'CN';
        return self::isValidNational($mobile, $countryCode);
    }

    public static function detectCountryFromMobile($mobile)
    {
        $mobile = trim((string)$mobile);
        if ($mobile === '') {
            return 'CN';
        }
        if ($mobile[0] === '+') {
            $code = self::matchCountryCodeByE164($mobile);
            if ($code !== '') {
                return $code;
            }
        }
        if (Validate::regex($mobile, "^1\d{10}$")) {
            return 'CN';
        }
        return 'CN';
    }

    public static function canonical($mobile)
    {
        $mobile = trim((string)$mobile);
        if ($mobile === '') {
            return '';
        }
        if ($mobile[0] === '+') {
            $code = self::matchCountryCodeByE164($mobile);
            if ($code !== '') {
                $prefix = '+' . self::country($code)['dial'];
                $national = substr($mobile, strlen($prefix));
                if (self::isValidNational($national, $code)) {
                    return $prefix . $national;
                }
            }
        }
        foreach (self::countries() as $code => $item) {
            $normalized = self::normalize($mobile, $code);
            if ($normalized !== '') {
                return $normalized;
            }
        }
        return $mobile;
    }

    public static function equivalent($a, $b)
    {
        $a = trim((string)$a);
        $b = trim((string)$b);
        if ($a === $b) {
            return true;
        }
        $ca = self::canonical($a);
        $cb = self::canonical($b);
        return $ca !== '' && $cb !== '' && $ca === $cb;
    }

    public static function smsRecipient($mobile)
    {
        $mobile = trim((string)$mobile);
        if ($mobile === '') {
            return '';
        }
        $canonical = self::canonical($mobile);
        if ($canonical !== '' && $canonical[0] === '+') {
            $code = self::matchCountryCodeByE164($canonical);
            if ($code !== '') {
                if ($code === 'CN') {
                    $prefix = '+' . self::country($code)['dial'];
                    return substr($canonical, strlen($prefix));
                }
                return $canonical;
            }
        }
        return $mobile;
    }

    public static function publicCountries()
    {
        $rows = [];
        foreach (self::countries() as $code => $item) {
            $rows[] = [
                'code' => $code,
                'dial' => $item['dial'],
                'label'=> $item['label'],
                'maxlen' => $item['maxlen'],
                'placeholder_key' => $item['placeholder_key'],
            ];
        }
        return $rows;
    }
}
