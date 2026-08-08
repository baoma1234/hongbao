<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use think\Db;

/**
 * 尾数牛牛对局列表
 *
 * @icon fa fa-list
 */
class Niuniu extends Backend
{
    protected $noNeedRight = [];

    public function index()
    {
        if ($this->request->isAjax()) {
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $total = Db::name('chat_niuniu_rounds')->where($where)->count();
            $list = Db::name('chat_niuniu_rounds')
                ->where($where)
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();
            return json(['total' => $total, 'rows' => $list]);
        }
        return $this->view->fetch();
    }

    public function detail($ids = null)
    {
        $id = (int)($ids ?: $this->request->param('ids'));
        $row = Db::name('chat_niuniu_rounds')->where('id', $id)->find();
        if (!$row) {
            $this->error('对局不存在');
        }
        $shares = Db::name('chat_niuniu_shares')->where('round_id', $id)->order('share_no asc')->select();
        $this->view->assign('row', $row);
        $this->view->assign('shares', $shares);
        return $this->view->fetch();
    }
}
