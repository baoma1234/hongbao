<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubService;
use think\Db;

/**
 * 机器人账户（会员运营）
 *
 * @icon fa fa-android
 */
class Robotaccount extends Backend
{
    protected $model = null;
    protected $relationSearch = true;
    protected $searchFields = 'id,user.nickname,user.mobile';

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Account;
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
            if ($sort === '' || $sort === null || $sort === 'id') {
                $sort = 'createtime';
                $order = $order ?: 'desc';
            }
            $list = $this->model
                ->with(['user'])
                ->where($where)
                ->where('is_bot', 1)
                ->order($sort, $order)
                ->paginate($limit);
            foreach ($list as $row) {
                if ($row->getRelation('user')) {
                    $row->getRelation('user')->visible(['id', 'mobile', 'nickname', 'username', 'jointime']);
                }
                $u = $row->user;
                $nick = $u ? trim((string)($u->nickname ?: $u->username ?: '')) : '';
                $row->nickname = $nick !== '' ? $nick : ('ID' . (int)$row->user_id);
            }
            return json(['total' => $list->total(), 'rows' => $list->items()]);
        }
        return $this->view->fetch();
    }

    /**
     * 批量加红宝（勾选 ids 或自定义逗号 ID 列表）
     */
    public function batchadjust()
    {
        if (!$this->request->isPost()) {
            $ids = (string)$this->request->get('ids', '');
            $this->view->assign('ids', $ids);
            return $this->view->fetch();
        }
        $idsRaw = (string)$this->request->post('ids', '');
        $idsRaw = str_replace(["\xef\xbc\x8c", '、', '|', "\n", "\r", ' '], ',', $idsRaw);
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $idsRaw)))));
        $hongbaoDelta = round((float)$this->request->post('hongbao_delta', 0), 2);
        $remark = trim((string)$this->request->post('remark', '机器人批量加余额'));
        if (!$ids) {
            $this->error('请选择或填写会员ID');
        }
        if (abs($hongbaoDelta) < 1e-8) {
            $this->error('请填写红宝调整数值');
        }
        if ($remark === '') {
            $remark = '机器人批量加余额';
        }

        $ok = 0;
        $fail = [];
        foreach ($ids as $uid) {
            $acc = $this->model->where('user_id', $uid)->where('is_bot', 1)->find();
            if (!$acc) {
                $fail[] = $uid . '(非机器人或不存在)';
                continue;
            }
            try {
                FansHubService::changeAssets($uid, 0, 0, 'admin_adjust', $remark, $this->auth->id, '', $hongbaoDelta);
                $ok++;
            } catch (\Throwable $e) {
                $fail[] = $uid . '(' . $e->getMessage() . ')';
            }
        }
        $msg = "成功 {$ok} 个";
        if ($fail) {
            $msg .= '；失败 ' . count($fail) . '：' . implode('、', array_slice($fail, 0, 8));
            if (count($fail) > 8) {
                $msg .= '…';
            }
        }
        if ($ok <= 0) {
            $this->error($msg);
        }
        $this->success($msg);
    }

    /**
     * 注册一批机器人（默认 300，手机号从 10000000001 起，红宝 10 万）
     */
    public function seed()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        $count = (int)$this->request->post('count', 300);
        $start = trim((string)$this->request->post('start_mobile', '10000000001'));
        $hongbao = (float)$this->request->post('hongbao', 100000);
        if ($start === '') {
            $start = '10000000001';
        }
        try {
            $ret = FansHubService::seedBotUsers($count, $start, $hongbao);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
        $n = count($ret['created']);
        $s = count($ret['skipped']);
        $e = count($ret['errors']);
        $ids = [];
        foreach ($ret['created'] as $row) {
            $ids[] = (int)$row['user_id'];
        }
        $this->success(
            "新建 {$n}，跳过已存在 {$s}，失败 {$e}",
            null,
            [
                'created' => $n,
                'skipped' => $s,
                'errors'  => $e,
                'ids'     => implode(',', $ids),
                'error_detail' => array_slice($ret['errors'], 0, 10),
            ]
        );
    }

    public function adjust($ids = null)
    {
        $row = $this->model->where('id', (int)$ids)->where('is_bot', 1)->find();
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        if ($this->request->isPost()) {
            $hongbaoDelta = (float)$this->request->post('hongbao_delta', 0);
            $remark = trim((string)$this->request->post('remark', '机器人调账'));
            if (abs($hongbaoDelta) < 1e-8) {
                $this->error('请填写红宝调整数值');
            }
            try {
                FansHubService::changeAssets($row->user_id, 0, 0, 'admin_adjust', $remark ?: '机器人调账', $this->auth->id, '', $hongbaoDelta);
            } catch (\Throwable $e) {
                $this->error($e->getMessage());
            }
            $this->success('调账成功');
        }
        $user = \app\common\model\User::get($row->user_id);
        $this->view->assign('row', $row);
        $this->view->assign('user', $user);
        return $this->view->fetch();
    }
}
