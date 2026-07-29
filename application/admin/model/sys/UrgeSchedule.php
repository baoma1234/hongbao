<?php

namespace app\admin\model\sys;

use app\common\library\Platform;
use think\Model;

class UrgeSchedule extends Model
{
    protected $name = 'sys_urge_schedule';

    protected $autoWriteTimestamp = 'integer';

    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $deleteTime = false;

    protected $append = [
        'status_text',
        'type_text',
        'pid_text',
    ];

    public function getStatusList()
    {
        return ['normal' => __('Normal'), 'hidden' => __('Hidden')];
    }

    public function getTypeList()
    {
        return ['fixed' => __('Fixed'), 'repeat' => __('Repeat')];
    }

    public function getStatusTextAttr($value, $data)
    {
        $value = $value ?: ($data['status'] ?? '');
        return $this->getStatusList()[$value] ?? '';
    }

    public function getTypeTextAttr($value, $data)
    {
        $value = $value ?: ($data['type'] ?? '');
        return $this->getTypeList()[$value] ?? '';
    }

    public function getPidTextAttr($value, $data)
    {
        return Platform::getName($data['pid'] ?? 0);
    }
}
