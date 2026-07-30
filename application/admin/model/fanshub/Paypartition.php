<?php

namespace app\admin\model\fanshub;

use think\Model;

class Paypartition extends Model
{
    protected $name = 'fans_pay_partition';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    public function getTypeList()
    {
        return ['recharge' => '充值', 'withdraw' => '提现'];
    }

    public function getStatusList()
    {
        return ['normal' => '显示', 'hidden' => '隐藏'];
    }

    public function getBindModeList()
    {
        return [
            'conventional' => '常规绑定(支付宝/银行卡)',
            'wallet'       => '钱包地址绑定',
            'none'         => '无绑定',
        ];
    }

    public function getCodeList()
    {
        return [
            'self_service' => '自助充提',
            'wallet'       => '钱包地址',
        ];
    }
}
