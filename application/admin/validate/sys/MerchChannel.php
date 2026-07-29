<?php

namespace app\admin\validate\sys;

use think\Validate;

class MerchChannel extends Validate
{
    protected $rule = [
        'merchCode' => 'require',
        'pid'       => 'require|number',
    ];

    protected $message = [
        'merchCode.require' => '商户编码不能为空',
        'pid.require'       => '平台ID不能为空',
    ];

    protected $scene = [
        'add'  => ['merchCode', 'pid'],
        'edit' => ['merchCode', 'pid'],
    ];
}
