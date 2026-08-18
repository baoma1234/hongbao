<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubBsGateway;
use app\common\library\FansHubWallet;
/**
 * 提现订单
 *
 * @icon fa fa-minus-circle
 */
class Withdraworder extends Backend
{
    protected $model = null;
    protected $searchFields = 'id,order_no,user_id,remark';
    protected $relationSearch = true;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Withdraworder;
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
                $info = $row['account_info'];
                if (is_string($info) && $info !== '') {
                    $decoded = json_decode($info, true);
                    if (is_array($decoded)) {
                        if (($decoded['method'] ?? '') === 'online_coop' || ($decoded['withdraw_mode'] ?? '') === 'online_coop') {
                            $row['account_info_text'] = '线上合作'
                                . ' | 平台:' . (string)($decoded['platform'] ?? '')
                                . ' | 主站账号:' . (string)($decoded['main_uid'] ?? $decoded['account'] ?? '');
                        } else {
                            $row['account_info_text'] = (string)($decoded['account'] ?? json_encode($decoded, JSON_UNESCAPED_UNICODE));
                        }
                    } else {
                        $row['account_info_text'] = $info;
                    }
                } else {
                    $row['account_info_text'] = '';
                }
                $row['payout_gateway'] = FansHubWallet::withdrawHasPayoutGateway((string)$row['handler']);
                $row['payout_submitted'] = FansHubWallet::withdrawPayoutAlreadySubmitted($row->toArray());
            }
            return json(['total' => $list->total(), 'rows' => $list->items()]);
        }
        return $this->view->fetch();
    }

    /**
     * 审核通过（进入待打款）
     */
    public function approve()
    {
        $ids = $this->request->post('ids');
        $id = (int)(is_array($ids) ? ($ids[0] ?? 0) : $ids);
        if ($id <= 0) {
            $this->error('参数错误');
        }
        try {
            FansHubWallet::adminApproveWithdraw($id);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
        $this->success('已审核通过，可进行打款');
    }

    /**
     * 确认已打款
     */
    public function markpaid()
    {
        $ids = $this->request->post('ids');
        $id = (int)(is_array($ids) ? ($ids[0] ?? 0) : $ids);
        if ($id <= 0) {
            $this->error('参数错误');
        }
        try {
            $this->auth->assertGoogleCode($this->request->post('google_code', ''));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
        try {
            $result = FansHubWallet::adminMarkWithdrawPaid($id);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
        $msg = is_array($result) ? (string)($result['message'] ?? '') : '';
        $this->success($msg !== '' ? $msg : '已提交打款');
    }

    /**
     * 拒绝并退回红宝
     */
    public function reject()
    {
        $ids = $this->request->post('ids');
        $id = (int)(is_array($ids) ? ($ids[0] ?? 0) : $ids);
        $remark = trim((string)$this->request->post('remark', ''));
        if ($id <= 0) {
            $this->error('参数错误');
        }
        try {
            FansHubWallet::adminRejectWithdraw($id, $remark);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
        $this->success('已拒绝并退回红宝');
    }

    /**
     * BS 网关查单并同步（代付订单查询）
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
        $handler = strtolower(trim((string)$order['handler']));
        $result = '';
        try {
            if ($handler === 'bs') {
                $result = FansHubBsGateway::syncWithdrawFromQuery((int)$order['channel_id'], (string)$order['order_no']);
            } elseif ($handler === 'wanhuitong') {
                $result = \app\common\library\FansHubWanhuitongGateway::syncWithdrawFromQuery((int)$order['channel_id'], (string)$order['order_no']);
            } else {
                $this->error('该通道暂不支持查单');
            }
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
        $labels = [
            'paid'      => '查询成功，订单已打款',
            'failed'    => '查询成功，代付失败已退回红宝',
            'pending'   => '查询成功，订单处理中',
            'unchanged' => '订单状态无变化',
        ];
        $this->success($labels[$result] ?? ('同步结果：' . $result));
    }
}
