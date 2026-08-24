<?php

namespace app\admin\model\fanshub;

use think\Model;

class Redpacketauto extends Model
{
    protected $name = 'chat_rp_auto_task';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    public function getStatusList()
    {
        return ['normal' => '启用', 'hidden' => '停用'];
    }

    public function getPacketTypeList()
    {
        return [
            '1' => '普通红宝',
            '2' => '拼手气',
            '3' => '埋雷',
            '5' => '接龙红包',
        ];
    }

    public function getActorModeList()
    {
        return [
            '1' => '模式一：UID池',
            '2' => '模式二：机器人账户',
        ];
    }

    public function getAmountModeList()
    {
        return [
            '1' => '模式一：金额区间',
            '2' => '模式二：小额+大奖',
        ];
    }
}
