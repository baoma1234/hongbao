<?php

namespace Im\Support;

/**
 * 安全 JSON：坏 UTF-8 不导致空响应（前端会表现为「加载失败」）
 */
class Json
{
    public static function encode($data, $flags = 0)
    {
        $flags = (int)$flags | JSON_UNESCAPED_UNICODE;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        } elseif (defined('JSON_PARTIAL_OUTPUT_ON_ERROR')) {
            $flags |= JSON_PARTIAL_OUTPUT_ON_ERROR;
        }
        $json = json_encode($data, $flags);
        if ($json !== false) {
            return $json;
        }
        // 兜底：清洗字符串后再试
        $cleaned = self::clean($data);
        $json = json_encode($cleaned, $flags);
        if ($json !== false) {
            return $json;
        }
        return '{"code":0,"message":"encode error"}';
    }

    /** 递归清洗非法 UTF-8 字符串 */
    public static function clean($value)
    {
        if (is_string($value)) {
            return self::cleanString($value);
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[is_string($k) ? self::cleanString($k) : $k] = self::clean($v);
            }
            return $out;
        }
        return $value;
    }

    public static function cleanString($s)
    {
        $s = (string)$s;
        if ($s === '') {
            return '';
        }
        if (mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        if (function_exists('iconv')) {
            $fixed = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
            if ($fixed !== false && $fixed !== '') {
                return $fixed;
            }
        }
        if (function_exists('mb_convert_encoding')) {
            $fixed = @mb_convert_encoding($s, 'UTF-8', 'UTF-8');
            if (is_string($fixed) && $fixed !== '') {
                return $fixed;
            }
        }
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?: '';
    }
}
