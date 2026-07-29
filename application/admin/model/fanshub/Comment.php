<?php

namespace app\admin\model\fanshub;

use think\Model;

class Comment extends Model
{
    protected $name = 'fans_comment';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    public function getStatusList()
    {
        return [
            'pending'  => '待审核',
            'approved' => '已通过',
            'rejected' => '已拒绝',
        ];
    }

    public function user()
    {
        return $this->belongsTo('app\admin\model\User', 'user_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }
}
