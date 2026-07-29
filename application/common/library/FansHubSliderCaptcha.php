<?php

namespace app\common\library;

use fast\Random;
use think\Cache;

/**
 * 福利大厅短信前滑块验证码（拖到最右侧）
 */
class FansHubSliderCaptcha
{
    const CACHE_PREFIX = 'fanshub_slider:';

    public static function enabled()
    {
        return !empty(FansHubService::config('sms_slider_enabled', true));
    }

    public static function create()
    {
        $token = Random::alnum(32);
        Cache::set(self::CACHE_PREFIX . $token, [
            't'  => time(),
            'ip' => request()->ip(),
            'w'  => max(200, (int)FansHubService::config('sms_slider_width', 280)),
        ], 300);

        return [
            'enabled' => true,
            'mode'    => 'slide',
            'token'   => $token,
            'hint'    => FansHubService::h5CopyText('slider_modal_hint'),
        ];
    }

    public static function verify($token, $x, $durationMs = 0, $maxX = 0)
    {
        $token = trim((string)$token);
        if ($token === '') {
            return false;
        }
        $key = self::CACHE_PREFIX . $token;
        $data = Cache::get($key);
        if (!$data || !is_array($data)) {
            return false;
        }
        $minDuration = max(0, (int)FansHubService::config('sms_slider_min_duration_ms', 180));
        if ($minDuration > 0 && (int)$durationMs > 0 && (int)$durationMs < $minDuration) {
            return false;
        }

        $ratio = (float)FansHubService::config('sms_slider_pass_ratio', 0.82);
        $ratio = max(0.5, min(0.99, $ratio));
        $x = (int)$x;
        $maxX = (int)$maxX;
        $width = max(200, (int)($data['w'] ?? FansHubService::config('sms_slider_width', 280)));

        if ($maxX >= 40) {
            // 用拖动行程比例，兼容窄屏；maxX 需与挑战宽度大致吻合，防伪造
            $expectedMax = max(40, $width - 42);
            if ($maxX < (int)floor($expectedMax * 0.55) || $maxX > (int)ceil($expectedMax * 1.5)) {
                return false;
            }
            if ($x < (int)floor($maxX * $ratio)) {
                return false;
            }
        } else {
            $threshold = (int)floor($width * $ratio);
            if ($x < $threshold) {
                return false;
            }
        }
        Cache::rm($key);
        return true;
    }
}
