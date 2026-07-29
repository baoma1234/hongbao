<?php

namespace app\common\model\fanshub;

use think\Model;

class Idempotent extends Model
{
    protected $name = 'fans_idempotent';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = false;
}
