<?php

namespace app\admin\model\fanshub;

use think\Model;

class Contactremark extends Model
{
    protected $name = 'chat_user_remarks';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    public function owner()
    {
        return $this->belongsTo('app\admin\model\User', 'user_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }

    public function peer()
    {
        return $this->belongsTo('app\admin\model\User', 'peer_user_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }
}
