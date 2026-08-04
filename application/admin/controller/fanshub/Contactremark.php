<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;

/**
 * IM 用户备注列表
 *
 * @icon fa fa-pencil-square-o
 */
class Contactremark extends Backend
{
    protected $model = null;
    protected $relationSearch = true;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Contactremark;
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
                ->with(['owner', 'peer'])
                ->where($where)
                ->where('remark', '<>', '')
                ->order($sort, $order)
                ->paginate($limit);
            foreach ($list as $row) {
                if ($row->getRelation('owner')) {
                    $row->getRelation('owner')->visible(['id', 'mobile', 'nickname']);
                }
                if ($row->getRelation('peer')) {
                    $row->getRelation('peer')->visible(['id', 'mobile', 'nickname']);
                }
            }
            return json(['total' => $list->total(), 'rows' => $list->items()]);
        }
        return $this->view->fetch();
    }
}
