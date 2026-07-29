<?php

namespace app\admin\controller\fanshub;

use app\admin\library\traits\FanshubExport;
use app\common\controller\Backend;
use app\common\library\FansHubService;

/**
 * 游戏账号审核（主站账号核销）
 *
 * @icon fa fa-id-card
 */
class Uidaudit extends Backend
{
    use FanshubExport;

    protected $model = null;
    protected $relationSearch = true;
    protected $searchFields = 'id,user_id,main_uid,main_uid_pending';

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Account;
        $uidAuditList = $this->model->getUidAuditList();
        unset($uidAuditList['']);
        $this->view->assign('uidAuditList', $uidAuditList);
        $this->assignconfig('uidAuditList', $uidAuditList);
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
                ->where(function ($query) {
                    $query->where('main_uid_audit', 'in', ['pending', 'approved', 'rejected'])
                        ->whereOr('main_uid_pending', '<>', '');
                })
                ->order($sort, $order)
                ->paginate($limit);
            foreach ($list as $row) {
                if ($row->getRelation('user')) {
                    $row->getRelation('user')->visible(['id', 'mobile', 'nickname']);
                }
            }
            $result = ['total' => $list->total(), 'rows' => $list->items()];
            return json($result);
        }
        return $this->view->fetch();
    }

    public function approve($ids = null)
    {
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        try {
            // 必须请求 SugarCRM，mobilestatus=Verified 才可核销通过
            FansHubService::approveMainUid($row->user_id);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
        $this->success('已核销通过（SugarCRM 已验证）');
    }

    /**
     * 强制通过核销：不请求 SugarCRM，人工确认后使用
     */
    public function forceapprove($ids = null)
    {
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        try {
            FansHubService::approveMainUid($row->user_id, ['skip_sugarcrm' => true]);
            \think\Log::write(sprintf(
                'UID forceapprove admin=%s account_id=%s user_id=%s pending=%s',
                $this->auth->id ?? 0,
                $row->id,
                $row->user_id,
                $row->main_uid_pending ?? ''
            ), 'notice');
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
        $this->success('已强制核销通过（未校验 SugarCRM）');
    }

    public function reject($ids = null)
    {
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        $reason = trim((string)$this->request->post('reason', $this->request->request('reason', '')));
        try {
            FansHubService::rejectMainUid($row->user_id, $reason);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
        $this->success('已拒绝');
    }

    public function export()
    {
        $this->request->filter(['strip_tags', 'trim']);
        list($where, $sort, $order) = $this->buildparams();
        $rows = $this->exportQueryRows(
            $this->model->with(['user'])
                ->where($where)
                ->where(function ($query) {
                    $query->where('main_uid_audit', 'in', ['pending', 'approved', 'rejected'])
                        ->whereOr('main_uid_pending', '<>', '');
                })
                ->order($sort, $order)
        );
        $uidAuditList = $this->model->getUidAuditList();
        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                $row->id,
                $row->user_id,
                $row->user ? $row->user->mobile : '',
                $row->main_uid_pending ?? '',
                $row->main_uid,
                $uidAuditList[$row->main_uid_audit ?? ''] ?? ($row->main_uid_audit ?? ''),
                $row->main_uid_reject_reason ?? '',
                $row->updatetime ? date('Y-m-d H:i:s', $row->updatetime) : '',
            ];
        }
        $this->exportXlsx('fanshub_uidaudit_' . date('Ymd_His'), [
            'ID', '会员ID', '手机号', '待审账号', '已通过账号', '审核状态', '拒绝原因', '更新时间',
        ], $data);
    }
}
