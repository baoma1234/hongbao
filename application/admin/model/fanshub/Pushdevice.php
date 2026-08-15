<?php

namespace app\admin\model\fanshub;

use think\Model;

class Pushdevice extends Model
{
    protected $name = 'chat_push_devices';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    public function getPlatformList()
    {
        return ['ios' => 'iOS', 'android' => 'Android', '' => '未知'];
    }
}
