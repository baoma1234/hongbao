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

    /**
     * 从筛选条件里抽取参与用户ID，并剔除虚拟字段，避免 buildparams 查不存在的列
     *
     * @return int|null null=未筛该项；0=无有效用户ID；>0=参与用户ID
     */
    protected function pullParticipantUserId()
    {
        $filterRaw = $this->request->get('filter', '');
        $opRaw = $this->request->get('op', '');
        $filterArr = is_string($filterRaw) ? (json_decode($filterRaw, true) ?: []) : (is_array($filterRaw) ? $filterRaw : []);
        $opArr = is_string($opRaw) ? (json_decode($opRaw, true) ?: []) : (is_array($opRaw) ? $opRaw : []);

        $hasUserId = array_key_exists('participant_user_id', $filterArr);
        $userId = $hasUserId ? (int)$filterArr['participant_user_id'] : 0;
        unset($filterArr['participant_user_id'], $opArr['participant_user_id']);
        $this->request->get([
            'filter' => json_encode($filterArr, JSON_UNESCAPED_UNICODE),
            'op'     => json_encode($opArr, JSON_UNESCAPED_UNICODE),
        ]);

        if (!$hasUserId) {
            return null;
        }
        return $userId > 0 ? $userId : 0;
    }

    public function index()
    {
        if ($this->request->isAjax()) {
            $participantUserId = $this->pullParticipantUserId();
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $query = Db::name('chat_niuniu_rounds');
            if ($where) {
                $query->where($where);
            }
            if ($participantUserId !== null) {
                if ($participantUserId <= 0) {
                    $query->where('id', 0);
                } else {
                    $roundIds = Db::name('chat_niuniu_shares')
                        ->where('user_id', $participantUserId)
                        ->distinct(true)
                        ->column('round_id');
                    $roundIds = array_values(array_unique(array_filter(array_map('intval', $roundIds ?: []))));
                    $query->where('id', 'in', $roundIds ?: [0]);
                }
            }
            $total = (clone $query)->count();
            $list = $query
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
            $winAmt = round((float)($s['win_amount'] ?? 0), 4);
            $pktAmt = ($claimed && $tail !== '') ? round((float)($s['amount'] ?? 0), 2) : null;
            $rows[] = [
                'id'            => (int)($s['id'] ?? 0),
                'share_no'      => (int)($s['share_no'] ?? 0),
                'share_count'   => 1,
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
                'amount'        => $pktAmt !== null ? sprintf('%.2f', $pktAmt) : '-',
                'amount_raw'    => $pktAmt,
                'win_amount'    => $winAmt,
                'win_amount_text' => $winAmt > 0 ? sprintf('%.4f', $winAmt) : '0',
                'packet_paid'   => (int)($s['packet_paid'] ?? 0) === 1 ? '已入账' : '-',
            ];
        }

        // 单结果：与前台领取明细一致，同一用户只展示一行并合并奖金
        if ($mode === 2 && $rows) {
            $merged = [];
            $order = [];
            foreach ($rows as $r) {
                $uid = (int)$r['user_id'];
                if (!isset($merged[$uid])) {
                    $merged[$uid] = $r;
                    $order[] = $uid;
                    continue;
                }
                $g = &$merged[$uid];
                $g['share_count'] = ((int)$g['share_count']) + 1;
                $g['win_amount'] = round((float)$g['win_amount'] + (float)$r['win_amount'], 4);
                if ((int)$r['claim_seq'] > 0 && ((int)$g['claim_seq'] <= 0 || (int)$r['claim_seq'] < (int)$g['claim_seq'])) {
                    $g['claim_seq'] = (int)$r['claim_seq'];
                }
                if ((int)$r['claimed_at'] > 0 && ((int)$g['claimed_at'] <= 0 || (int)$r['claimed_at'] < (int)$g['claimed_at'])) {
                    $g['claimed_at'] = (int)$r['claimed_at'];
                    $g['claimed_at_text'] = $r['claimed_at_text'];
                }
                if ($r['claimed']) {
                    $g['claimed'] = true;
                    $g['claimed_label'] = '已领取';
                    if ($g['tail_digits'] === '-' && $r['tail_digits'] !== '-') {
                        $g['tail_digits'] = $r['tail_digits'];
                        $g['niu_label'] = $r['niu_label'];
                        $g['niu_tier'] = $r['niu_tier'];
                        $g['tier_label'] = $r['tier_label'];
                        $g['amount'] = $r['amount'];
                        $g['amount_raw'] = $r['amount_raw'];
                    }
                }
                if ($r['packet_paid'] === '已入账') {
                    $g['packet_paid'] = '已入账';
                }
                unset($g);
            }
            $rows = [];
            foreach ($order as $uid) {
                $g = $merged[$uid];
                $n = max(1, (int)$g['share_count']);
                $win = round((float)$g['win_amount'], 4);
                $g['win_amount'] = $win;
                $g['win_amount_text'] = $win > 0 ? sprintf('%.4f', $win) : '0';
                $g['share_no_text'] = $n > 1 ? ('×' . $n . '份') : (string)(int)$g['share_no'];
                if ($g['amount_raw'] !== null && $n > 1) {
                    $g['amount'] = sprintf('%.2f', $g['amount_raw']) . '/' . $n;
                }
                $rows[] = $g;
            }
        } else {
            foreach ($rows as &$r) {
                $r['share_no_text'] = (string)(int)$r['share_no'];
            }
            unset($r);
        }

        $this->view->assign('row', $row);
        $this->view->assign('shares', $rows);
        $this->view->assign('share_total', count($shares));
        $this->view->assign('person_total', count($rows));
        $this->view->assign('claimed_count', $claimedCount);
        $this->view->assign('is_single_mode', $mode === 2);
        return $this->view->fetch();
    }
}
