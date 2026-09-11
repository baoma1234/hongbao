<?php

namespace app\admin\model\fanshub;

use think\Model;

class Appupdate extends Model
{
    protected $name = 'fanshub_app_update';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    public function getPlatformList()
    {
        return ['android' => '安卓', 'ios' => 'iOS'];
    }

    public function getStatusList()
    {
        return ['normal' => '正常', 'hidden' => '隐藏'];
    }

    public function getForceUpdateList()
    {
        return ['0' => '否', '1' => '是'];
    }

    public function getIsCurrentList()
    {
        return ['0' => '否', '1' => '当前'];
    }
}
