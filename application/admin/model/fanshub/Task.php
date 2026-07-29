<?php

namespace app\admin\model\fanshub;

use think\Model;

class Task extends Model
{
    protected $name = 'fans_task';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = false;

    public function getTaskTypeList()
    {
        return [
            'share'        => '分享奖励',
            'open_account' => '开户奖励',
            'exchange'     => '闪兑',
            'invite'       => '邀请奖励',
        ];
    }

    public function user()
    {
        return $this->belongsTo('app\admin\model\User', 'user_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }
}
