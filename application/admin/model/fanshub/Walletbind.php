<?php

namespace app\admin\model\fanshub;

use think\Model;

class Walletbind extends Model
{
    protected $name = 'fans_wallet_bind';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    public function getBindModeList()
    {
        return [
            'bank'         => '银行卡',
            'alipay'       => '支付宝',
            'wechat'       => '微信',
            'wallet'       => '数字钱包/地址',
            'conventional' => '常规',
        ];
    }

    public function getWalletTypeList()
    {
        return [
            'BANK'   => '银行卡 (BANK)',
            'ALIPAY' => '支付宝 (ALIPAY)',
            'WECHAT' => '微信 (WECHAT)',
        ];
    }

    public function user()
    {
        return $this->belongsTo('app\admin\model\User', 'user_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }
}
