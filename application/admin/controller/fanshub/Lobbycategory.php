<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubLobby;

/**
 * 大厅分类管理
 * @icon fa fa-th
 */
class Lobbycategory extends Backend
{
    protected $model = null;
    protected $searchFields = 'id,cat_key,title';
    protected $multiFields = 'status,weigh';

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Lobbycategory;
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->view->assign('actionList', $this->model->getActionList());
        $this->assignconfig('statusList', $this->model->getStatusList());
        $this->assignconfig('actionList', $this->model->getActionList());
    }

    protected function normalize(array $p)
    {
        $p['cat_key'] = preg_replace('/[^a-z0-9_]/i', '', strtolower(trim((string)($p['cat_key'] ?? ''))));
        if ($p['cat_key'] === '') {
            $this->error('请填写分类 Key（英文）');
        }
        $p['title'] = mb_substr(trim((string)($p['title'] ?? '')), 0, 64);
        if ($p['title'] === '') {
            $this->error('请填写分类名');
        }
        $p['icon'] = trim((string)($p['icon'] ?? ''));
        $p['icon_static'] = trim((string)($p['icon_static'] ?? ''));
        $act = strtolower(trim((string)($p['action'] ?? 'filter')));
        if (!isset($this->model->getActionList()[$act])) {
            $act = 'filter';
        }
        $p['action'] = $act;
        $p['action_url'] = mb_substr(trim((string)($p['action_url'] ?? '')), 0, 255);
        $p['weigh'] = (int)($p['weigh'] ?? 0);
        $p['status'] = ((string)($p['status'] ?? 'normal') === 'hidden') ? 'hidden' : 'normal';
        return $p;
    }

    public function index()
    {
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $list = $this->model->where($where)->order($sort, $order)->paginate($limit);
            return json(['total' => $list->total(), 'rows' => $list->items()]);
        }
        return $this->view->fetch();
    }

    public function add()
    {
        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');
            if ($params) {
                $this->request->post(['row' => $this->normalize($params)]);
            }
            $ret = parent::add();
            FansHubLobby::clearCache();
            return $ret;
        }
        $this->view->assign('row', ['action' => 'filter', 'status' => 'normal', 'weigh' => 0]);
        return parent::add();
    }

    public function edit($ids = null)
    {
        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');
            if ($params) {
                $this->request->post(['row' => $this->normalize($params)]);
            }
            $ret = parent::edit($ids);
            FansHubLobby::clearCache();
            return $ret;
        }
        return parent::edit($ids);
    }

    public function del($ids = null)
    {
        $ret = parent::del($ids);
        FansHubLobby::clearCache();
        return $ret;
    }

    public function multi($ids = null)
    {
        $ret = parent::multi($ids);
        FansHubLobby::clearCache();
        return $ret;
    }
}
