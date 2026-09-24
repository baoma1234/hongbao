<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use think\Db;

/**
 * 福利群红包每日领取配额
 *
 * @icon fa fa-gift
 */
class Welfareclaim extends Backend
{
    protected $model = null;
    protected $searchFields = 'user_id,quota_date';
    protected $relationSearch = false;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Welfareclaim;
        $cfg = (array)config('fanshub.');
        $this->view->assign('welfareCfg', [
            'enabled'             => !empty($cfg['welfare_rp_quota_enabled']),
            'daily_free'          => (int)($cfg['welfare_rp_daily_free'] ?? 10),
            'entertain_per_bonus' => max(1, (int)($cfg['welfare_rp_entertain_per_bonus'] ?? 5)),
            'group_ids'           => implode(',', (array)($cfg['welfare_rp_group_ids'] ?? [80])),
        ]);
    }

    public function index()
    {
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $list = $this->model
                ->where($where)
                ->order($sort ?: 'id', $order ?: 'desc')
                ->paginate($limit);
            $cfg = (array)config('fanshub.');
            $globalFree = max(0, (int)($cfg['welfare_rp_daily_free'] ?? 10));
            $per = max(1, (int)($cfg['welfare_rp_entertain_per_bonus'] ?? 5));
            $uids = [];
            foreach ($list as $row) {
                $uids[] = (int)$row->user_id;
            }
            $nickMap = [];
            if ($uids) {
                $nickMap = Db::name('user')->where('id', 'in', array_values(array_unique($uids)))->column('nickname', 'id');
            }
            foreach ($list as $row) {
                $uid = (int)$row->user_id;
                $row->nickname = $nickMap[$uid] ?? ('UID ' . $uid);
                $d = (string)$row->quota_date;
                $row->quota_date_text = (strlen($d) === 8)
                    ? (substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2))
                    : $d;
                $entertain = max(0, (int)$row->entertain_count);
                $bonus = intdiv($entertain, $per);
                $freePersonal = (int)$row->free_limit;
                $free = $freePersonal > 0 ? $freePersonal : $globalFree;
                $extra = (int)$row->admin_extra;
                $effective = max(0, $free + $bonus + $extra);
                $claimed = max(0, (int)$row->claim_count);
                $row->bonus_chance = $bonus;
                $row->effective_limit = $effective;
                $row->remain = max(0, $effective - $claimed);
                $row->global_free = $globalFree;
            }
            return json(['total' => $list->total(), 'rows' => $list->items()]);
        }
        return $this->view->fetch();
    }

    public function add()
    {
        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');
            if (!$params) {
                $this->error(__('Parameter %s can not be empty', ''));
            }
            $userId = (int)($params['user_id'] ?? 0);
            $ymd = preg_replace('/\D+/', '', (string)($params['quota_date'] ?? date('Ymd')));
            if (strlen($ymd) === 8 && strpos((string)$params['quota_date'], '-') !== false) {
                $ymd = date('Ymd', strtotime((string)$params['quota_date']));
            }
            if ($userId <= 0 || strlen($ymd) !== 8) {
                $this->error('请填写有效用户ID与日期');
            }
            $now = time();
            $data = [
                'user_id'         => $userId,
                'quota_date'      => $ymd,
                'claim_count'     => max(0, (int)($params['claim_count'] ?? 0)),
                'entertain_count' => max(0, (int)($params['entertain_count'] ?? 0)),
                'admin_extra'     => (int)($params['admin_extra'] ?? 0),
                'free_limit'      => max(0, (int)($params['free_limit'] ?? 0)),
                'createtime'      => $now,
                'updatetime'      => $now,
            ];
            try {
                $this->model->insert($data);
            } catch (\Throwable $e) {
                $this->error('写入失败（可能已存在同日记录）：' . $e->getMessage());
            }
            $this->success();
        }
        return $this->view->fetch();
    }

    public function edit($ids = null)
    {
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');
            if (!$params) {
                $this->error(__('Parameter %s can not be empty', ''));
            }
            $row->save([
                'claim_count'     => max(0, (int)($params['claim_count'] ?? $row->claim_count)),
                'entertain_count' => max(0, (int)($params['entertain_count'] ?? $row->entertain_count)),
                'admin_extra'     => (int)($params['admin_extra'] ?? $row->admin_extra),
                'free_limit'      => max(0, (int)($params['free_limit'] ?? $row->free_limit)),
                'updatetime'      => time(),
            ]);
            $this->success();
        }
        $this->view->assign('row', $row);
        return $this->view->fetch();
    }
}
