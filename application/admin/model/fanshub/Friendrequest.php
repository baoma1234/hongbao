<?php

namespace app\admin\model\fanshub;

use think\Model;

class Friendrequest extends Model
{
    protected $name = 'chat_friend_requests';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    public function getStatusList()
    {
        return [
            '0' => '待处理',
            '1' => '已通过',
            '2' => '已拒绝',
            '3' => '已取消',
        ];
    }

    public function fromuser()
    {
        return $this->belongsTo('app\admin\model\User', 'from_user_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }

    public function touser()
    {
        return $this->belongsTo('app\admin\model\User', 'to_user_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }
}
