<?php

namespace app\admin\model\fanshub;

use think\Model;

class Withdraworder extends Model
{
    protected $name = 'fans_withdraw_order';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    public function getStatusList()
    {
        return [
            'pending'    => '待处理',
            'processing' => '处理中',
            'paid'       => '已打款',
            'rejected'   => '已拒绝',
            'cancelled'  => '已取消',
        ];
    }

    public function user()
    {
        return $this->belongsTo('app\admin\model\User', 'user_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }

    public function channel()
    {
        return $this->belongsTo('app\admin\model\fanshub\Paychannel', 'channel_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }
}
