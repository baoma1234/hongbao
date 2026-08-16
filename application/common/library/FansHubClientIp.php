<?php

namespace app\common\library;

/**
 * 解析用户真实客户端 IP（反代 / CDN / 阿里云 SLB 后不要用 REMOTE_ADDR）
 */
class FansHubClientIp
{
    /**
     * @return string IPv4/IPv6，失败时 0.0.0.0
     */
    public static function get()
    {
        $candidates = [];

        // CDN / 云厂商常见头（优先）
        foreach ([
            'HTTP_CF_CONNECTING_IP',      // Cloudflare
            'HTTP_ALI_CDN_REAL_IP',       // 阿里云 CDN
            'HTTP_X_TRUE_IP',
            'HTTP_TRUE_CLIENT_IP',
            'HTTP_X_REAL_IP',             // Nginx proxy_set_header X-Real-IP
        ] as $key) {
            $v = self::header($key);
            if ($v !== '') {
                $candidates[] = $v;
            }
        }

        // X-Forwarded-For: 取最左侧非内网公网地址；若全是内网则取第一个
        $xff = self::header('HTTP_X_FORWARDED_FOR');
        if ($xff !== '') {
            foreach (explode(',', $xff) as $part) {
                $part = trim($part);
                if ($part !== '' && strtolower($part) !== 'unknown') {
                    $candidates[] = $part;
                }
            }
        }

        $remote = self::header('REMOTE_ADDR');
        if ($remote !== '') {
            $candidates[] = $remote;
        }

        $public = '';
        $any = '';
        foreach ($candidates as $ip) {
            $ip = self::normalize($ip);
            if ($ip === '' || !self::isValid($ip)) {
                continue;
            }
            if ($any === '') {
                $any = $ip;
            }
            if (!self::isPrivateOrReserved($ip)) {
                $public = $ip;
                break;
            }
        }

        return $public !== '' ? $public : ($any !== '' ? $any : '0.0.0.0');
    }

    protected static function header($key)
    {
        try {
            if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
                return trim($_SERVER[$key]);
            }
        } catch (\Throwable $e) {
        }
        return '';
    }

    protected static function normalize($ip)
    {
        $ip = trim((string)$ip);
        // [::1]:port / 1.2.3.4:5678
        if (preg_match('/^\[([^\]]+)\](?::\d+)?$/', $ip, $m)) {
            return $m[1];
        }
        if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $ip, $m)) {
            return $m[1];
        }
        return $ip;
    }

    protected static function isValid($ip)
    {
        return (bool)filter_var($ip, FILTER_VALIDATE_IP);
    }

    protected static function isPrivateOrReserved($ip)
    {
        return !filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }
}
