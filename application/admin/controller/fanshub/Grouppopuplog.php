<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use think\Db;

/**
 * 群弹窗展示记录
 *
 * @icon fa fa-history
 */
class Grouppopuplog extends Backend
{
    protected $model = null;
    protected $relationSearch = true;
    protected $searchFields = 'id,popup_id,group_id,user_id';

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Grouppopuplog;
        $this->view->assign('actionList', $this->model->getActionList());
        $this->assignconfig('actionList', $this->model->getActionList());
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
                ->with(['user', 'popup'])
                ->where($where)
                ->order($sort, $order)
                ->paginate($limit);
            $rows = $list->items();
            $gids = [];
            foreach ($rows as $row) {
                $gids[] = (int)$row['group_id'];
                if ($row->getRelation('user')) {
                    $row->getRelation('user')->visible(['id', 'mobile', 'nickname']);
                }
                if ($row->getRelation('popup')) {
                    $row->getRelation('popup')->visible(['id', 'title', 'show_mode']);
                }
            }
            $gids = array_values(array_unique(array_filter($gids)));
            $names = $gids
                ? Db::name('chat_groups')->where('id', 'in', $gids)->column('name', 'id')
                : [];
            foreach ($rows as $row) {
                $row['group_name'] = $names[(int)$row['group_id']] ?? '';
            }
            return json(['total' => $list->total(), 'rows' => $rows]);
        }
        return $this->view->fetch();
    }
}
