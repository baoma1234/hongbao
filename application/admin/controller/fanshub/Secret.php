<?php

namespace app\admin\controller\fanshub;

use app\admin\library\traits\FanshubExport;
use app\common\controller\Backend;
use app\common\library\FansHubService;

/**
 * 福利领取工单（原密令）
 *
 * @icon fa fa-key
 */
class Secret extends Backend
{
    use FanshubExport;

    protected $model = null;
    protected $relationSearch = true;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Secret;
        $this->view->assign('statusList', $this->model->getStatusList());
    }

    public function index()
    {
        FansHubService::expireSecrets();
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $list = $this->model
                ->with(['user'])
                ->where($where)
                ->order($sort, $order)
                ->paginate($limit);
            foreach ($list as $row) {
                if ($row->getRelation('user')) {
                    $row->getRelation('user')->visible(['id', 'mobile']);
                }
            }
            $result = ['total' => $list->total(), 'rows' => $list->items()];
            return json($result);
        }
        return $this->view->fetch();
    }

    public function export()
    {
        FansHubService::expireSecrets();
        $this->request->filter(['strip_tags', 'trim']);
        list($where, $sort, $order) = $this->buildparams();
        $rows = $this->exportQueryRows(
            $this->model->with(['user'])->where($where)->order($sort, $order)
        );
        $statusList = $this->model->getStatusList();
        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                $row->id,
                $row->user_id,
                $row->user ? $row->user->mobile : '',
                $row->code,
                $row->amount,
                $row->tier,
                $row->main_uid,
                $statusList[$row->status] ?? $row->status,
                $row->expiretime ? date('Y-m-d H:i:s', $row->expiretime) : '',
                $row->createtime ? date('Y-m-d H:i:s', $row->createtime) : '',
            ];
        }
        $this->exportXlsx('fanshub_secret_' . date('Ymd_His'), [
            'ID', '会员ID', '手机号', '密令', '金额', '等级', 'UID', '状态', '过期时间', '创建时间',
        ], $data);
    }
}
