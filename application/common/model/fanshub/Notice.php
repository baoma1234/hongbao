<?php

namespace app\common\model\fanshub;

use think\Model;

class Notice extends Model
{
    protected $name = 'fans_notice';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    /** @return string[] code => 中文名 */
    public static function categoryMap()
    {
        return [
            'latest'  => '最新发布',
            'promote' => '推广赚钱',
            'ads'     => '广告发布',
            'rules'   => '游戏规则',
        ];
    }

    /** 分类多语言展示名 */
    public static function categoryLabelMap()
    {
        return [
            'latest' => [
                'zh-CN' => '最新发布',
                'en-PH' => 'Latest',
                'km-KH' => 'ថ្មីបំផុត',
                'id-ID' => 'Terbaru',
                'vi-VN' => 'Mới nhất',
                'ms-MY' => 'Terkini',
            ],
            'promote' => [
                'zh-CN' => '推广赚钱',
                'en-PH' => 'Promote & Earn',
                'km-KH' => 'ផ្សព្វផ្សាយរកប្រាក់',
                'id-ID' => 'Promosi & Cuan',
                'vi-VN' => 'Kiếm tiền giới thiệu',
                'ms-MY' => 'Promosi & Jana',
            ],
            'ads' => [
                'zh-CN' => '广告发布',
                'en-PH' => 'Ads',
                'km-KH' => 'ផ្សាយពាណិជ្ជកម្ម',
                'id-ID' => 'Iklan',
                'vi-VN' => 'Quảng cáo',
                'ms-MY' => 'Iklan',
            ],
            'rules' => [
                'zh-CN' => '游戏规则',
                'en-PH' => 'Game Rules',
                'km-KH' => 'វិធានហ្គេម',
                'id-ID' => 'Aturan Game',
                'vi-VN' => 'Luật chơi',
                'ms-MY' => 'Peraturan',
            ],
        ];
    }

    public static function categoryLabel($code, $locale = 'zh-CN')
    {
        $map = self::categoryLabelMap();
        $code = (string)$code;
        if (!isset($map[$code])) {
            return $code;
        }
        return $map[$code][$locale] ?? ($map[$code]['zh-CN'] ?? $code);
    }

    public function getImagesAttr($value)
    {
        if (is_array($value)) {
            return $value;
        }
        $value = trim((string)$value);
        if ($value === '') {
            return [];
        }
        $arr = json_decode($value, true);
        return is_array($arr) ? array_values(array_filter(array_map('strval', $arr))) : [];
    }

    public function setImagesAttr($value)
    {
        if (is_string($value)) {
            $trim = trim($value);
            if ($trim !== '' && $trim[0] === '[') {
                return $trim;
            }
            $parts = preg_split('/[\r\n,]+/', $trim);
            $value = array_values(array_filter(array_map('trim', $parts ?: [])));
        }
        if (!is_array($value)) {
            $value = [];
        }
        return json_encode(array_values($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function getActionButtonsAttr($value)
    {
        if (is_array($value)) {
            return $value;
        }
        $value = trim((string)$value);
        if ($value === '') {
            return [];
        }
        $arr = json_decode($value, true);
        return is_array($arr) ? $arr : [];
    }

    public function setActionButtonsAttr($value)
    {
        if (is_string($value)) {
            $trim = trim($value);
            if ($trim === '') {
                return '[]';
            }
            $decoded = json_decode($trim, true);
            if (is_array($decoded)) {
                return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            return '[]';
        }
        if (!is_array($value)) {
            $value = [];
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function getContentI18nAttr($value)
    {
        if (is_array($value)) {
            return $value;
        }
        $value = trim((string)$value);
        if ($value === '') {
            return [];
        }
        $arr = json_decode($value, true);
        return is_array($arr) ? $arr : [];
    }

    public function setContentI18nAttr($value)
    {
        return self::encodeI18nMap($value);
    }

    public function getActionLabelI18nAttr($value)
    {
        if (is_array($value)) {
            return $value;
        }
        $value = trim((string)$value);
        if ($value === '') {
            return [];
        }
        $arr = json_decode($value, true);
        return is_array($arr) ? $arr : [];
    }

    public function setActionLabelI18nAttr($value)
    {
        return self::encodeI18nMap($value);
    }

    public function getAuthorNameI18nAttr($value)
    {
        if (is_array($value)) {
            return $value;
        }
        $value = trim((string)$value);
        if ($value === '') {
            return [];
        }
        $arr = json_decode($value, true);
        return is_array($arr) ? $arr : [];
    }

    public function setAuthorNameI18nAttr($value)
    {
        return self::encodeI18nMap($value);
    }

    public static function encodeI18nMap($value)
    {
        if (is_string($value)) {
            $trim = trim($value);
            if ($trim === '') {
                return '{}';
            }
            $decoded = json_decode($trim, true);
            if (is_array($decoded)) {
                $value = $decoded;
            } else {
                return '{}';
            }
        }
        if (!is_array($value)) {
            return '{}';
        }
        $out = [];
        foreach ($value as $k => $v) {
            $k = trim((string)$k);
            $v = trim((string)$v);
            if ($k === '' || $v === '' || $k === 'zh-CN') {
                continue;
            }
            $out[$k] = $v;
        }
        return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** 按语言取文案，缺省回退中文主字段 */
    public function localized($field, $locale = 'zh-CN')
    {
        $locale = (string)$locale;
        $base = (string)($this->getData($field) ?? '');
        if ($locale === 'zh-CN' || $locale === '') {
            return $base;
        }
        $i18nField = $field . '_i18n';
        $map = $this->$i18nField;
        if (!is_array($map)) {
            $map = [];
        }
        $alt = trim((string)($map[$locale] ?? ''));
        return $alt !== '' ? $alt : $base;
    }
}
