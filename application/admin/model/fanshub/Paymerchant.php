<?php

namespace app\admin\model\fanshub;

use think\Model;

class Paymerchant extends Model
{
    protected $name = 'fans_pay_merchant';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    public function getStatusList()
    {
        return ['normal' => '启用', 'hidden' => '停用'];
    }

    public function getGatewayList()
    {
        return [
            'wanhuitong' => '万汇通 wanhuipay',
            'bs'         => 'BS 必胜 USDT',
        ];
    }
}
