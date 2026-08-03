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
            '1' => '普通红包',
            '2' => '拼手气',
            '3' => '埋雷',
        ];
    }
}
