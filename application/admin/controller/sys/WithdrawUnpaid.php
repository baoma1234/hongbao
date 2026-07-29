<?php

namespace app\admin\controller\sys;

use app\common\controller\Backend;
use app\common\library\finance\FinanceConfig;
use app\common\library\Platform;
use app\common\library\WithdrawUrge;

/**
 * 未支付提现订单
 *
 * @icon fa fa-money
 */
class WithdrawUnpaid extends Backend
{
    protected $model = null;
    protected $searchFields = 'withdraw_unpaid.order_no,withdraw_unpaid.username,withdraw_unpaid.merchAgentId,merch_channel.channelName';
    protected $relationSearch = true;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\sys\WithdrawUnpaid;
        $this->view->assign('payStatusList', $this->model->getPayStatusList());
        $this->view->assign('pidList', Platform::getList());
        $this->assignconfig('platformList', Platform::getList());
    }

    public function index()
    {
        if ($this->request->isAjax()) {
            list($where, $sort, $order, $offset, $limit) = $this->buildparams(null, true);
            $list = $this->model
                ->alias('withdraw_unpaid')
                ->join(
                    'sys_merch_channel merch_channel',
                    'withdraw_unpaid.pid = merch_channel.pid AND withdraw_unpaid.merchAgentId = merch_channel.id',
                    'LEFT'
                )
                ->field('withdraw_unpaid.*, merch_channel.channelName as merch_channel_name')
                ->where($where)
                ->order($sort, $order)
                ->paginate($limit);

            return json(['total' => $list->total(), 'rows' => $list->items()]);
        }

        return $this->view->fetch();
    }

    /**
     * 手动催单
     */
    public function urge($ids = null)
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }

        $id = $ids ?: $this->request->post('ids');
        if (!$id) {
            $this->error('参数错误');
        }

        $row = $this->model->get($id);
        if (!$row) {
            $this->error('订单不存在');
        }

        $order = $row->toArray();
        $config = FinanceConfig::getUrgeConfig($order['pid'] ?? null);
        $result = (new WithdrawUrge($config))->manualUrge($order);

        if (empty($result['success'])) {
            $this->error($result['msg'] ?? '催单失败');
        }

        $this->success($result['msg'], null, $result);
    }
}
