<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use think\Db;

/**
 * 尾数牛牛对局列表
 *
 * @icon fa fa-list
 */
class Niuniu extends Backend
{
    protected $noNeedRight = [];

    public function index()
    {
        if ($this->request->isAjax()) {
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $total = Db::name('chat_niuniu_rounds')->where($where)->count();
            $list = Db::name('chat_niuniu_rounds')
                ->where($where)
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();
            return json(['total' => $total, 'rows' => $list]);
        }
        return $this->view->fetch();
    }

    public function detail($ids = null)
    {
        $id = (int)($ids ?: $this->request->param('ids'));
        $row = Db::name('chat_niuniu_rounds')->where('id', $id)->find();
        if (!$row) {
            $this->error('对局不存在');
        }

        $statusMap = [
            1 => '购入中',
            2 => '领取中',
            3 => '已结算',
            4 => '作废',
            5 => '流局退回',
        ];
        $tierMap = [
            3 => '牛牛',
            2 => '次级(牛7-9)',
            1 => '低流(牛1-6)',
            0 => '-',
        ];
        $mode = (int)($row['game_mode'] ?? 1);
        $row['status_label'] = $statusMap[(int)($row['status'] ?? 0)] ?? ('状态' . ($row['status'] ?? ''));
        $row['game_mode_label'] = $mode === 2 ? '单结果' : '多包';
        $row['tron_block'] = (string)($row['tron_block_num'] ?? $row['drand_round'] ?? '');
        $row['tron_hash'] = (string)($row['tron_block_id'] ?? $row['drand_randomness'] ?? '');

        // 按真实领取时间；未领排后
        $shares = Db::name('chat_niuniu_shares')
            ->where('round_id', $id)
            ->orderRaw('CASE WHEN claimed=1 THEN 0 ELSE 1 END ASC, claimed_at ASC, claim_seq ASC, id ASC')
            ->select();
        if (!is_array($shares)) {
            $shares = $shares ? $shares->toArray() : [];
        }

        $uids = [];
        foreach ($shares as $s) {
            $uid = (int)($s['user_id'] ?? 0);
            if ($uid > 0) {
                $uids[$uid] = true;
            }
        }
        $nickMap = [];
        if ($uids) {
            $users = Db::name('user')->where('id', 'in', array_keys($uids))->column('nickname', 'id');
            if (is_array($users)) {
                $nickMap = $users;
            }
        }

        $claimedCount = 0;
        $rows = [];
        foreach ($shares as $s) {
            $claimed = (int)($s['claimed'] ?? 0) === 1;
            if ($claimed) {
                $claimedCount++;
            }
            $uid = (int)($s['user_id'] ?? 0);
            $tail = trim((string)($s['tail_digits'] ?? ''));
            $claimedAt = (int)($s['claimed_at'] ?? 0);
            $rows[] = [
                'id'            => (int)($s['id'] ?? 0),
                'share_no'      => (int)($s['share_no'] ?? 0),
                'user_id'       => $uid,
                'nickname'      => $nickMap[$uid] ?? ('用户' . $uid),
                'claim_seq'     => (int)($s['claim_seq'] ?? 0),
                'claimed'       => $claimed,
                'claimed_label' => $claimed ? '已领取' : '未领取',
                'claimed_at'    => $claimedAt,
                'claimed_at_text' => $claimedAt > 0 ? date('Y-m-d H:i:s', $claimedAt) : '-',
                'tail_digits'   => $claimed && $tail !== '' ? $tail : '-',
                'niu_label'     => $claimed && $tail !== '' ? (string)($s['niu_label'] ?? '-') : '未领取',
                'niu_tier'      => (int)($s['niu_tier'] ?? 0),
                'tier_label'    => $claimed && $tail !== ''
                    ? ($tierMap[(int)($s['niu_tier'] ?? 0)] ?? '-')
                    : '-',
                'amount'        => $claimed && $tail !== ''
                    ? sprintf('%.2f', round((float)($s['amount'] ?? 0), 2))
                    : '-',
                'win_amount'    => sprintf('%.4f', round((float)($s['win_amount'] ?? 0), 4)),
                'packet_paid'   => (int)($s['packet_paid'] ?? 0) === 1 ? '已入账' : '-',
            ];
        }

        $this->view->assign('row', $row);
        $this->view->assign('shares', $rows);
        $this->view->assign('share_total', count($rows));
        $this->view->assign('claimed_count', $claimedCount);
        return $this->view->fetch();
    }
}
