<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubFriend;

/**
 * IM 好友申请（全站）
 *
 * @icon fa fa-user-plus
 */
class Friendrequest extends Backend
{
    protected $model = null;
    protected $relationSearch = true;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Friendrequest;
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
            $list = $this->model
                ->with(['fromuser', 'touser'])
                ->where($where)
                ->order($sort, $order)
                ->paginate($limit);
            foreach ($list as $row) {
                if ($row->getRelation('fromuser')) {
                    $row->getRelation('fromuser')->visible(['id', 'mobile', 'nickname']);
                }
                if ($row->getRelation('touser')) {
                    $row->getRelation('touser')->visible(['id', 'mobile', 'nickname']);
                }
            }
            return json(['total' => $list->total(), 'rows' => $list->items()]);
        }
        return $this->view->fetch();
    }

    public function approve($ids = null)
    {
        $ids = $ids ?: $this->request->param('ids');
        try {
            FansHubFriend::acceptByAdmin($ids);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
        $this->success('已通过并互为好友');
    }

    public function reject($ids = null)
    {
        $ids = $ids ?: $this->request->param('ids');
        try {
            FansHubFriend::rejectByAdmin($ids);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
        $this->success('已拒绝');
    }
}
