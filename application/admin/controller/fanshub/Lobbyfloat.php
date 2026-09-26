<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubLobby;

/**
 * 大厅左右浮标
 * @icon fa fa-bullseye
 */
class Lobbyfloat extends Backend
{
    protected $model = null;
    protected $searchFields = 'id,title';
    protected $multiFields = 'status,weigh';

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Lobbyfloat;
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->view->assign('sideList', $this->model->getSideList());
        $this->view->assign('linkTypeList', $this->model->getLinkTypeList());
        $this->assignconfig('statusList', $this->model->getStatusList());
        $this->assignconfig('sideList', $this->model->getSideList());
        $this->assignconfig('linkTypeList', $this->model->getLinkTypeList());
    }

    protected function normalize(array $p)
    {
        $p['title'] = mb_substr(trim((string)($p['title'] ?? '')), 0, 64);
        $p['image'] = FansHubLobby::normalizeStoredPath($p['image'] ?? '');
        if ($p['image'] === '') {
            $this->error('请上传悬浮图标');
        }
        $side = strtolower(trim((string)($p['side'] ?? 'right')));
        $p['side'] = ($side === 'left') ? 'left' : 'right';
        $lt = strtolower(trim((string)($p['link_type'] ?? 'internal')));
        if (!isset($this->model->getLinkTypeList()[$lt])) {
            $lt = 'internal';
        }
        $p['link_type'] = $lt;
        $url = mb_substr(trim((string)($p['link_url'] ?? '')), 0, 255);
        if ($lt === 'external') {
            if ($url !== '' && !preg_match('#^https?://#i', $url)) {
                $this->error('站外链接须以 http:// 或 https:// 开头');
            }
        } elseif ($lt === 'internal') {
            $url = ltrim($url, '#');
            if ($url !== '' && (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0)) {
                $this->error('站内请填 pages/... 路径，不要填 http');
            }
            // 允许 pages/notice/notice 或 /pages/notice/notice
            if ($url !== '' && $url[0] === '/') {
                $url = ltrim($url, '/');
            }
        } else {
            $url = '';
        }
        $p['link_url'] = $url;
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
            $rows = FansHubLobby::mapAdminImageFields($list->items(), ['image']);
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
            FansHubLobby::clearCache();
            return $ret;
        }
        $this->view->assign('row', [
            'side'      => 'right',
            'link_type' => 'internal',
            'status'    => 'normal',
            'weigh'     => 0,
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
