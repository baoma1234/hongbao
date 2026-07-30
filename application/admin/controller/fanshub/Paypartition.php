<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubService;

/**
 * 充提通道分区
 *
 * @icon fa fa-th-large
 */
class Paypartition extends Backend
{
    protected $model = null;
    protected $searchFields = 'id,code,name';

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Paypartition;
        $this->view->assign('typeList', $this->model->getTypeList());
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->view->assign('bindModeList', $this->model->getBindModeList());
        $this->view->assign('codeList', $this->model->getCodeList());
        $this->view->assign('localeList', FansHubService::i18nLocaleCodes());
        $this->assignconfig('typeList', $this->model->getTypeList());
        $this->assignconfig('statusList', $this->model->getStatusList());
        $this->assignconfig('bindModeList', $this->model->getBindModeList());
    }

    protected function decodeI18n($raw)
    {
        if (is_array($raw)) {
            return $raw;
        }
        $raw = trim((string)$raw);
        if ($raw === '') {
            return [];
        }
        $arr = json_decode($raw, true);
        return is_array($arr) ? $arr : [];
    }

    protected function encodeI18n($raw)
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            } else {
                return '{}';
            }
        }
        if (!is_array($raw)) {
            return '{}';
        }
        $out = [];
        foreach ($raw as $k => $v) {
            $k = trim((string)$k);
            $v = trim((string)$v);
            if ($k === '' || $k === 'zh-CN' || $v === '') {
                continue;
            }
            $out[$k] = mb_substr($v, 0, 64);
        }
        return json_encode($out, JSON_UNESCAPED_UNICODE);
    }

    public function add()
    {
        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');
            if (!is_array($params)) {
                $this->error(__('Parameter %s can not be empty', ''));
            }
            $params['name_i18n'] = $this->encodeI18n($params['name_i18n'] ?? []);
            $params['code'] = trim((string)($params['code'] ?? ''));
            $params['name'] = trim((string)($params['name'] ?? ''));
            if ($params['code'] === '' || $params['name'] === '') {
                $this->error('请填写分区编码与名称');
            }
            $this->request->post(['row' => $params]);
            return parent::add();
        }
        $this->view->assign('row', [
            'type' => 'recharge',
            'code' => 'self_service',
            'name' => '',
            'name_i18n_map' => [],
            'bind_mode' => 'conventional',
            'weigh' => 0,
            'status' => 'normal',
        ]);
        return $this->view->fetch();
    }

    public function edit($ids = null)
    {
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');
            if (!is_array($params)) {
                $this->error(__('Parameter %s can not be empty', ''));
            }
            $params['name_i18n'] = $this->encodeI18n($params['name_i18n'] ?? []);
            $params['code'] = trim((string)($params['code'] ?? $row['code']));
            $params['name'] = trim((string)($params['name'] ?? ''));
            if ($params['name'] === '') {
                $this->error('请填写分区名称');
            }
            $this->request->post(['row' => $params]);
            return parent::edit($ids);
        }
        $data = $row->getData();
        $data['name_i18n_map'] = $this->decodeI18n($data['name_i18n'] ?? '');
        $this->view->assign('row', $data);
        return $this->view->fetch();
    }
}
