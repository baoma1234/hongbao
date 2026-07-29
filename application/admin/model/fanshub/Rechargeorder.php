<?php

namespace app\admin\model\fanshub;

use think\Model;

class Rechargeorder extends Model
{
    protected $name = 'fans_recharge_order';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    public function getStatusList()
    {
        return [
            'pending'   => '待支付',
            'paid'      => '已到账',
            'failed'    => '失败',
            'cancelled' => '已取消',
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
