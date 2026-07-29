<?php

namespace app\admin\model\fanshub;

use think\Model;

class Secret extends Model
{
    protected $name = 'fans_secret';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    public function getStatusList()
    {
        return [
            'pending'   => '待联系',
            'contacted' => '已联系',
            'completed' => '已完成',
            'expired'   => '已过期',
        ];
    }

    public function user()
    {
        return $this->belongsTo('app\admin\model\User', 'user_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }
}
