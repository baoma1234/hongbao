<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubImBridge;
use app\common\library\FansHubRedPacket;
use think\Db;

/**
 * 红包订单中心 + 运营工具
 *
 * @icon fa fa-gift
 */
class Redpacket extends Backend
{
    protected $noNeedRight = [];

    public function index()
    {
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            $sort = $this->request->get('sort', 'id');
            $order = $this->request->get('order', 'desc');
            $offset = (int)$this->request->get('offset', 0);
            $limit = max(1, min(100, (int)$this->request->get('limit', 20)));
            $filter = $this->request->get('filter', '');
            $filterArr = $filter ? (array)json_decode($filter, true) : [];

            $where = [];
            if (isset($filterArr['status']) && $filterArr['status'] !== '') {
                $where['status'] = (int)$filterArr['status'];
            }
            if (isset($filterArr['packet_type']) && $filterArr['packet_type'] !== '') {
                $where['packet_type'] = (int)$filterArr['packet_type'];
            }
            if (!empty($filterArr['from_user_id'])) {
                $where['from_user_id'] = (int)$filterArr['from_user_id'];
            }
            if (!empty($filterArr['packet_no'])) {
                $where['packet_no'] = ['like', '%' . $filterArr['packet_no'] . '%'];
            }
            if (!empty($filterArr['group_id'])) {
                $where['group_id'] = (int)$filterArr['group_id'];
            }

            $allowSort = ['id', 'createtime', 'total_amount', 'status', 'expiretime'];
            if (!in_array($sort, $allowSort, true)) {
                $sort = 'id';
            }
            $order = strtolower($order) === 'asc' ? 'asc' : 'desc';

            $total = Db::name('chat_red_packets')->where($where)->count();
            $list = Db::name('chat_red_packets')
                ->where($where)
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();
            $statusList = FansHubRedPacket::statusList();
            $typeList = FansHubRedPacket::typeList();
            foreach ($list as &$row) {
                $row['from_label'] = FansHubRedPacket::userLabel((int)$row['from_user_id']);
                $row['status_text'] = $statusList[(int)$row['status']] ?? (string)$row['status'];
                $row['type_text'] = $typeList[(int)$row['packet_type']] ?? (string)$row['packet_type'];
                $row['grabbed'] = (int)$row['total_count'] - (int)$row['remain_count'];
            }
            unset($row);
            return json(['total' => $total, 'rows' => $list]);
        }
        $this->view->assign('statusList', FansHubRedPacket::statusList());
        $this->view->assign('typeList', FansHubRedPacket::typeList());
        return $this->view->fetch();
    }

    public function detail($ids = null)
    {
        $id = (int)($ids ?: $this->request->param('ids'));
        $row = Db::name('chat_red_packets')->where('id', $id)->find();
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        $records = Db::name('chat_red_packet_records')
            ->where('packet_id', $id)
            ->order('id asc')
            ->select();
        foreach ($records as &$r) {
            $r['user_label'] = FansHubRedPacket::userLabel((int)$r['user_id']);
        }
        unset($r);
        $settlements = Db::name('chat_red_packet_settlements')
            ->where('packet_id', $id)
            ->order('id asc')
            ->select();
        $settleTypes = FansHubRedPacket::settleTypeList();
        foreach ($settlements as &$s) {
            $s['type_text'] = $settleTypes[$s['settle_type']] ?? $s['settle_type'];
            $s['from_label'] = FansHubRedPacket::userLabel((int)$s['from_user_id']);
            $s['to_label'] = FansHubRedPacket::userLabel((int)$s['to_user_id']);
        }
        unset($s);

        $this->view->assign('row', $row);
        $this->view->assign('records', $records);
        $this->view->assign('settlements', $settlements);
        $this->view->assign('statusList', FansHubRedPacket::statusList());
        $this->view->assign('typeList', FansHubRedPacket::typeList());
        $this->view->assign('fromLabel', FansHubRedPacket::userLabel((int)$row['from_user_id']));
        return $this->view->fetch();
    }

    /**
     * 重试结算（status=2 已抢完未结算，或结算失败）
     */
    public function retrysettle()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        $id = (int)$this->request->post('ids', $this->request->post('id', 0));
        if ($id <= 0) {
            $this->error('缺少红包ID');
        }
        try {
            $result = FansHubImBridge::post('/agent/settle_packet', [
                'packet_id' => $id,
                'admin_id'  => (int)$this->auth->id,
            ]);
            $this->success('结算已触发', null, $result);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 手动触发过期退回
     */
    public function refundnow()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        $id = (int)$this->request->post('ids', $this->request->post('id', 0));
        if ($id <= 0) {
            $this->error('缺少红包ID');
        }
        try {
            $result = FansHubImBridge::post('/agent/refund_packet', [
                'packet_id' => $id,
                'admin_id'  => (int)$this->auth->id,
                'force'     => 1,
            ]);
            $this->success('已退回', null, $result);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 强制关包（不退款；慎用）
     */
    public function forceclose()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        $id = (int)$this->request->post('ids', $this->request->post('id', 0));
        if ($id <= 0) {
            $this->error('缺少红包ID');
        }
        try {
            $result = FansHubImBridge::post('/agent/close_packet', [
                'packet_id' => $id,
                'admin_id'  => (int)$this->auth->id,
            ]);
            $this->success('已关闭', null, $result);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 异常补账入口：跳到账户调账并预填备注
     */
    public function adjusthint()
    {
        $id = (int)$this->request->param('ids', 0);
        $packet = $id > 0 ? Db::name('chat_red_packets')->where('id', $id)->find() : null;
        $remark = $packet ? ('红包异常补账 ' . $packet['packet_no']) : '红包异常补账';
        $this->redirect('fanshub/account/adjust', ['remark' => $remark]);
    }
}
