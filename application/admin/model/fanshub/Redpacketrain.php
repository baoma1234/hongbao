<?php

namespace app\admin\model\fanshub;

use think\Model;

class Redpacketrain extends Model
{
    protected $name = 'chat_rp_rain_task';
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
            '4' => '随机红宝',
        ];
    }
}
