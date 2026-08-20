<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use think\Db;

/**
 * 后台充值/提现语音提醒轮询
 */
class Payalert extends Backend
{
    protected $noNeedRight = ['*'];
    protected $layout = '';

    /**
     * 返回待提醒订单快照（前端负责：充值按 id 只播一次，提现有单持续播）
     */
    public function stats()
    {
        $rechargeIds = Db::name('fans_recharge_order')
            ->where('status', 'pending')
            ->order('id', 'desc')
            ->limit(80)
            ->column('id');
        $withdrawCount = (int)Db::name('fans_withdraw_order')
            ->where('status', 'in', ['pending', 'processing'])
            ->count();
        $withdrawIds = [];
        if ($withdrawCount > 0) {
            $withdrawIds = Db::name('fans_withdraw_order')
                ->where('status', 'in', ['pending', 'processing'])
                ->order('id', 'desc')
                ->limit(40)
                ->column('id');
        }
        $this->success('', null, [
            'recharge_ids'     => array_values(array_map('intval', (array)$rechargeIds)),
            'withdraw_pending' => $withdrawCount,
            'withdraw_ids'     => array_values(array_map('intval', (array)$withdrawIds)),
            'ts'               => time(),
        ]);
    }
}
