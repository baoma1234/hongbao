<?php

namespace app\admin\controller\fanshub;

use app\admin\library\traits\FanshubExport;
use app\common\controller\Backend;

/**
 * 任务记录
 *
 * @icon fa fa-tasks
 */
class Task extends Backend
{
    use FanshubExport;

    protected $model = null;
    protected $relationSearch = true;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Task;
        $this->view->assign('taskTypeList', $this->model->getTaskTypeList());
    }

    public function index()
    {
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $list = $this->model
                ->with(['user'])
                ->where($where)
                ->order($sort, $order)
                ->paginate($limit);
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
        $typeList = $this->model->getTaskTypeList();
        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                $row->id,
                $row->user_id,
                $row->user ? $row->user->mobile : '',
                $typeList[$row->task_type] ?? $row->task_type,
                $row->channel,
                $row->rights,
                $row->balance,
                $row->extra,
                $row->ip,
                $row->createtime ? date('Y-m-d H:i:s', $row->createtime) : '',
            ];
        }
        $this->exportXlsx('fanshub_task_' . date('Ymd_His'), [
            'ID', '会员ID', '手机号', '任务类型', '通道', '股份', '红宝', '备注', 'IP', '时间',
        ], $data);
    }
}
