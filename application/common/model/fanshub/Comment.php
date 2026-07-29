<?php

namespace app\common\model\fanshub;

use think\Model;

class Comment extends Model
{
    protected $name = 'fans_comment';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    public function user()
    {
        return $this->belongsTo('app\common\model\User', 'user_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }
}
