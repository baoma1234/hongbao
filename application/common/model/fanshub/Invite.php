<?php

namespace app\common\model\fanshub;

use think\Model;

class Invite extends Model
{
    protected $name = 'fans_invite';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = false;

    public function inviter()
    {
        return $this->belongsTo('app\common\model\User', 'inviter_user_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }

    public function invitee()
    {
        return $this->belongsTo('app\common\model\User', 'invitee_user_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }
}
