<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubYxxPool;
use think\Db;

/**
 * 鱼虾蟹奖池 · 熔断开关
 *
 * @icon fa fa-shield
 */
class Yxxpool extends Backend
{
    protected $noNeedRight = [];

    public function index()
    {
        if ($this->request->isAjax()) {
            return $this->rains();
        }
        $this->view->assign('dash', FansHubYxxPool::dashboard());
        $this->view->assign('statusLabels', FansHubYxxPool::statusLabels());
        return $this->view->fetch();
    }

    /**
     * 切换熔断状态（需谷歌验证码）
     */
    public function setstatus()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        try {
            $this->auth->assertGoogleCode($this->request->post('google_code', ''));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
        }
        $status = FansHubYxxPool::setStatus($this->request->post('status', ''));
        $this->success('已切换为：' . (FansHubYxxPool::statusLabels()[$status] ?? $status), [
            'status' => $status,
        ]);
    }

    public function rains()
    {
        list($where, $sort, $order, $offset, $limit) = $this->buildparams();
        $total = Db::name('fans_yxx_rain_events')->where($where)->count();
        $list = Db::name('fans_yxx_rain_events')
            ->where($where)
            ->order($sort, $order)
            ->limit($offset, $limit)
            ->select();
        return json(['total' => $total, 'rows' => $list]);
    }

    public function raindetail($ids = null)
    {
        $id = (int)($ids ?: $this->request->param('ids'));
        $row = Db::name('fans_yxx_rain_events')->where('id', $id)->find();
        if (!$row) {
            $this->error('红包雨记录不存在');
        }
        $grants = Db::name('fans_yxx_rain_grants')
            ->where('event_id', $id)
            ->order('amount desc, id asc')
            ->limit(500)
            ->select();
        if (!is_array($grants)) {
            $grants = $grants ? $grants->toArray() : [];
        }
        $uids = [];
        foreach ($grants as $g) {
            $uid = (int)($g['user_id'] ?? 0);
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
        foreach ($grants as &$g) {
            $uid = (int)($g['user_id'] ?? 0);
            $g['nickname'] = $nickMap[$uid] ?? ('UID ' . $uid);
            $g['time_text'] = !empty($g['createtime']) ? date('Y-m-d H:i:s', (int)$g['createtime']) : '-';
        }
        unset($g);
        $row['time_text'] = !empty($row['createtime']) ? date('Y-m-d H:i:s', (int)$row['createtime']) : '-';
        $this->view->assign('row', $row);
        $this->view->assign('grants', $grants);
        return $this->view->fetch();
    }
}
