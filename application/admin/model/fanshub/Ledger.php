<?php

namespace app\admin\model\fanshub;

use think\Model;

class Ledger extends Model
{
    protected $name = 'fans_ledger';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = false;

    public function getTypeList()
    {
        return [
            'register'      => '注册赠送',
            'share'         => '分享奖励',
            'invite'        => '邀请奖励',
            'open_account'  => '开户奖励',
            'exchange'      => '闪兑',
            'admin_adjust'  => '人工调整',
            'checkin'       => '星火签到',
            'checkin_bonus' => '暴力对账',
            'checkin_day7'  => '7天暴击',
            'honor_tier'    => '荣誉晋升',
            'register_bonus'=> '拉新股份',
        ];
    }

    public function user()
    {
        return $this->belongsTo('app\admin\model\User', 'user_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }
}
