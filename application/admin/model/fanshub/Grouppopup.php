<?php

namespace app\admin\model\fanshub;

use think\Model;

class Grouppopup extends Model
{
    protected $name = 'chat_group_popups';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    public function getStatusList()
    {
        return ['normal' => '启用', 'hidden' => '停用'];
    }

    public function getShowModeList()
    {
        return [
            'always' => '每次进入：挂在群公告下，可点开查看（可今日不显示）',
            'once'   => '仅展示一次（进群弹窗）',
        ];
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

    public function getTitleI18nAttr($value)
    {
        return $this->decodeI18n($value);
    }

    public function setTitleI18nAttr($value)
    {
        return $this->encodeI18n($value);
    }

    public function getContentI18nAttr($value)
    {
        return $this->decodeI18n($value);
    }

    public function setContentI18nAttr($value)
    {
        return $this->encodeI18n($value);
    }

    protected function decodeI18n($value)
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

    protected function encodeI18n($value)
    {
        if (is_string($value)) {
            $trim = trim($value);
            if ($trim === '') {
                return '{}';
            }
            $decoded = json_decode($trim, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) {
            return '{}';
        }
        $out = [];
        foreach ($value as $k => $v) {
            $k = trim((string)$k);
            $v = trim((string)$v);
            if ($k !== '' && $v !== '') {
                $out[$k] = $v;
            }
        }
        return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
