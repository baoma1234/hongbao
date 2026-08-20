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
        $cacheKey = 'fh:admin:payalert:stats:v1';
        try {
            $cached = \think\Cache::get($cacheKey);
            if (is_array($cached)) {
                $this->success('', null, $cached);
            }
        } catch (\Throwable $e) {
        }

        $rechargeIds = Db::name('fans_recharge_order')
            ->where('status', 'pending')
            ->order('id', 'desc')
            ->limit(40)
            ->column('id');
        // 只 count，不拉 withdraw ids（前端只用数量判断是否循环播）
        $withdrawCount = (int)Db::name('fans_withdraw_order')
            ->where('status', 'in', ['pending', 'processing'])
            ->count();

        $data = [
            'recharge_ids'     => array_values(array_map('intval', (array)$rechargeIds)),
            'withdraw_pending' => $withdrawCount,
            'withdraw_ids'     => [],
            'ts'               => time(),
        ];
        try {
            \think\Cache::set($cacheKey, $data, 4);
        } catch (\Throwable $e) {
        }
        $this->success('', null, $data);
    }
}
