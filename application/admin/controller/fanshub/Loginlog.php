<?php

namespace app\admin\controller\fanshub;

use app\admin\library\traits\FanshubExport;
use app\common\controller\Backend;

/**
 * 福利登录日志
 *
 * @icon fa fa-sign-in
 */
class Loginlog extends Backend
{
    use FanshubExport;

    protected $model = null;
    protected $relationSearch = true;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\common\model\fanshub\LoginLog;
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
                ->with(['user'])
                ->where($where)
                ->order($sort, $order)
                ->paginate($limit);
            foreach ($list as $row) {
                if ($row->getRelation('user')) {
                    $row->getRelation('user')->visible(['id', 'mobile']);
                }
            }
            $result = ['total' => $list->total(), 'rows' => $list->items()];
            return json($result);
        }
        return $this->view->fetch();
    }

    public function export()
    {
        $this->request->filter(['strip_tags', 'trim']);
        list($where, $sort, $order) = $this->buildparams();
        $rows = $this->exportQueryRows(
            $this->model->with(['user'])->where($where)->order($sort, $order)
        );
        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                $row->id,
                $row->user_id,
                $row->user ? $row->user->mobile : '',
                $row->ip,
                $row->device_fingerprint,
                $row->user_agent,
                $row->createtime ? date('Y-m-d H:i:s', $row->createtime) : '',
            ];
        }
        $this->exportXlsx('fanshub_loginlog_' . date('Ymd_His'), [
            'ID', '会员ID', '手机号', 'IP', '设备指纹', 'User-Agent', '时间',
        ], $data);
    }
}
