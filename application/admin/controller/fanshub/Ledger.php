<?php

namespace app\admin\controller\fanshub;

use app\admin\library\traits\FanshubExport;
use app\common\controller\Backend;
use think\Db;

/**
 * 福利资产流水
 *
 * @icon fa fa-list
 */
class Ledger extends Backend
{
    use FanshubExport;

    protected $model = null;
    protected $relationSearch = true;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Ledger;
        $this->view->assign('typeList', $this->model->getTypeList());
    }

    /**
     * 抽取虚拟字段 user_kind（0=普通用户 1=机器人），并从 filter/op 剔除，避免 buildparams 查不存在的列
     *
     * @return int|null null=未筛；0/1=对应类型
     */
    protected function pullUserKind()
    {
        $filterRaw = $this->request->get('filter', '');
        $opRaw = $this->request->get('op', '');
        $filterArr = is_string($filterRaw) ? (json_decode($filterRaw, true) ?: []) : (is_array($filterRaw) ? $filterRaw : []);
        $opArr = is_string($opRaw) ? (json_decode($opRaw, true) ?: []) : (is_array($opRaw) ? $opRaw : []);

        $has = array_key_exists('user_kind', $filterArr);
        $kind = $has ? (string)$filterArr['user_kind'] : '';
        unset($filterArr['user_kind'], $opArr['user_kind']);
        $this->request->get([
            'filter' => json_encode($filterArr, JSON_UNESCAPED_UNICODE),
            'op'     => json_encode($opArr, JSON_UNESCAPED_UNICODE),
        ]);

        if (!$has || $kind === '') {
            return null;
        }
        return ((int)$kind === 1) ? 1 : 0;
    }

    /**
     * @param \think\db\Query|\think\Model $query
     * @param int|null                     $userKind
     */
    protected function applyUserKindFilter($query, $userKind)
    {
        if ($userKind === null) {
            return;
        }
        $ledgerTable = $this->model->getTable();
        $accountTable = Db::name('fans_account')->getTable();
        $flag = (int)$userKind;
        $query->whereRaw(
            "`{$ledgerTable}`.`user_id` IN (SELECT `user_id` FROM `{$accountTable}` WHERE IFNULL(`is_bot`,0)={$flag})"
        );
    }

    /**
     * 给列表行挂上 user_kind（0/1）供前端展示
     *
     * @param array|\think\Collection $rows
     */
    protected function attachUserKind($rows)
    {
        $list = is_array($rows) ? $rows : (method_exists($rows, 'all') ? $rows->all() : []);
        if (!$list) {
            return;
        }
        $uids = [];
        foreach ($list as $row) {
            $uid = (int)(is_object($row) ? ($row->user_id ?? 0) : ($row['user_id'] ?? 0));
            if ($uid > 0) {
                $uids[] = $uid;
            }
        }
        $uids = array_values(array_unique($uids));
        $botMap = [];
        if ($uids) {
            $botMap = Db::name('fans_account')
                ->where('user_id', 'in', $uids)
                ->column('is_bot', 'user_id');
        }
        foreach ($list as $row) {
            $uid = (int)(is_object($row) ? ($row->user_id ?? 0) : ($row['user_id'] ?? 0));
            $isBot = !empty($botMap[$uid]) ? 1 : 0;
            if (is_object($row)) {
                $row->user_kind = $isBot;
            }
        }
    }

    public function index()
    {
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }
            $userKind = $this->pullUserKind();
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $forceUserId = (int)$this->request->param('user_id', 0);
            $query = $this->model
                ->with(['user'])
                ->where($where);
            if ($forceUserId > 0) {
                $query->where($this->model->getTable() . '.user_id', $forceUserId);
            }
            $this->applyUserKindFilter($query, $userKind);
            $list = $query
                ->order($sort, $order)
                ->paginate($limit);
            $this->attachUserKind($list);
            foreach ($list as $row) {
                if ($row->getRelation('user')) {
                    $row->getRelation('user')->visible(['id', 'mobile']);
                }
                $row->remark = \app\common\library\FansHubWallet::enrichLedgerRemark(
                    (string)$row->remark,
                    (string)($row->biz_no ?? ''),
                    (string)($row->ref_type ?? ''),
                    (string)($row->type ?? '')
                );
            }
            $result = ['total' => $list->total(), 'rows' => $list->items()];
            return json($result);
        }
        return $this->view->fetch();
    }

    public function export()
    {
        $this->request->filter(['strip_tags', 'trim']);
        $userKind = $this->pullUserKind();
        list($where, $sort, $order) = $this->buildparams();
        $query = $this->model->with(['user'])->where($where)->order($sort, $order);
        $this->applyUserKindFilter($query, $userKind);
        $rows = $this->exportQueryRows($query);
        $this->attachUserKind($rows);
        $typeList = $this->model->getTypeList();
        $data = [];
        foreach ($rows as $row) {
            $remark = \app\common\library\FansHubWallet::enrichLedgerRemark(
                (string)$row->remark,
                (string)($row->biz_no ?? ''),
                (string)($row->ref_type ?? ''),
                (string)($row->type ?? '')
            );
            $kindLabel = ((int)($row->user_kind ?? 0) === 1) ? '机器人' : '普通用户';
            $data[] = [
                $row->id,
                $row->user_id,
                $row->user ? $row->user->mobile : '',
                $kindLabel,
                $typeList[$row->type] ?? $row->type,
                $row->rights_change,
                (abs((float)($row->hongbao_change ?? 0)) > 1e-8)
                    ? $row->hongbao_change
                    : $row->balance_change,
                $row->rights_after,
                (isset($row->hongbao_after) && $row->hongbao_after !== null && $row->hongbao_after !== '')
                    ? $row->hongbao_after
                    : $row->balance_after,
                (string)($row->biz_no ?? ''),
                $remark,
                $row->channel,
                $row->createtime ? date('Y-m-d H:i:s', $row->createtime) : '',
            ];
        }
        $this->exportXlsx('fanshub_ledger_' . date('Ymd_His'), [
            'ID', '会员ID', '手机号', '用户类型', '类型', '股份变动', '红宝变动', '股份结余', '红宝结余', '红宝号', '备注', '通道', '时间',
        ], $data);
    }
}
