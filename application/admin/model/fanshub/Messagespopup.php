<?php

namespace app\admin\model\fanshub;

use think\Model;

class Messagespopup extends Model
{
    protected $name = 'chat_messages_popups';
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
            'daily'  => '每日一次（可点「今日不再显示」）',
            'once'   => '仅展示一次',
            'always' => '每次进入都展示',
        ];
    }

    public function getJumpTypeList()
    {
        return [
            'community' => '红宝 → 社群',
            'notice'    => '红宝 → 公告',
            'url'       => '外链 / 站内路径',
            'none'      => '不跳转（仅关闭）',
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
}
