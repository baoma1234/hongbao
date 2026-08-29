<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubFission;
use app\common\model\fanshub\FissionActivity;
use think\Db;

/**
 * 裂变红包活动
 *
 * @icon fa fa-gift
 */
class Fission extends Backend
{
    protected $noNeedRight = ['*'];

    public function index()
    {
        $list = Db::name('fans_fission_activity')->order('id', 'desc')->limit(50)->select();
        if ($list instanceof \think\Collection || $list instanceof \think\model\Collection) {
            $list = $list->toArray();
        } elseif (!is_array($list)) {
            $list = [];
        }
        if ($this->request->isAjax() || $this->request->get('ajax')) {
            return json(['total' => count($list), 'rows' => $list]);
        }
        $this->view->assign('rows', $list);
        return $this->view->fetch();
    }

    /**
     * 开启新一轮（若已有进行中则拒绝）
     */
    public function start()
    {
        if (!$this->request->isPost()) {
            return $this->view->fetch();
        }
        try {
            $id = FansHubFission::startRound([
                'pool_amount'    => (float)$this->request->post('pool_amount', 1000),
                'global_cap'     => (int)$this->request->post('global_cap', 100),
                'user_cap'       => (int)$this->request->post('user_cap', 5),
                'duration_hours' => (int)$this->request->post('duration_hours', 72),
                'title'          => (string)$this->request->post('title', '全网裂变红宝'),
            ]);
            $this->success('已开启活动 #' . $id);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    public function maintain()
    {
        $r = FansHubFission::maintain();
        $this->success('完成', null, $r);
    }

    /**
     * 编辑活动：奖金池 / 进度 / 单人上限 / 状态 / 起止时间
     */
    public function edit($ids = null)
    {
        $id = (int)($ids ?: $this->request->param('ids'));
        $row = Db::name('fans_fission_activity')->where('id', $id)->find();
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');
            if (!is_array($params)) {
                $this->error('参数错误');
            }
            $pool = max(0.01, (float)($params['pool_amount'] ?? $row['pool_amount']));
            $globalCap = max(1, (int)($params['global_cap'] ?? $row['global_cap']));
            $userCap = max(1, (int)($params['user_cap'] ?? $row['user_cap']));
            $status = (int)($params['status'] ?? $row['status']);
            if (!in_array($status, [
                FissionActivity::STATUS_DRAFT,
                FissionActivity::STATUS_RUNNING,
                FissionActivity::STATUS_SUCCESS,
                FissionActivity::STATUS_EXPIRED,
            ], true)) {
                $this->error('状态无效');
            }
            if ($status === FissionActivity::STATUS_RUNNING) {
                $other = Db::name('fans_fission_activity')
                    ->where('status', FissionActivity::STATUS_RUNNING)
                    ->where('id', '<>', $id)
                    ->find();
                if ($other) {
                    $this->error('已有进行中的活动 #' . $other['id'] . '，请先改其状态');
                }
            }
            $startTime = $this->parseAdminTime($params['start_time'] ?? null, (int)($row['start_time'] ?? 0));
            $endTime = $this->parseAdminTime($params['end_time'] ?? null, (int)($row['end_time'] ?? 0));
            if ($startTime > 0 && $endTime > 0 && $endTime <= $startTime) {
                $this->error('结束时间必须晚于开始时间');
            }
            $data = [
                'pool_amount'  => round($pool, 2),
                'global_cap'   => $globalCap,
                'user_cap'     => $userCap,
                'status'       => $status,
                'start_time'   => $startTime,
                'end_time'     => $endTime,
                'updatetime'   => time(),
            ];
            if ($startTime > 0 && $endTime > $startTime) {
                $data['duration_hours'] = max(1, (int)ceil(($endTime - $startTime) / 3600));
            }
            if (array_key_exists('title', $params)) {
                $title = trim((string)$params['title']);
                if ($title !== '') {
                    $data['title'] = $title;
                }
            }
            Db::name('fans_fission_activity')->where('id', $id)->update($data);
            $this->success('已保存');
        }
        $this->view->assign('row', $row);
        $this->view->assign('start_time_text', !empty($row['start_time']) ? date('Y-m-d H:i:s', (int)$row['start_time']) : '');
        $this->view->assign('end_time_text', !empty($row['end_time']) ? date('Y-m-d H:i:s', (int)$row['end_time']) : '');
        $this->view->assign('statusList', [
            FissionActivity::STATUS_DRAFT   => '草稿',
            FissionActivity::STATUS_RUNNING => '进行中',
            FissionActivity::STATUS_SUCCESS => '开奖成功',
            FissionActivity::STATUS_EXPIRED => '超时作废',
        ]);
        return $this->view->fetch();
    }

    /**
     * 单独编辑进度（全局资格数）
     */
    public function progress($ids = null)
    {
        $id = (int)($ids ?: $this->request->param('ids'));
        $row = Db::name('fans_fission_activity')->where('id', $id)->find();
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');
            if (!is_array($params)) {
                $this->error('参数错误');
            }
            $globalQuals = max(0, (int)($params['global_quals'] ?? $row['global_quals']));
            $globalCap = max(1, (int)($row['global_cap'] ?? 1));
            if ($globalQuals > $globalCap) {
                $this->error('当前进度不能大于全局上限 ' . $globalCap);
            }
            Db::name('fans_fission_activity')->where('id', $id)->update([
                'global_quals' => $globalQuals,
                'updatetime'   => time(),
            ]);
            $this->success('进度已保存');
        }
        $this->view->assign('row', $row);
        return $this->view->fetch();
    }

    /**
     * 一键结算开奖：进度拉满 → 按活动上限（默认 100 份）拆池派奖
     */
    public function forcesettle($ids = null)
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $id = (int)($ids ?: $this->request->param('ids') ?: $this->request->post('ids'));
        if ($id <= 0) {
            $this->error('活动 ID 无效');
        }
        $row = Db::name('fans_fission_activity')->where('id', $id)->find();
        if (!$row) {
            $this->error('活动不存在');
        }
        if ((int)$row['status'] !== FissionActivity::STATUS_RUNNING) {
            $this->error('仅进行中的活动可一键开奖');
        }
        $ok = FansHubFission::settleSuccess($id, true);
        if (!$ok) {
            $this->error('开奖失败，请刷新后重试');
        }
        $cap = max(1, (int)$row['global_cap']);
        $qualCount = (int)Db::name('fans_fission_qual')->where('activity_id', $id)->count();
        $this->success('已按 ' . $cap . ' 份拆池开奖（实际参与 ' . $qualCount . ' 份，余份不派发）');
    }

    /**
     * 给指定用户加资格份数
     */
    public function addqual($ids = null)
    {
        $id = (int)($ids ?: $this->request->param('ids'));
        $row = null;
        if ($id > 0) {
            $row = Db::name('fans_fission_activity')->where('id', $id)->find();
        }
        if (!$row) {
            $row = Db::name('fans_fission_activity')
                ->where('status', 'in', [
                    FissionActivity::STATUS_RUNNING,
                    FissionActivity::STATUS_SUCCESS,
                ])
                ->order('id', 'desc')
                ->find();
        }
        if (!$this->request->isPost()) {
            $this->view->assign('row', $row ?: []);
            $this->view->assign('activity_id', $row ? (int)$row['id'] : 0);
            $this->view->assign('status', $row ? (int)$row['status'] : 0);
            return $this->view->fetch();
        }
        $activityId = (int)$this->request->post('activity_id', $id);
        $userKey = trim((string)$this->request->post('user_key', ''));
        $count = (int)$this->request->post('count', 1);
        $winAmount = $this->request->post('win_amount', '');
        $winAmount = ($winAmount === '' || $winAmount === null) ? null : (float)$winAmount;

        $userId = 0;
        if ($userKey !== '') {
            if (ctype_digit($userKey)) {
                $userId = (int)$userKey;
            }
            if ($userId <= 0) {
                $u = Db::name('user')->where('mobile', $userKey)->order('id', 'desc')->find();
                if ($u) {
                    $userId = (int)$u['id'];
                }
            }
        }
        if ($userId <= 0) {
            $this->error('请填写有效的用户ID或手机号');
        }
        try {
            $r = FansHubFission::adminGrantQuals($activityId, $userId, $count, $winAmount);
        } catch (\Throwable $e) {
            $this->error($e->getMessage() ?: '加份失败');
        }
        $this->success('已为用户 #' . $userId . ' 加 ' . $r['granted'] . ' 份', null, $r);
    }

    /**
     * 某一期资格 / 领取记录
     */
    public function claims($ids = null)
    {
        $id = (int)($ids ?: $this->request->param('ids'));
        $row = Db::name('fans_fission_activity')->where('id', $id)->find();
        if (!$row) {
            $this->error(__('No Results were found'));
        }

        $quals = Db::name('fans_fission_qual')
            ->alias('q')
            ->join('user u', 'u.id = q.user_id', 'LEFT')
            ->where('q.activity_id', $id)
            ->field('q.id,q.user_id,q.source,q.ref_user_id,q.win_amount,q.claimed,q.claimed_at,q.createtime,u.mobile,u.nickname')
            ->order('q.claimed desc,q.claimed_at desc,q.id asc')
            ->select();
        if ($quals instanceof \think\Collection || $quals instanceof \think\model\Collection) {
            $quals = $quals->toArray();
        } elseif (!is_array($quals)) {
            $quals = [];
        }

        $sourceMap = [
            'join'          => '参与',
            'invite_reward' => '邀请奖励',
            'invitee'       => '被邀请',
            'admin'         => '后台加份',
        ];
        $claimedCount = 0;
        $claimedAmount = 0.0;
        $unclaimedCount = 0;
        $unclaimedAmount = 0.0;
        foreach ($quals as &$q) {
            $src = (string)($q['source'] ?? '');
            $q['source_label'] = $sourceMap[$src] ?? $src;
            $win = $q['win_amount'] !== null && $q['win_amount'] !== ''
                ? round((float)$q['win_amount'], 2)
                : null;
            $q['win_amount_fmt'] = $win === null ? '-' : number_format($win, 2, '.', '');
            $isClaimed = (int)($q['claimed'] ?? 0) === 1;
            $q['claimed_label'] = $isClaimed ? '已领取' : (($win !== null && $win > 0) ? '待领取' : '未开奖');
            $q['claimed_at_text'] = !empty($q['claimed_at']) ? date('Y-m-d H:i:s', (int)$q['claimed_at']) : '-';
            $q['createtime_text'] = !empty($q['createtime']) ? date('Y-m-d H:i:s', (int)$q['createtime']) : '-';
            if ($isClaimed && $win !== null) {
                $claimedCount++;
                $claimedAmount += $win;
            } elseif ($win !== null && $win > 0) {
                $unclaimedCount++;
                $unclaimedAmount += $win;
            }
        }
        unset($q);

        $statusMap = [
            FissionActivity::STATUS_DRAFT   => '草稿',
            FissionActivity::STATUS_RUNNING => '进行中',
            FissionActivity::STATUS_SUCCESS => '开奖成功',
            FissionActivity::STATUS_EXPIRED => '超时作废',
        ];
        $this->view->assign('row', $row);
        $this->view->assign('status_label', $statusMap[(int)$row['status']] ?? (string)$row['status']);
        $this->view->assign('quals', $quals);
        $this->view->assign('summary', [
            'total'            => count($quals),
            'claimed_count'    => $claimedCount,
            'claimed_amount'   => round($claimedAmount, 2),
            'unclaimed_count'  => $unclaimedCount,
            'unclaimed_amount' => round($unclaimedAmount, 2),
        ]);
        return $this->view->fetch();
    }

    /**
     * @param mixed $raw
     * @param int   $fallback
     */
    protected function parseAdminTime($raw, $fallback = 0)
    {
        if ($raw === null || $raw === '') {
            return (int)$fallback;
        }
        if (is_numeric($raw)) {
            $n = (int)$raw;
            return $n > 0 ? $n : (int)$fallback;
        }
        $ts = strtotime(trim((string)$raw));
        return $ts !== false ? (int)$ts : (int)$fallback;
    }
}
