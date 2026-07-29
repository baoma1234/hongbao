<?php

namespace app\admin\model\fanshub;

use think\Model;

class Redpacketskin extends Model
{
    protected $name = 'chat_red_packet_skins';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    public function getPacketTypeList()
    {
        return [0 => '通用', 2 => '手气包', 3 => '埋雷包'];
    }

    public function getStatusList()
    {
        return ['normal' => '启用', 'hidden' => '停用'];
    }
}
