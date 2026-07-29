<?php

namespace app\common\model\fanshub;

use think\Model;

class LoginLog extends Model
{
    protected $name = 'fans_login_log';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = false;

    public function user()
    {
        return $this->belongsTo('app\common\model\User', 'user_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }
}
