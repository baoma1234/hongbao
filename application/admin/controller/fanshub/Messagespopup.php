<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;

/**
 * 红宝页（消息 Tab）弹窗
 *
 * @icon fa fa-commenting
 */
class Messagespopup extends Backend
{
    protected $model = null;
    protected $searchFields = 'id,title';
    protected $multiFields = 'status,weigh';

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Messagespopup;
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->view->assign('showModeList', $this->model->getShowModeList());
        $this->view->assign('jumpTypeList', $this->model->getJumpTypeList());
        $this->assignconfig('statusList', $this->model->getStatusList());
        $this->assignconfig('showModeList', $this->model->getShowModeList());
        $this->assignconfig('jumpTypeList', $this->model->getJumpTypeList());
    }

    protected function rowForForm($row)
    {
        $data = $row->getData();
        $images = $data['images'] ?? '';
        if (is_array($images)) {
            $data['images'] = implode(',', array_values(array_filter(array_map('strval', $images))));
        } else {
            $trim = trim((string)$images);
            if ($trim !== '' && $trim[0] === '[') {
                $arr = json_decode($trim, true);
                $data['images'] = is_array($arr)
                    ? implode(',', array_values(array_filter(array_map('strval', $arr))))
                    : '';
            } else {
                $data['images'] = $trim;
            }
        }
        return $data;
    }

    protected function normalizeRow(array $params)
    {
        $params['title'] = mb_substr(trim((string)($params['title'] ?? '')), 0, 128);
        if ($params['title'] === '') {
            $this->error('请填写标题');
        }
        $params['content'] = trim((string)($params['content'] ?? ''));
        $jump = strtolower(trim((string)($params['jump_type'] ?? 'none')));
        if (!in_array($jump, ['community', 'notice', 'url', 'none'], true)) {
            $jump = 'none';
        }
        $params['jump_type'] = $jump;
        $params['jump_extra'] = mb_substr(trim((string)($params['jump_extra'] ?? '')), 0, 255);
        $params['btn_text'] = mb_substr(trim((string)($params['btn_text'] ?? '')) ?: '查看', 0, 64);
        $mode = strtolower(trim((string)($params['show_mode'] ?? 'daily')));
        if (!in_array($mode, ['daily', 'once', 'always'], true)) {
            $mode = 'daily';
        }
        $params['show_mode'] = $mode;
        $params['weigh'] = (int)($params['weigh'] ?? 0);
        $params['status'] = ((string)($params['status'] ?? 'normal') === 'hidden') ? 'hidden' : 'normal';

        if (isset($params['images']) && is_string($params['images'])) {
            $trim = trim($params['images']);
            if ($trim !== '' && $trim[0] !== '[') {
                $parts = preg_split('/[\r\n,]+/', $trim);
                $params['images'] = array_values(array_filter(array_map('trim', $parts ?: [])));
            }
        }
        return $params;
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
            $rows = $list->items();
            foreach ($rows as $row) {
                $imgs = $row['images'];
                if (is_array($imgs)) {
                    $row['images'] = implode(',', $imgs);
                }
            }
            return json(['total' => $list->total(), 'rows' => $rows]);
        }
        return $this->view->fetch();
    }

    public function add()
    {
        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');
            if ($params) {
                $params = $this->normalizeRow($params);
                $this->request->post(['row' => $params]);
            }
        }
        $this->view->assign('row', [
            'jump_type' => 'community',
            'btn_text'  => '进入社群',
            'show_mode' => 'daily',
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
                $params = $this->normalizeRow($params);
                $this->request->post(['row' => $params]);
            }
            return parent::edit($ids);
        }
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        $this->view->assign('row', $this->rowForForm($row));
        return $this->view->fetch();
    }
}
