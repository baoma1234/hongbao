<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubBsGateway;
use app\common\library\FansHubWallet;

/**
 * 充值订单
 *
 * @icon fa fa-plus-circle
 */
class Rechargeorder extends Backend
{
    protected $model = null;
    protected $searchFields = 'id,order_no,user_id,remark';
    protected $relationSearch = true;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Rechargeorder;
        $this->view->assign('statusList', $this->model->getStatusList());
    }

    public function index()
    {
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $list = $this->model
                ->with(['user', 'channel'])
                ->where($where)
                ->order($sort, $order)
                ->paginate($limit);
            foreach ($list as $row) {
                if ($row->getRelation('user')) {
                    $row->getRelation('user')->visible(['id', 'mobile', 'nickname']);
                }
                if ($row->getRelation('channel')) {
                    $row->getRelation('channel')->visible(['id', 'name', 'handler']);
                }
            }
            return json(['total' => $list->total(), 'rows' => $list->items()]);
        }
        return $this->view->fetch();
    }

    /**
     * 确认到账
     */
    public function markpaid()
    {
        $ids = $this->request->post('ids');
        $id = (int)(is_array($ids) ? ($ids[0] ?? 0) : $ids);
        if ($id <= 0) {
            $this->error('参数错误');
        }
        try {
            FansHubWallet::adminMarkRechargePaid($id);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
        $this->success('已确认到账');
    }

    /**
     * 作废
     */
    public function markfailed()
    {
        $ids = $this->request->post('ids');
        $id = (int)(is_array($ids) ? ($ids[0] ?? 0) : $ids);
        $remark = trim((string)$this->request->post('remark', ''));
        if ($id <= 0) {
            $this->error('参数错误');
        }
        try {
            FansHubWallet::adminMarkRechargeFailed($id, $remark);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
        $this->success('已作废');
    }

    /**
     * BS 网关查单并同步（代收订单查询）
     */
    public function querygateway()
    {
        $ids = $this->request->post('ids');
        $id = (int)(is_array($ids) ? ($ids[0] ?? 0) : $ids);
        if ($id <= 0) {
            $this->error('参数错误');
        }
        $order = $this->model->where('id', $id)->find();
        if (!$order) {
            $this->error('订单不存在');
        }
        if ((string)$order['handler'] !== 'bs') {
            $this->error('仅支持 BS 通道订单查单');
        }
        try {
            $result = FansHubBsGateway::syncRechargeFromQuery((int)$order['channel_id'], (string)$order['order_no']);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
        $labels = [
            'paid'      => '查询成功，订单已到账并入账',
            'failed'    => '查询成功，订单已标记失败',
            'pending'   => '查询成功，订单处理中',
            'unchanged' => '订单状态无变化',
        ];
        $this->success($labels[$result] ?? ('同步结果：' . $result));
    }
}
