<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubPhase2;
use app\common\library\FansHubService;
use think\Db;

/**
 * 机器人账户（会员运营）
 *
 * @icon fa fa-android
 */
class Robotaccount extends Backend
{
    protected $model = null;
    protected $relationSearch = true;
    protected $searchFields = 'id,user.nickname,user.mobile';

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Account;
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->view->assign('flowStageList', $this->model->getFlowStageList());
        $this->view->assign('memberLevelList', FansHubService::memberLevels());
        $this->view->assign('userModeList', $this->model->getUserModeList());
        $this->view->assign('phase2Enabled', FansHubPhase2::enabled());
        $honorTierList = ['0' => '未晋升'];
        if (FansHubPhase2::enabled()) {
            foreach (FansHubPhase2::honorTiers() as $tier) {
                $honorTierList[(string)(int)$tier['id']] = $tier['name'];
            }
        }
        $this->view->assign('honorTierList', $honorTierList);
    }

    public function index()
    {
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            if ($sort === '' || $sort === null || $sort === 'id') {
                $sort = 'createtime';
                $order = $order ?: 'desc';
            }
            $list = $this->model
                ->with(['user'])
                ->where($where)
                ->where('is_bot', 1)
                ->order($sort, $order)
                ->paginate($limit);
            foreach ($list as $row) {
                if ($row->getRelation('user')) {
                    $row->getRelation('user')->visible(['id', 'mobile', 'nickname', 'username', 'jointime']);
                }
                $u = $row->user;
                $nick = $u ? trim((string)($u->nickname ?: $u->username ?: '')) : '';
                $row->nickname = $nick !== '' ? $nick : ('ID' . (int)$row->user_id);
            }
            return json(['total' => $list->total(), 'rows' => $list->items()]);
        }
        return $this->view->fetch();
    }

    /**
     * 批量加红宝（勾选 ids 或自定义逗号 ID 列表）
     */
    public function batchadjust()
    {
        if (!$this->request->isPost()) {
            $ids = (string)$this->request->get('ids', '');
            $this->view->assign('ids', $ids);
            return $this->view->fetch();
        }
        $idsRaw = (string)$this->request->post('ids', '');
        $idsRaw = str_replace(["\xef\xbc\x8c", '、', '|', "\n", "\r", ' '], ',', $idsRaw);
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $idsRaw)))));
        $hongbaoDelta = round((float)$this->request->post('hongbao_delta', 0), 2);
        $remark = trim((string)$this->request->post('remark', '机器人批量加余额'));
        if (!$ids) {
            $this->error('请选择或填写会员ID');
        }
        if (abs($hongbaoDelta) < 1e-8) {
            $this->error('请填写红宝调整数值');
        }
        if ($remark === '') {
            $remark = '机器人批量加余额';
        }

        $ok = 0;
        $fail = [];
        foreach ($ids as $uid) {
            $acc = $this->model->where('user_id', $uid)->where('is_bot', 1)->find();
            if (!$acc) {
                $fail[] = $uid . '(非机器人或不存在)';
                continue;
            }
            try {
                FansHubService::changeAssets($uid, 0, 0, 'admin_adjust', $remark, $this->auth->id, '', $hongbaoDelta);
                $ok++;
            } catch (\Throwable $e) {
                $fail[] = $uid . '(' . $e->getMessage() . ')';
            }
        }
        $msg = "成功 {$ok} 个";
        if ($fail) {
            $msg .= '；失败 ' . count($fail) . '：' . implode('、', array_slice($fail, 0, 8));
            if (count($fail) > 8) {
                $msg .= '…';
            }
        }
        if ($ok <= 0) {
            $this->error($msg);
        }
        $this->success($msg);
    }

    /**
     * 注册一批机器人（默认 300，手机号从 10000000001 起，红宝 10 万）
     */
    public function seed()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }
        $count = (int)$this->request->post('count', 300);
        $start = trim((string)$this->request->post('start_mobile', '10000000001'));
        $hongbao = (float)$this->request->post('hongbao', 100000);
        if ($start === '') {
            $start = '10000000001';
        }
        try {
            $ret = FansHubService::seedBotUsers($count, $start, $hongbao);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
        $n = count($ret['created']);
        $s = count($ret['skipped']);
        $e = count($ret['errors']);
        $ids = [];
        foreach ($ret['created'] as $row) {
            $ids[] = (int)$row['user_id'];
        }
        $this->success(
            "新建 {$n}，跳过已存在 {$s}，失败 {$e}",
            null,
            [
                'created' => $n,
                'skipped' => $s,
                'errors'  => $e,
                'ids'     => implode(',', $ids),
                'error_detail' => array_slice($ret['errors'], 0, 10),
            ]
        );
    }

    /**
     * 编辑机器人账户（字段与普通会员编辑一致）
     */
    public function edit($ids = null)
    {
        $row = $this->model->where('id', (int)$ids)->where('is_bot', 1)->find();
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        if (!$this->request->isPost()) {
            $user = \app\common\model\User::get($row->user_id);
            $this->view->assign('row', $row);
            $this->view->assign('userRow', $user ?: []);
            $this->view->assign('inviter', FansHubService::getInviterInfo($row->user_id));
            return $this->view->fetch();
        }
        $params = $this->request->post('row/a');
        if (empty($params)) {
            $this->error(__('Parameter %s can not be empty', ''));
        }

        $userParams = $this->request->post('user/a');
        $userUpdate = [];
        if (is_array($userParams)) {
            if (isset($userParams['nickname'])) {
                $nick = trim((string)$userParams['nickname']);
                if ($nick === '') {
                    $this->error('昵称不能为空');
                }
                if (mb_strlen($nick) > 30) {
                    $this->error('昵称最多30个字');
                }
                $userUpdate['nickname'] = $nick;
            }
            if (array_key_exists('avatar', $userParams)) {
                $userUpdate['avatar'] = trim((string)$userParams['avatar']);
            }
            if (!empty($userParams['password'])) {
                $pwd = (string)$userParams['password'];
                if (strlen($pwd) < 6 || strlen($pwd) > 32) {
                    $this->error('密码长度需为6-32位');
                }
                $salt = \fast\Random::alnum();
                $userUpdate['password'] = md5(md5($pwd) . $salt);
                $userUpdate['salt'] = $salt;
            }
            if ($userUpdate) {
                $userUpdate['updatetime'] = time();
            }
        }

        $clearPayPwd = !empty($params['clear_pay_password']);
        $newPayPwd = isset($params['pay_password']) ? trim((string)$params['pay_password']) : '';
        unset($params['clear_pay_password'], $params['pay_password']);
        $payPwdMeta = [];
        if ($clearPayPwd) {
            $payPwdMeta['pay_password'] = '';
            $payPwdMeta['pay_salt'] = '';
        } elseif ($newPayPwd !== '') {
            if (strlen($newPayPwd) < 6 || strlen($newPayPwd) > 32) {
                $this->error('支付密码长度需为6-32位');
            }
            $paySalt = \fast\Random::alnum();
            $payPwdMeta['pay_password'] = md5(md5($newPayPwd) . $paySalt);
            $payPwdMeta['pay_salt'] = $paySalt;
        }

        $oldRights = (float)$row->rights;
        $oldHongbao = (float)($row->hongbao ?? 0) + (float)($row->balance ?? 0);
        $newRights = isset($params['rights']) ? round((float)$params['rights'], 2) : $oldRights;
        $newHongbao = isset($params['hongbao']) ? round((float)$params['hongbao'], 2) : $oldHongbao;
        $rightsDelta = round($newRights - $oldRights, 2);
        $hongbaoDelta = round($newHongbao - $oldHongbao, 2);
        unset($params['rights'], $params['balance'], $params['hongbao']);

        $meta = [];
        foreach (['main_uid', 'flow_stage', 'status', 'member_level', 'turnover'] as $field) {
            if (array_key_exists($field, $params)) {
                $meta[$field] = $params[$field];
            }
        }
        if (array_key_exists('turnover', $meta)) {
            $meta['turnover'] = max(0, round((float)$meta['turnover'], 2));
        }
        if (FansHubPhase2::enabled()) {
            foreach (['user_mode', 'fission_streak_days', 'fission_last_checkin_date', 'sub_withdrawn_count', 'honor_tier_claimed'] as $field) {
                if (array_key_exists($field, $params)) {
                    $meta[$field] = $params[$field];
                }
            }
            foreach (['fission_streak_qualified', 'first_withdraw_done'] as $field) {
                if (array_key_exists($field, $params)) {
                    $meta[$field] = !empty($params[$field]) ? 1 : 0;
                }
            }
            if (array_key_exists('fission_last_checkin_date', $meta)) {
                $date = trim((string)$meta['fission_last_checkin_date']);
                $meta['fission_last_checkin_date'] = ($date === '' || $date === '0000-00-00') ? null : $date;
            }
            if (array_key_exists('fission_streak_days', $meta)) {
                $meta['fission_streak_days'] = max(0, (int)$meta['fission_streak_days']);
            }
            if (array_key_exists('sub_withdrawn_count', $meta)) {
                $meta['sub_withdrawn_count'] = max(0, (int)$meta['sub_withdrawn_count']);
            }
            if (array_key_exists('honor_tier_claimed', $meta)) {
                $meta['honor_tier_claimed'] = max(0, (int)$meta['honor_tier_claimed']);
            }
            if (isset($meta['user_mode']) && !in_array($meta['user_mode'], ['newbie', 'master'], true)) {
                $this->error('用户态无效');
            }
        }
        if (array_key_exists('main_uid', $meta)) {
            $newUid = trim((string)$meta['main_uid']);
            if ($newUid !== '' && $newUid !== (string)$row->main_uid) {
                $meta['main_uid'] = FansHubService::verifyMainUid($row->user_id, $newUid);
                $meta['main_uid_audit'] = 'approved';
                $meta['main_uid_pending'] = '';
                $meta['main_uid_reject_reason'] = '';
            } else {
                $meta['main_uid'] = $newUid;
                if ($newUid === '') {
                    $meta['main_uid_audit'] = '';
                    $meta['main_uid_pending'] = '';
                    $meta['main_uid_reject_reason'] = '';
                }
            }
        }
        if ($payPwdMeta) {
            $meta = array_merge($meta, $payPwdMeta);
        }

        $inviterRef = $this->request->post('inviter_ref', null);
        if ($inviterRef === null && is_array($params) && array_key_exists('inviter_ref', $params)) {
            $inviterRef = $params['inviter_ref'];
        }

        Db::startTrans();
        try {
            if ($userUpdate) {
                Db::name('user')->where('id', (int)$row->user_id)->update($userUpdate);
            }
            if ($meta) {
                $row->allowField(array_keys($meta))->save($meta);
            }
            if ($inviterRef !== null) {
                FansHubService::adminSetInviter($row->user_id, $inviterRef);
            }
            if ($rightsDelta != 0 || $hongbaoDelta != 0) {
                $remark = trim((string)$this->request->post('adjust_remark', '编辑机器人账户'));
                if ($remark === '') {
                    $remark = '编辑机器人账户';
                }
                FansHubService::changeAssets(
                    $row->user_id,
                    $rightsDelta,
                    0,
                    'admin_adjust',
                    $remark,
                    $this->auth->id,
                    '',
                    $hongbaoDelta
                );
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
        $this->success();
    }

    public function adjust($ids = null)
    {
        $row = $this->model->where('id', (int)$ids)->where('is_bot', 1)->find();
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        if ($this->request->isPost()) {
            $hongbaoDelta = (float)$this->request->post('hongbao_delta', 0);
            $remark = trim((string)$this->request->post('remark', '机器人调账'));
            if (abs($hongbaoDelta) < 1e-8) {
                $this->error('请填写红宝调整数值');
            }
            try {
                FansHubService::changeAssets($row->user_id, 0, 0, 'admin_adjust', $remark ?: '机器人调账', $this->auth->id, '', $hongbaoDelta);
            } catch (\Throwable $e) {
                $this->error($e->getMessage());
            }
            $this->success('调账成功');
        }
        $user = \app\common\model\User::get($row->user_id);
        $this->view->assign('row', $row);
        $this->view->assign('user', $user);
        return $this->view->fetch();
    }
}
