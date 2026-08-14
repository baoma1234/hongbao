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
        $running = Db::name('fans_fission_activity')->where('status', 1)->find();
        if ($running) {
            $this->error('已有进行中的活动 #' . $running['id']);
        }
        $pool = max(0.01, (float)$this->request->post('pool_amount', 1000));
        $globalCap = max(1, (int)$this->request->post('global_cap', 100));
        $userCap = max(1, (int)$this->request->post('user_cap', 5));
        $hours = max(1, (int)$this->request->post('duration_hours', 72));
        $title = trim((string)$this->request->post('title', '全网裂变红宝')) ?: '全网裂变红宝';
        $now = time();
        $id = Db::name('fans_fission_activity')->insertGetId([
            'title'          => $title,
            'pool_amount'    => round($pool, 2),
            'global_cap'     => $globalCap,
            'user_cap'       => $userCap,
            'duration_hours' => $hours,
            'global_quals'   => 0,
            'status'         => FissionActivity::STATUS_RUNNING,
            'start_time'     => $now,
            'end_time'       => $now + $hours * 3600,
            'settled_time'   => 0,
            'createtime'     => $now,
            'updatetime'     => $now,
        ]);
        $this->success('已开启活动 #' . $id);
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
            $globalQuals = max(0, (int)($params['global_quals'] ?? $row['global_quals']));
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
            if ($globalQuals > $globalCap) {
                $this->error('当前进度不能大于全局上限');
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
                'global_quals' => $globalQuals,
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
