<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubLobby;

/**
 * 大厅邀请条
 * @icon fa fa-gift
 */
class Lobbyinvite extends Backend
{
    protected $model = null;
    protected $searchFields = 'id,title';
    protected $multiFields = 'status,weigh';

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Lobbyinvite;
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->view->assign('linkTypeList', $this->model->getLinkTypeList());
        $this->assignconfig('statusList', $this->model->getStatusList());
        $this->assignconfig('linkTypeList', $this->model->getLinkTypeList());
    }

    protected function normalize(array $p)
    {
        $p['title'] = mb_substr(trim((string)($p['title'] ?? '')), 0, 64);
        $p['image'] = trim((string)($p['image'] ?? ''));
        if ($p['image'] === '') {
            $this->error('请上传邀请条图片');
        }
        $lt = strtolower(trim((string)($p['link_type'] ?? 'share')));
        if (!isset($this->model->getLinkTypeList()[$lt])) {
            $lt = 'share';
        }
        $p['link_type'] = $lt;
        $p['link_url'] = mb_substr(trim((string)($p['link_url'] ?? '')), 0, 255);
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
        $this->view->assign('row', ['link_type' => 'share', 'status' => 'normal', 'weigh' => 0]);
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
