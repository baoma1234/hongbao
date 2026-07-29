<?php

namespace app\admin\validate\sys;

use think\Validate;

class UrgeSchedule extends Validate
{
    protected $rule = [
        'pid'     => 'require|number',
        'sort'    => 'require|number',
        'minutes' => 'require|number',
        'type'    => 'require|in:fixed,repeat',
    ];

    protected $scene = [
        'add'  => ['pid', 'sort', 'minutes', 'type'],
        'edit' => ['pid', 'sort', 'minutes', 'type'],
    ];
}
