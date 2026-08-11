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
}
