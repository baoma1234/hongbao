<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubImBridge;
use app\common\library\FansHubRedPacket;
use think\Db;

/**
 * 红包结算与对账
 *
 * @icon fa fa-balance-scale
 */
class Redpacketsettle extends Backend
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
            if (!empty($filterArr['settle_type'])) {
                $where['settle_type'] = $filterArr['settle_type'];
            }
            if (isset($filterArr['status']) && $filterArr['status'] !== '') {
                $where['status'] = (int)$filterArr['status'];
            }
            if (!empty($filterArr['packet_no'])) {
                $where['packet_no'] = ['like', '%' . $filterArr['packet_no'] . '%'];
            }
            if (!empty($filterArr['packet_id'])) {
                $where['packet_id'] = (int)$filterArr['packet_id'];
            }

            $allowSort = ['id', 'createtime', 'amount'];
            if (!in_array($sort, $allowSort, true)) {
                $sort = 'id';
            }
            $order = strtolower($order) === 'asc' ? 'asc' : 'desc';

            $total = Db::name('chat_red_packet_settlements')->where($where)->count();
            $list = Db::name('chat_red_packet_settlements')
                ->where($where)
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();
            $types = FansHubRedPacket::settleTypeList();
            foreach ($list as &$row) {
                $row['type_text'] = $types[$row['settle_type']] ?? $row['settle_type'];
                $row['from_label'] = FansHubRedPacket::userLabel((int)$row['from_user_id']);
                $row['to_label'] = FansHubRedPacket::userLabel((int)$row['to_user_id']);
            }
            unset($row);
            return json(['total' => $total, 'rows' => $list]);
        }
        $this->view->assign('settleTypeList', FansHubRedPacket::settleTypeList());
        $this->view->assign('summary', $this->buildSummary());
        return $this->view->fetch();
    }

    /**
     * 汇总：手续费 / 返点 / 赔付
     */
    public function summary()
    {
        $this->success('ok', null, $this->buildSummary());
    }

    protected function buildSummary()
    {
        $types = ['platform_fee', 'agent_rebate', 'compensate', 'refund'];
        $out = [];
        foreach ($types as $t) {
            $row = Db::name('chat_red_packet_settlements')
                ->where(['settle_type' => $t, 'status' => 1])
                ->field('COUNT(*) AS cnt, IFNULL(SUM(amount),0) AS amount')
                ->find();
            $out[$t] = [
                'count'  => (int)($row['cnt'] ?? 0),
                'amount' => round((float)($row['amount'] ?? 0), 2),
            ];
        }
        $failCompensate = (int)Db::name('chat_red_packets')
            ->where('compensate_status', 3)
            ->count();
        $pendingSettle = (int)Db::name('chat_red_packets')
            ->where('status', 2)
            ->count();
        $out['fail_compensate_packets'] = $failCompensate;
        $out['pending_settle_packets'] = $pendingSettle;
        return $out;
    }

    /**
     * 批量重试：已抢完未结算的包
     */
    public function retrybatch()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        $ids = Db::name('chat_red_packets')
            ->where('status', 2)
            ->order('id asc')
            ->limit(20)
            ->column('id');
        if (!$ids) {
            $this->success('没有待结算红包');
        }
        $ok = 0;
        $errors = [];
        foreach ($ids as $id) {
            try {
                FansHubImBridge::post('/agent/settle_packet', [
                    'packet_id' => (int)$id,
                    'admin_id'  => (int)$this->auth->id,
                ]);
                $ok++;
            } catch (\Throwable $e) {
                $errors[] = '#' . $id . ' ' . $e->getMessage();
            }
        }
        $this->success("成功 {$ok} 单", null, ['ok' => $ok, 'errors' => $errors]);
    }
}
