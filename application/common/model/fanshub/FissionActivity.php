<?php

namespace app\common\model\fanshub;

use think\Model;

class FissionActivity extends Model
{
    protected $name = 'fans_fission_activity';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    const STATUS_DRAFT = 0;
    const STATUS_RUNNING = 1;
    const STATUS_SUCCESS = 2;
    const STATUS_EXPIRED = 3;
}
