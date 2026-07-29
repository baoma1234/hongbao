<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubRedPacket;

/**
 * 红包皮肤管理
 *
 * @icon fa fa-picture-o
 */
class Redpacketskin extends Backend
{
    protected $model = null;
    protected $searchFields = 'id,name';
    protected $multiFields = 'status,weigh';

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Redpacketskin;
        $this->view->assign('packetTypeList', $this->model->getPacketTypeList());
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->view->assign('skinWidth', (int)FansHubRedPacket::get('skin_width', 750));
        $this->view->assign('skinHeight', (int)FansHubRedPacket::get('skin_height', 1000));
    }

    public function index()
    {
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $list = $this->model->where($where)->order($sort, $order)->paginate($limit);
            return json(['total' => $list->total(), 'rows' => $list->items()]);
        }
        return $this->view->fetch();
    }

    public function add()
    {
        if ($this->request->isPost()) {
            $params = $this->normalize($this->request->post('row/a'));
            $this->request->post(['row' => $params]);
        }
        return parent::add();
    }

    public function edit($ids = null)
    {
        if ($this->request->isPost()) {
            $params = $this->normalize($this->request->post('row/a'));
            $this->request->post(['row' => $params]);
        }
        return parent::edit($ids);
    }

    protected function normalize(array $params)
    {
        $params['name'] = mb_substr(trim((string)($params['name'] ?? '')), 0, 64);
        $params['image'] = trim((string)($params['image'] ?? ''));
        $params['thumb'] = trim((string)($params['thumb'] ?? ''));
        $params['packet_type'] = (int)($params['packet_type'] ?? 0);
        if (!in_array($params['packet_type'], [0, 2, 3], true)) {
            $params['packet_type'] = 0;
        }
        $params['weigh'] = (int)($params['weigh'] ?? 0);
        $params['status'] = ($params['status'] ?? 'normal') === 'hidden' ? 'hidden' : 'normal';
        $check = FansHubRedPacket::validateSkinImage($params['image']);
        if (!$check['ok']) {
            $this->error($check['message']);
        }
        $params['width'] = (int)$check['width'];
        $params['height'] = (int)$check['height'];
        if ($params['thumb'] === '') {
            $params['thumb'] = $params['image'];
        }
        return $params;
    }
}
