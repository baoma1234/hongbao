<?php

namespace app\admin\controller\fanshub;

use app\admin\library\traits\FanshubExport;
use app\common\controller\Backend;

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

    public function index()
    {
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $forceUserId = (int)$this->request->param('user_id', 0);
            $query = $this->model
                ->with(['user'])
                ->where($where);
            if ($forceUserId > 0) {
                $query->where($this->model->getTable() . '.user_id', $forceUserId);
            }
            $list = $query
                ->order($sort, $order)
                ->paginate($limit);
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
        list($where, $sort, $order) = $this->buildparams();
        $rows = $this->exportQueryRows(
            $this->model->with(['user'])->where($where)->order($sort, $order)
        );
        $typeList = $this->model->getTypeList();
        $data = [];
        foreach ($rows as $row) {
            $remark = \app\common\library\FansHubWallet::enrichLedgerRemark(
                (string)$row->remark,
                (string)($row->biz_no ?? ''),
                (string)($row->ref_type ?? ''),
                (string)($row->type ?? '')
            );
            $data[] = [
                $row->id,
                $row->user_id,
                $row->user ? $row->user->mobile : '',
                $typeList[$row->type] ?? $row->type,
                $row->rights_change,
                // 红宝变动：优先 hongbao_change，旧流水回退 balance_change
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
            'ID', '会员ID', '手机号', '类型', '股份变动', '红宝变动', '股份结余', '红宝结余', '红宝号', '备注', '通道', '时间',
        ], $data);
    }
}
