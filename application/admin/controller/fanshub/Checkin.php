<?php

namespace app\admin\controller\fanshub;

use app\admin\library\traits\FanshubExport;
use app\common\controller\Backend;

/**
 * 星火签到记录
 *
 * @icon fa fa-calendar-check-o
 */
class Checkin extends Backend
{
    use FanshubExport;

    protected $model = null;
    protected $relationSearch = true;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Checkin;
        $this->view->assign('modeList', $this->model->getModeList());
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
        $modeList = $this->model->getModeList();
        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                $row->id,
                $row->user_id,
                $row->user ? $row->user->mobile : '',
                $row->checkin_date,
                $modeList[$row->mode] ?? $row->mode,
                $row->base_amount,
                $row->bonus_amount,
                $row->bonus_unlocked ? '是' : '否',
                $row->streak_day,
                $row->day7_settled ? '是' : '否',
                $row->createtime ? date('Y-m-d H:i:s', $row->createtime) : '',
            ];
        }
        $this->exportXlsx('fanshub_checkin_' . date('Ymd_His'), [
            'ID', '会员ID', '手机号', '签到日', '模式', '基础金额', '暴击金额', '暴击已解锁', '连续天', '第7天已结算', '记录时间',
        ], $data);
    }
}
