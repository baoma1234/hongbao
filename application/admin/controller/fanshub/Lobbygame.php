<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubLobby;
use think\Db;

/**
 * 大厅分类游戏管理
 * @icon fa fa-gamepad
 */
class Lobbygame extends Backend
{
    protected $model = null;
    protected $searchFields = 'id,game_key,title';
    protected $multiFields = 'status,weigh,coming_soon';

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Lobbygame;
        $catList = [];
        try {
            $rows = Db::name('fans_lobby_categories')->order('weigh', 'desc')->order('id', 'asc')->select();
            foreach ((array)$rows as $r) {
                $k = (string)($r['cat_key'] ?? '');
                if ($k !== '') {
                    $catList[$k] = (string)($r['title'] ?? $k) . ' (' . $k . ')';
                }
            }
        } catch (\Throwable $e) {
        }
        if (!$catList) {
            $catList = ['hot' => '热门推荐', 'games' => '红宝游戏'];
        }
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->view->assign('catList', $catList);
        $this->assignconfig('statusList', $this->model->getStatusList());
        $this->assignconfig('catList', $catList);
    }

    protected function normalize(array $p)
    {
        $p['game_key'] = preg_replace('/[^a-z0-9_]/i', '', strtolower(trim((string)($p['game_key'] ?? ''))));
        if ($p['game_key'] === '') {
            $this->error('请填写游戏 Key');
        }
        $p['title'] = mb_substr(trim((string)($p['title'] ?? '')), 0, 64);
        if ($p['title'] === '') {
            $this->error('请填写游戏名');
        }
        $p['cover'] = trim((string)($p['cover'] ?? ''));
        if ($p['cover'] === '') {
            $this->error('请上传封面图');
        }
        $p['badge'] = mb_substr(trim((string)($p['badge'] ?? '')), 0, 16);
        $cats = $p['cats'] ?? [];
        if (is_string($cats)) {
            $cats = preg_split('/\s*,\s*/', $cats, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($cats)) {
            $cats = [];
        }
        $cats = array_values(array_unique(array_filter(array_map(function ($x) {
            return preg_replace('/[^a-z0-9_]/i', '', strtolower(trim((string)$x)));
        }, $cats))));
        if (!$cats) {
            $this->error('请至少选择一个大厅分类');
        }
        $p['cats'] = implode(',', $cats);
        $p['group_match'] = mb_substr(trim((string)($p['group_match'] ?? '')), 0, 128);
        $p['sum_group_match'] = mb_substr(trim((string)($p['sum_group_match'] ?? '')), 0, 128);
        $p['coming_soon'] = !empty($p['coming_soon']) ? 1 : 0;
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
        $this->view->assign('row', [
            'cats' => 'hot,games',
            'status' => 'normal',
            'weigh' => 0,
            'coming_soon' => 0,
            'badge' => '',
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
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        $data = $row->getData();
        $this->view->assign('row', $data);
        return $this->view->fetch();
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
