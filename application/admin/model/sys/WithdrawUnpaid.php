<?php

namespace app\admin\model\sys;

use app\common\library\Platform;
use think\Model;

class WithdrawUnpaid extends Model
{
    protected $name = 'sys_withdraw_unpaid';

    protected $autoWriteTimestamp = false;

    protected $createTime = false;
    protected $updateTime = false;
    protected $deleteTime = false;

    protected $append = [
        'pay_status_text',
        'pid_text',
    ];

    public function getPayStatusList()
    {
        return ['0' => __('Unpaid'), '1' => __('Paid')];
    }

    public function getPayStatusTextAttr($value, $data)
    {
        $value = $value ?? ($data['pay_status'] ?? '0');
        return $this->getPayStatusList()[(string)$value] ?? '';
    }

    public function getPidTextAttr($value, $data)
    {
        return Platform::getName($data['pid'] ?? 0);
    }
}
