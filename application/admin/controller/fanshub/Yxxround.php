<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use think\Db;

/**
 * 鱼虾蟹局结算归档
 *
 * @icon fa fa-list
 */
class Yxxround extends Backend
{
    protected $noNeedRight = [];

    public function index()
    {
        if ($this->request->isAjax()) {
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $total = Db::name('fans_yxx_rounds')->where($where)->count();
            $list = Db::name('fans_yxx_rounds')
                ->where($where)
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();
            return json(['total' => $total, 'rows' => $list]);
        }
        return $this->view->fetch();
    }
}
