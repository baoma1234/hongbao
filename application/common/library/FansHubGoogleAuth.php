<?php

namespace app\common\library;

/**
 * 登录验证码可用的 Google Authenticator（TOTP）辅助。
 * 依赖 application/common/library/GoogleAuthenticator.php
 */
class FansHubGoogleAuth
{
    /** @var \PHPGangsta_GoogleAuthenticator|null */
    protected static $ga;

    protected static function ga()
    {
        if (self::$ga === null) {
            require_once APP_PATH . 'common/library/GoogleAuthenticator.php';
            self::$ga = new \PHPGangsta_GoogleAuthenticator();
        }
        return self::$ga;
    }

    public static function normalizeSecret($secret)
    {
        $secret = strtoupper(preg_replace('/\s+/', '', (string)$secret));
        $secret = rtrim($secret, '=');
        if ($secret === '' || !preg_match('/^[A-Z2-7]+$/', $secret)) {
            return '';
        }
        return $secret;
    }

    public static function createSecret($length = 16)
    {
        return self::ga()->createSecret((int)$length);
    }

    public static function getCode($secret)
    {
        $secret = self::normalizeSecret($secret);
        if ($secret === '') {
            return '';
        }
        return self::ga()->getCode($secret);
    }

    public static function verify($secret, $code, $discrepancy = 1)
    {
        $secret = self::normalizeSecret($secret);
        $code = preg_replace('/\s+/', '', (string)$code);
        if ($secret === '' || $code === '' || !preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        return (bool)self::ga()->verifyCode($secret, $code, max(0, (int)$discrepancy));
    }

    public static function qrUrl($label, $secret, $issuer = 'FansHub')
    {
        $secret = self::normalizeSecret($secret);
        if ($secret === '') {
            return '';
        }
        $label = trim((string)$label) !== '' ? (string)$label : 'login';
        return self::ga()->getQRCodeGoogleUrl($label, $secret, (string)$issuer);
    }
}
