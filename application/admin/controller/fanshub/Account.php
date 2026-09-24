<?php

namespace app\admin\controller\fanshub;

use app\admin\library\traits\FanshubExport;
use app\common\controller\Backend;
use app\common\library\FansHubMobile;
use app\common\library\FansHubOg;
use app\common\library\FansHubPhase2;
use app\common\library\FansHubService;
use think\Db;

/**
 * 福利用户账户
 *
 * @icon fa fa-user
 */
class Account extends Backend
{
    use FanshubExport;

    protected $model = null;
    protected $relationSearch = true;
    protected $searchFields = 'id,user_id,main_uid,user.nickname,user.mobile';

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Account;
        $this->view->assign('flowStageList', $this->model->getFlowStageList());
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->view->assign('memberLevelList', FansHubService::memberLevels());
        $memberLevelSearch = [];
        foreach (FansHubService::memberLevels() as $level => $item) {
            $memberLevelSearch[(string)(int)$level] = $item['name'];
        }
        $this->assignconfig('memberLevelList', $memberLevelSearch);
        $this->view->assign('userModeList', $this->model->getUserModeList());
        $this->view->assign('phase2Enabled', FansHubPhase2::enabled());
        $honorTierList = ['0' => '未晋升'];
        if (FansHubPhase2::enabled()) {
            foreach (FansHubPhase2::honorTiers() as $tier) {
                $honorTierList[(string)(int)$tier['id']] = $tier['name'];
            }
        }
        $this->view->assign('honorTierList', $honorTierList);
        $this->assignconfig('canHardDelete', $this->auth->check('fanshub/account/del'));
    }

    /**
     * 从上线搜索条件解析 invitee user_id 列表；无条件返回 null；无匹配返回 []
     * 同时从 filter/op 中剔除虚拟字段，避免 buildparams 查不存在的列
     * @return int[]|null
     */
    protected function pullInviterInviteeIds()
    {
        $filterRaw = $this->request->get('filter', '');
        $opRaw = $this->request->get('op', '');
        $filterArr = is_string($filterRaw) ? (json_decode($filterRaw, true) ?: []) : (is_array($filterRaw) ? $filterRaw : []);
        $opArr = is_string($opRaw) ? (json_decode($opRaw, true) ?: []) : (is_array($opRaw) ? $opRaw : []);

        $inviterUserId = isset($filterArr['inviter_user_id']) ? trim((string)$filterArr['inviter_user_id']) : '';
        $inviterMobile = isset($filterArr['inviter_mobile']) ? trim((string)$filterArr['inviter_mobile']) : '';
        unset($filterArr['inviter_user_id'], $filterArr['inviter_mobile']);
        unset($opArr['inviter_user_id'], $opArr['inviter_mobile']);
        $this->request->get([
            'filter' => json_encode($filterArr, JSON_UNESCAPED_UNICODE),
            'op'     => json_encode($opArr, JSON_UNESCAPED_UNICODE),
        ]);

        if ($inviterUserId === '' && $inviterMobile === '') {
            return null;
        }

        $q = Db::name('fans_invite')->alias('i');
        if ($inviterUserId !== '') {
            $q->where('i.inviter_user_id', (int)$inviterUserId);
        }
        if ($inviterMobile !== '') {
            $q->join('user u', 'u.id = i.inviter_user_id', 'INNER')
                ->where('u.mobile', 'like', '%' . $inviterMobile . '%');
        }
        $ids = $q->column('i.invitee_user_id');
        return array_values(array_unique(array_filter(array_map('intval', $ids ?: []))));
    }

    public function index()
    {
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }
            $inviteeIds = $this->pullInviterInviteeIds();
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            // 默认按注册/开户时间倒序（避免按主键 id 乱序）
            if ($sort === '' || $sort === null || $sort === 'id') {
                $sort = 'createtime';
                $order = $order ?: 'desc';
            }
            $query = $this->model
                ->with(['user'])
                ->where($where)
                ->where('is_bot', 0);
            if ($inviteeIds !== null) {
                if (!$inviteeIds) {
                    $query->where('user_id', 0);
                } else {
                    $query->where('user_id', 'in', $inviteeIds);
                }
            }
            $list = $query
                ->order($sort, $order)
                ->paginate($limit);
            $userIds = [];
            foreach ($list as $row) {
                $userIds[] = (int)$row->user_id;
                if ($row->getRelation('user')) {
                    $row->getRelation('user')->visible([
                        'id', 'mobile', 'nickname', 'username', 'avatar', 'jointime', 'createtime',
                        'joinip', 'loginip', 'logintime', 'status',
                    ]);
                }
                $u = $row->user;
                $nick = '';
                if ($u) {
                    $nick = trim((string)($u->nickname ?: $u->username ?: ''));
                }
                $row->nickname = $nick !== '' ? $nick : ('ID' . (int)$row->user_id);
                $avatar = $u ? (string)($u->avatar ?? '') : '';
                $row->avatar = function_exists('normalize_user_avatar')
                    ? normalize_user_avatar($avatar, true)
                    : $avatar;
                if ($u) {
                    $u->avatar = $row->avatar;
                }
                $row->jointime = $u && !empty($u->jointime) ? (int)$u->jointime : (int)($row->createtime ?: 0);
                $row->joinip = $u ? (string)($u->joinip ?? '') : '';
                $row->loginip = $u ? (string)($u->loginip ?? '') : '';
                $row->logintime = $u && !empty($u->logintime) ? (int)$u->logintime : 0;
            }
            $inviterMap = FansHubService::getInviterInfoMap($userIds);
            $pnlMap = $this->batchUserPnlMap($userIds, $list);
            foreach ($list as $row) {
                $info = $inviterMap[(int)$row->user_id] ?? null;
                $row->inviter_user_id = $info ? (int)$info['inviter_user_id'] : 0;
                $row->inviter_mobile = $info ? (string)$info['mobile'] : '';
                $pnl = $pnlMap[(int)$row->user_id] ?? null;
                $row->pnl_total_withdraw = $pnl ? $pnl['total_withdraw'] : '0.00';
                $row->pnl_balance = $pnl ? $pnl['balance'] : number_format(round((float)($row->hongbao ?? 0), 2), 2, '.', '');
                $row->pnl_total_recharge = $pnl ? $pnl['total_recharge'] : '0.00';
                $row->pnl_net = $pnl ? $pnl['net_pnl'] : '0.00';
            }
            $result = ['total' => $list->total(), 'rows' => $list->items()];
            return json($result);
        }
        return $this->view->fetch();
    }

    public function detail($ids = null)
    {
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        try {
            $detail = FansHubService::getAccountDetail($row->user_id);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
        $this->view->assign('row', $row);
        $this->view->assign('detail', $detail);
        return $this->view->fetch();
    }

    /**
     * 真删除用户账户（支持批量）：删除 fa_user + fa_fans_account 及关联数据，不可恢复
     */
    public function del($ids = null)
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $ids = $ids ?: $this->request->post('ids');
        if (empty($ids)) {
            $this->error(__('Parameter %s can not be empty', 'ids'));
        }
        $idList = is_array($ids) ? $ids : explode(',', (string)$ids);
        $idList = array_values(array_unique(array_filter(array_map('intval', $idList))));
        if (!$idList) {
            $this->error(__('Parameter %s can not be empty', 'ids'));
        }

        // 列表主键为 fans_account.id；仅删非机器人账户
        $rows = $this->model
            ->where('id', 'in', $idList)
            ->where('is_bot', 0)
            ->field('id,user_id')
            ->select();
        if (!$rows || count($rows) === 0) {
            $this->error(__('No Results were found'));
        }
        $userIds = [];
        foreach ($rows as $row) {
            $uid = (int)$row['user_id'];
            if ($uid > 0) {
                $userIds[] = $uid;
            }
        }
        $userIds = array_values(array_unique($userIds));
        if (!$userIds) {
            $this->error('未找到对应用户');
        }

        try {
            $result = FansHubService::hardDeleteUsers($userIds);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
        $this->success('已真删除 ' . (int)($result['deleted'] ?? count($userIds)) . ' 个用户', null, $result);
    }

    public function edit($ids = null)
    {
        $row = $this->model->get($ids);
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

        // 支付密码：清除 / 重置
        $clearPayPwd = !empty($params['clear_pay_password']);
        $clearGoogleSecret = !empty($params['clear_google_secret']);
        $newPayPwd = isset($params['pay_password']) ? trim((string)$params['pay_password']) : '';
        unset($params['clear_pay_password'], $params['pay_password'], $params['clear_google_secret']);
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

        // 红宝/股份余额禁止在编辑页改写，只能走 adjust 调账
        unset(
            $params['rights'],
            $params['balance'],
            $params['hongbao'],
            $params['rights_baseline'],
            $params['hongbao_baseline'],
            $params['rights_locked'],
            $params['rights_lock_day']
        );

        $meta = [];
        foreach (['main_uid', 'flow_stage', 'status', 'member_level', 'turnover', 'admin_remark'] as $field) {
            if (array_key_exists($field, $params)) {
                $meta[$field] = $params[$field];
            }
        }
        if (array_key_exists('admin_remark', $meta)) {
            $meta['admin_remark'] = mb_substr(trim((string)$meta['admin_remark']), 0, 500);
        }
        if ($clearGoogleSecret) {
            $meta['google_secret'] = '';
        } elseif (array_key_exists('google_secret', $params)) {
            $gaSecret = \app\common\library\FansHubGoogleAuth::normalizeSecret($params['google_secret']);
            if (trim((string)$params['google_secret']) !== '' && $gaSecret === '') {
                $this->error('谷歌验证器密钥格式无效（需 Base32）');
            }
            $meta['google_secret'] = $gaSecret;
        }
        if (array_key_exists('turnover', $meta)) {
            // 允许负数：待打流水可为负（超额打完）
            $meta['turnover'] = round((float)$meta['turnover'], 2);
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
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
        $this->success();
    }

    public function adjust($ids = null)
    {
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        if ($this->request->isPost()) {
            $hongbaoDelta = round((float)$this->request->post('hongbao_delta', 0), 2);
            // 兼容旧字段 balance_delta → 红宝
            $hongbaoDelta = round($hongbaoDelta + (float)$this->request->post('balance_delta', 0), 2);
            $remark = trim((string)$this->request->post('remark', '人工调账'));
            if ($hongbaoDelta == 0) {
                $this->error('请填写红宝调整数值');
            }
            try {
                FansHubService::changeAssets($row->user_id, 0, 0, 'admin_adjust', $remark ?: '人工调账', $this->auth->id, '', $hongbaoDelta);
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

    /**
     * 单独加减待打流水（不改红宝余额）
     */
    public function adjustturnover($ids = null)
    {
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        if ($this->request->isPost()) {
            $delta = round((float)$this->request->post('turnover_delta', 0), 2);
            $remark = trim((string)$this->request->post('remark', '人工加减流水'));
            if (abs($delta) < 0.005) {
                $this->error('请填写流水调整数值（正数增加待打流水，负数减少）');
            }
            $userId = (int)$row->user_id;
            $before = round((float)($row->turnover ?? 0), 2);
            $after = round($before + $delta, 2);
            $now = time();
            $adminId = (int)$this->auth->id;
            $ledgerRemark = sprintf(
                '加减流水 %+.2f（%.2f→%.2f）%s',
                $delta,
                $before,
                $after,
                $remark !== '' ? '；' . $remark : ''
            );
            Db::startTrans();
            try {
                $aff = Db::name('fans_account')
                    ->where('id', (int)$row->id)
                    ->where('user_id', $userId)
                    ->update([
                        'turnover'   => $after,
                        'updatetime' => $now,
                    ]);
                if ($aff <= 0) {
                    throw new \RuntimeException('更新流水失败');
                }
                $acc = Db::name('fans_account')->where('user_id', $userId)->find();
                Db::name('fans_ledger')->insert([
                    'user_id'         => $userId,
                    'type'            => 'admin_turnover',
                    'rights_change'   => 0,
                    'balance_change'  => 0,
                    'hongbao_change'  => 0,
                    'rights_after'    => (float)($acc['rights'] ?? 0),
                    'balance_after'   => (float)($acc['balance'] ?? 0),
                    'hongbao_after'   => (float)($acc['hongbao'] ?? 0),
                    'remark'          => mb_substr($ledgerRemark, 0, 255),
                    'channel'         => 'admin',
                    'admin_id'        => $adminId,
                    'createtime'      => $now,
                ]);
                Db::commit();
            } catch (\Throwable $e) {
                Db::rollback();
                $this->error($e->getMessage());
            }
            try {
                \app\common\library\FansHubImCache::bustWallet($userId);
            } catch (\Throwable $e) {
                // ignore cache
            }
            $this->success(sprintf('流水已调整：%.2f → %.2f', $before, $after));
        }
        $user = \app\common\model\User::get($row->user_id);
        $this->view->assign('row', $row);
        $this->view->assign('user', $user);
        return $this->view->fetch();
    }

    /**
     * 总输赢：总提款 − 当前余额 − 总充值
     */
    public function pnl($ids = null)
    {
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        $userId = (int)$row->user_id;
        $map = $this->batchUserPnlMap([$userId], [$row]);
        $pnl = $map[$userId] ?? [
            'total_withdraw' => '0.00',
            'balance'        => number_format(round((float)($row->hongbao ?? 0), 2), 2, '.', ''),
            'total_recharge' => '0.00',
            'net_pnl'        => '0.00',
        ];

        $user = \app\common\model\User::get($userId);
        $this->view->assign('row', $row);
        $this->view->assign('user', $user ?: []);
        $this->view->assign('pnl', $pnl);
        return $this->view->fetch();
    }

    /**
     * 批量计算用户总输赢（与 pnl 弹窗公式一致）
     * @param int[] $userIds
     * @param iterable $accountRows 含 user_id、hongbao 的账户行（当前页余额）
     * @return array<int,array{total_withdraw:string,balance:string,total_recharge:string,net_pnl:string}>
     */
    protected function batchUserPnlMap(array $userIds, $accountRows = [])
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        $balanceMap = [];
        foreach ($accountRows as $row) {
            $uid = (int)(is_array($row) ? ($row['user_id'] ?? 0) : ($row->user_id ?? 0));
            if ($uid <= 0) {
                continue;
            }
            $hb = is_array($row) ? ($row['hongbao'] ?? 0) : ($row->hongbao ?? 0);
            $balanceMap[$uid] = round((float)$hb, 2);
        }
        $out = [];
        foreach ($userIds as $uid) {
            $bal = $balanceMap[$uid] ?? 0.0;
            $out[$uid] = [
                'total_withdraw' => '0.00',
                'balance'        => number_format($bal, 2, '.', ''),
                'total_recharge' => '0.00',
                'net_pnl'        => number_format(round(0 - $bal - 0, 2), 2, '.', ''),
            ];
        }
        if (!$userIds) {
            return $out;
        }

        $prefix = config('database.prefix') ?: 'fa_';
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $sql = 'SELECT user_id, type,'
            . ' SUM(CASE WHEN ABS(IFNULL(hongbao_change,0)) > 1e-8 THEN hongbao_change ELSE IFNULL(balance_change,0) END) AS s'
            . ' FROM ' . $prefix . 'fans_ledger'
            . ' WHERE user_id IN (' . $placeholders . ')'
            . " AND type IN ('recharge','withdraw','withdraw_refund')"
            . ' GROUP BY user_id, type';
        try {
            $rows = Db::query($sql, $userIds);
        } catch (\Throwable $e) {
            return $out;
        }
        $sums = [];
        foreach ($rows ?: [] as $r) {
            $uid = (int)($r['user_id'] ?? 0);
            $type = (string)($r['type'] ?? '');
            if ($uid <= 0 || $type === '') {
                continue;
            }
            if (!isset($sums[$uid])) {
                $sums[$uid] = ['recharge' => 0.0, 'withdraw' => 0.0, 'withdraw_refund' => 0.0];
            }
            if (isset($sums[$uid][$type])) {
                $sums[$uid][$type] = (float)($r['s'] ?? 0);
            }
        }
        foreach ($userIds as $uid) {
            $s = $sums[$uid] ?? ['recharge' => 0.0, 'withdraw' => 0.0, 'withdraw_refund' => 0.0];
            $bal = $balanceMap[$uid] ?? 0.0;
            $totalWithdraw = round(max(0, abs((float)$s['withdraw']) - max(0, (float)$s['withdraw_refund'])), 2);
            $totalRecharge = round(max(0, (float)$s['recharge']), 2);
            $netPnl = round($totalWithdraw - $bal - $totalRecharge, 2);
            $out[$uid] = [
                'total_withdraw' => number_format($totalWithdraw, 2, '.', ''),
                'balance'        => number_format($bal, 2, '.', ''),
                'total_recharge' => number_format($totalRecharge, 2, '.', ''),
                'net_pnl'        => number_format($netPnl, 2, '.', ''),
            ];
        }
        return $out;
    }

    /**
     * 查询用户 OG 视讯筹码余额
     */
    public function ogbalance($ids = null)
    {
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        try {
            $data = FansHubOg::balanceForUser((int)$row->user_id);
        } catch (\Throwable $e) {
            $this->error($e->getMessage() ?: 'OG余额查询失败');
        }
        $bal = (string)($data['current_balance'] ?? '0');
        $pid = (string)($data['player_id'] ?? '');
        $hb = isset($data['hongbao']) ? (string)$data['hongbao'] : '';
        $this->success(
            'OG余额 ' . $bal . '（player_id=' . $pid . ($hb !== '' ? '，本站红宝=' . $hb : '') . '）',
            null,
            $data
        );
    }

    /**
     * 晋升团长：用户态=团长，荣誉段位=青铜团长
     */
    public function promotemaster($ids = null)
    {
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        if (!FansHubPhase2::enabled()) {
            $this->error('团长二期功能未开启');
        }
        try {
            $result = FansHubPhase2::adminPromoteToMaster($row->user_id);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
        $name = $result['honor_tier_name'] ?? '青铜团长';
        $this->success('已晋升为团长（' . $name . '）', null, $result);
    }

    /**
     * 聊天禁言（可分项禁止发文字/图/表情/视频/发红包/领红包）
     */
    public function chatforbid($ids = null)
    {
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
            $flagDefs = [
            'text'    => '禁止发文字',
            'image'   => '禁止发图片',
            'sticker' => '禁止发表情',
            'video'   => '禁止发视频',
            'file'    => '禁止发文件',
            'rp_send' => '禁止发红包',
            'rp_grab' => '禁止领红包',
        ];
        if ($this->request->isPost()) {
            $posted = $this->request->post('forbid/a');
            if (!is_array($posted)) {
                $posted = [];
            }
            $flags = [];
            foreach (array_keys($flagDefs) as $k) {
                if (!empty($posted[$k])) {
                    $flags[$k] = 1;
                }
            }
            // 禁言（禁止发文字）时同步禁止领红包
            if (!empty($flags['text'])) {
                $flags['rp_grab'] = 1;
            }
            $encoded = $flags ? json_encode($flags, JSON_UNESCAPED_UNICODE) : '';
            Db::name('fans_account')->where('id', $row->id)->update([
                'chat_forbid' => $encoded,
                'updatetime'  => time(),
            ]);
            $this->syncChatForbidRedis((int)$row->user_id, $flags);
            $this->success($flags ? '禁言已更新' : '已取消全部聊天限制');
        }
        $current = [];
        $raw = (string)($row->chat_forbid ?? '');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $current = $decoded;
            }
        }
        $user = \app\common\model\User::get($row->user_id);
        $this->view->assign('row', $row);
        $this->view->assign('user', $user);
        $this->view->assign('flagDefs', $flagDefs);
        $this->view->assign('current', $current);
        return $this->view->fetch();
    }

    /**
     * 封禁 / 解封登录：封禁后立即踢下线且不可再登录
     */
    public function ban($ids = null)
    {
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        $uid = (int)$row->user_id;
        if ($uid <= 0) {
            $this->error('用户无效');
        }
        try {
            if (class_exists('\app\common\library\FansHubDefaultCs')
                && \app\common\library\FansHubDefaultCs::isDefaultCs($uid)) {
                $this->error('默认客服不可封禁');
            }
        } catch (\Throwable $e) {
            if ($uid === 88888888) {
                $this->error('默认客服不可封禁');
            }
        }
        $user = \app\common\model\User::get($uid);
        if (!$user) {
            $this->error('主站用户不存在');
        }
        $now = time();
        $cur = (string)($user->status ?? '');
        if ($cur === 'normal') {
            $oldTokens = Db::name('user_token')->where('user_id', $uid)->column('token');
            if (!is_array($oldTokens)) {
                $oldTokens = [];
            }
            Db::name('user')->where('id', $uid)->update([
                'status'     => 'hidden',
                'updatetime' => $now,
            ]);
            try {
                \app\common\library\Token::clear($uid);
            } catch (\Throwable $e) {
            }
            try {
                FansHubService::forceKickOffline($uid, array_values($oldTokens), '账号已被封禁', 'banned');
            } catch (\Throwable $e) {
            }
            $this->success('已封禁并踢下线，该账号无法再登录');
        }
        Db::name('user')->where('id', $uid)->update([
            'status'     => 'normal',
            'updatetime' => $now,
        ]);
        $this->success('已解除封禁，可重新登录');
    }

    /**
     * 同步到 IM Redis，使禁言立即生效
     */
    protected function syncChatForbidRedis($userId, array $flags)
    {
        if (!class_exists('\Redis')) {
            return;
        }
        $redisCfg = [
            'host'     => '127.0.0.1',
            'port'     => 6379,
            'password' => '',
            'db'       => 2,
            'prefix'   => 'im:',
        ];
        $imApp = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'im-server' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
        if (!is_file($imApp)) {
            $imApp = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'im-server' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
        }
        if (is_file($imApp)) {
            $appCfg = include $imApp;
            if (isset($appCfg['redis']) && is_array($appCfg['redis'])) {
                $redisCfg = array_merge($redisCfg, $appCfg['redis']);
            }
        }
        try {
            $r = new \Redis();
            if (!$r->connect($redisCfg['host'] ?? '127.0.0.1', (int)($redisCfg['port'] ?? 6379), 2.0)) {
                return;
            }
            if (!empty($redisCfg['password'])) {
                $r->auth($redisCfg['password']);
            }
            $r->select((int)($redisCfg['db'] ?? 2));
            $key = ((string)($redisCfg['prefix'] ?? 'im:')) . 'chat_forbid:' . (int)$userId;
            if ($flags) {
                $payload = [];
                foreach ($flags as $k => $v) {
                    if (!empty($v)) {
                        $payload[$k] = true;
                    }
                }
                $r->setex($key, 86400 * 7, json_encode($payload, JSON_UNESCAPED_UNICODE));
            } else {
                $r->setex($key, 60, '{}');
            }
            $r->close();
        } catch (\Throwable $e) {
        }
    }

    public function export()
    {
        $this->request->filter(['strip_tags', 'trim']);
        $inviteeIds = $this->pullInviterInviteeIds();
        list($where, $sort, $order) = $this->buildparams();
        $query = $this->model->with(['user'])->where($where)->where('is_bot', 0);
        if ($inviteeIds !== null) {
            if (!$inviteeIds) {
                $query->where('user_id', 0);
            } else {
                $query->where('user_id', 'in', $inviteeIds);
            }
        }
        $rows = $this->exportQueryRows($query->order($sort, $order));
        $stageList = $this->model->getFlowStageList();
        $statusList = $this->model->getStatusList();
        $uidAuditList = $this->model->getUidAuditList();
        $levelList = FansHubService::memberLevels();
        $userIds = [];
        foreach ($rows as $row) {
            $userIds[] = (int)$row->user_id;
        }
        $inviterMap = FansHubService::getInviterInfoMap($userIds);
        $data = [];
        foreach ($rows as $row) {
            $levelId = (int)($row->member_level ?? 0);
            $levelName = isset($levelList[$levelId]) ? ('VIP' . $levelId . ' ' . $levelList[$levelId]['name']) : ($levelId > 0 ? ('VIP' . $levelId) : '');
            $inv = $inviterMap[(int)$row->user_id] ?? null;
            list($dial, $national) = FansHubMobile::splitDialNational($row->user ? $row->user->mobile : '');
            list($invDial, $invNational) = FansHubMobile::splitDialNational($inv ? ($inv['mobile'] ?? '') : '');
            $data[] = [
                $row->id,
                $row->user ? ($row->user->nickname ?: '') : '',
                $dial,
                $national,
                $inv ? $inv['inviter_user_id'] : '',
                $invDial,
                $invNational,
                $row->rights,
                $row->hongbao ?? 0,
                $row->main_uid,
                $row->main_uid_pending ?? '',
                $uidAuditList[$row->main_uid_audit ?? ''] ?? ($row->main_uid_audit ?? ''),
                $levelName,
                $stageList[$row->flow_stage] ?? $row->flow_stage,
                $statusList[$row->status] ?? $row->status,
                $row->createtime ? date('Y-m-d H:i:s', $row->createtime) : '',
                $row->updatetime ? date('Y-m-d H:i:s', $row->updatetime) : '',
            ];
        }
        $this->exportXlsx('fanshub_account_' . date('Ymd_His'), [
            '会员ID', '昵称', '区号', '手机号', '上线ID', '上线区号', '上线手机', '股份', '红宝', '主站账号', '待审账号', '账号审核', 'VIP等级', '阶段', '状态', '创建时间', '更新时间',
        ], $data);
    }
}
