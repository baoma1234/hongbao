<?php

namespace app\admin\model\fanshub;

use think\Model;

class Checkin extends Model
{
    protected $name = 'fans_checkin';
    protected $autoWriteTimestamp = false;

    public function getModeList()
    {
        return ['normal' => '普通打卡', 'violent' => '暴力分享'];
    }

    public function user()
    {
        return $this->belongsTo('app\admin\model\User', 'user_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }
}
