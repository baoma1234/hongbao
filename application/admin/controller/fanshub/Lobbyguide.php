<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubLobby;
use app\common\library\FansHubLobbyGuide;

/**
 * 玩法说明（游戏详情简介/规则）
 * @icon fa fa-book
 */
class Lobbyguide extends Backend
{
    protected $model = null;
    protected $searchFields = 'id,game_key,title';
    protected $multiFields = 'status,weigh';

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Lobbyguide;
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->assignconfig('statusList', $this->model->getStatusList());
    }

    protected function normalize(array $p)
    {
        $p['game_key'] = preg_replace('/[^a-z0-9_]/i', '', strtolower(trim((string)($p['game_key'] ?? ''))));
        if ($p['game_key'] === '') {
            $this->error('请填写游戏 Key（如 saolei）');
        }
        $p['title'] = mb_substr(trim((string)($p['title'] ?? '')), 0, 64);
        if ($p['title'] === '') {
            $this->error('请填写标题');
        }
        $p['intro'] = trim((string)($p['intro'] ?? ''));
        $p['rules'] = trim((string)($p['rules'] ?? ''));
        $p['hero'] = FansHubLobby::normalizeStoredPath($p['hero'] ?? '');
        $p['badge'] = mb_substr(trim((string)($p['badge'] ?? '')), 0, 16);
        $p['badge_text'] = mb_substr(trim((string)($p['badge_text'] ?? '')), 0, 32);
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
            $rows = FansHubLobby::mapAdminImageFields($list->items(), ['hero']);
            return json(['total' => $list->total(), 'rows' => $rows]);
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
            FansHubLobbyGuide::clearCache();
            return $ret;
        }
        $this->view->assign('row', [
            'status' => 'normal',
            'weigh' => 0,
            'badge' => '',
            'badge_text' => '',
            'intro' => '',
            'rules' => '',
            'hero' => '',
        ]);
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
            FansHubLobbyGuide::clearCache();
            return $ret;
        }
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        $this->view->assign('row', $row->getData());
        return $this->view->fetch();
    }

    public function del($ids = null)
    {
        $ret = parent::del($ids);
        FansHubLobbyGuide::clearCache();
        return $ret;
    }

    public function multi($ids = null)
    {
        $ret = parent::multi($ids);
        FansHubLobbyGuide::clearCache();
        return $ret;
    }
}
