<?php

namespace app\admin\model\fanshub;

use think\Model;

class Welfareclaim extends Model
{
    protected $name = 'fans_welfare_rp_daily';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
}
