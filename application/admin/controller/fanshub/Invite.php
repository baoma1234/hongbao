<?php

namespace app\admin\controller\fanshub;

use app\admin\library\traits\FanshubExport;
use app\common\controller\Backend;
use app\common\library\FansHubService;

/**
 * 邀请记录
 *
 * @icon fa fa-share-alt
 */
class Invite extends Backend
{
    use FanshubExport;

    protected $model = null;
    protected $relationSearch = true;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\common\model\fanshub\Invite;
    }

    public function index()
    {
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $list = $this->model
                ->with(['inviter', 'invitee'])
                ->where($where)
                ->order($sort, $order)
                ->paginate($limit);
            $result = ['total' => $list->total(), 'rows' => $list->items()];
            return json($result);
        }
        return $this->view->fetch();
    }

    public function leaderboard()
    {
        if ($this->request->isAjax()) {
            $limit = (int)$this->request->get('limit', 50);
            $rows = FansHubService::inviteLeaderboard($limit);
            return json(['total' => count($rows), 'rows' => $rows]);
        }
        return $this->view->fetch();
    }

    public function export()
    {
        $this->request->filter(['strip_tags', 'trim']);
        list($where, $sort, $order) = $this->buildparams();
        $rows = $this->exportQueryRows(
            $this->model->with(['inviter', 'invitee'])->where($where)->order($sort, $order)
        );
        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                $row->id,
                $row->inviter_user_id,
                $row->inviter ? $row->inviter->mobile : '',
                $row->invitee_user_id,
                $row->invitee ? $row->invitee->mobile : '',
                $row->invitee_ip,
                $row->inviter_ip,
                $row->createtime ? date('Y-m-d H:i:s', $row->createtime) : '',
            ];
        }
        $this->exportXlsx('fanshub_invite_' . date('Ymd_His'), [
            'ID', '邀请人ID', '邀请人手机', '被邀请人ID', '被邀请人手机', '被邀请IP', '邀请人IP', '时间',
        ], $data);
    }
}
