<?php

namespace app\admin\model\fanshub;

use think\Model;

class Niuniuauto extends Model
{
    protected $name = 'chat_niuniu_auto_task';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    public function getStatusList()
    {
        return ['normal' => '启用', 'hidden' => '停用'];
    }

    public function getActorModeList()
    {
        return [
            '1' => '模式一：UID池',
            '2' => '模式二：机器人账户',
        ];
    }
}
