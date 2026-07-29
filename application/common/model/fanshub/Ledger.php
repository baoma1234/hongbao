<?php

namespace app\common\model\fanshub;

use think\Model;

class Ledger extends Model
{
    protected $name = 'fans_ledger';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = false;

    public function user()
    {
        return $this->belongsTo('app\common\model\User', 'user_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }
}
