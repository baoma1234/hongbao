<?php

namespace app\admin\controller\fanshub;

use app\admin\library\traits\FanshubExport;
use app\common\controller\Backend;
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
    protected $searchFields = 'id,main_uid,user.nickname,user.mobile';

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
    }

    public function index()
    {
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            // 默认按注册/开户时间倒序（避免按主键 id 乱序）
            if ($sort === '' || $sort === null || $sort === 'id') {
                $sort = 'createtime';
                $order = $order ?: 'desc';
            }
            $list = $this->model
                ->with(['user'])
                ->where($where)
                ->where('is_bot', 0)
                ->order($sort, $order)
                ->paginate($limit);
            $userIds = [];
            foreach ($list as $row) {
                $userIds[] = (int)$row->user_id;
                if ($row->getRelation('user')) {
                    $row->getRelation('user')->visible(['id', 'mobile', 'nickname', 'username', 'jointime', 'createtime']);
                }
                $u = $row->user;
                $nick = '';
                if ($u) {
                    $nick = trim((string)($u->nickname ?: $u->username ?: ''));
                }
                $row->nickname = $nick !== '' ? $nick : ('ID' . (int)$row->user_id);
                $row->jointime = $u && !empty($u->jointime) ? (int)$u->jointime : (int)($row->createtime ?: 0);
            }
            $inviterMap = FansHubService::getInviterInfoMap($userIds);
            foreach ($list as $row) {
                $info = $inviterMap[(int)$row->user_id] ?? null;
                $row->inviter_user_id = $info ? (int)$info['inviter_user_id'] : 0;
                $row->inviter_mobile = $info ? (string)$info['mobile'] : '';
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

        $oldRights = (float)$row->rights;
        $oldHongbao = (float)($row->hongbao ?? 0) + (float)($row->balance ?? 0);
        $newRights = isset($params['rights']) ? round((float)$params['rights'], 2) : $oldRights;
        $newHongbao = isset($params['hongbao']) ? round((float)$params['hongbao'], 2) : $oldHongbao;
        $rightsDelta = round($newRights - $oldRights, 2);
        $balanceDelta = 0.0;
        $hongbaoDelta = round($newHongbao - $oldHongbao, 2);
        unset($params['rights'], $params['balance'], $params['hongbao']);

        $meta = [];
        foreach (['main_uid', 'flow_stage', 'status', 'member_level', 'turnover'] as $field) {
            if (array_key_exists($field, $params)) {
                $meta[$field] = $params[$field];
            }
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
            if ($rightsDelta != 0 || $balanceDelta != 0 || $hongbaoDelta != 0) {
                $remark = trim((string)$this->request->post('adjust_remark', '编辑账户调整'));
                if ($remark === '') {
                    $remark = '编辑账户调整';
                }
                FansHubService::changeAssets(
                    $row->user_id,
                    $rightsDelta,
                    $balanceDelta,
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
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        if ($this->request->isPost()) {
            $rightsDelta = (float)$this->request->post('rights_delta', 0);
            $hongbaoDelta = (float)$this->request->post('hongbao_delta', 0);
            // 兼容旧字段 balance_delta → 红宝
            $hongbaoDelta += (float)$this->request->post('balance_delta', 0);
            $remark = trim((string)$this->request->post('remark', '人工调账'));
            if ($rightsDelta == 0 && $hongbaoDelta == 0) {
                $this->error('请填写调整数值');
            }
            try {
                FansHubService::changeAssets($row->user_id, $rightsDelta, 0, 'admin_adjust', $remark, $this->auth->id, '', $hongbaoDelta);
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
        list($where, $sort, $order) = $this->buildparams();
        $rows = $this->exportQueryRows(
            $this->model->with(['user'])->where($where)->where('is_bot', 0)->order($sort, $order)
        );
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
            $data[] = [
                $row->id,
                $row->user ? ($row->user->nickname ?: '') : '',
                $row->user ? $row->user->mobile : '',
                $inv ? $inv['inviter_user_id'] : '',
                $inv ? $inv['mobile'] : '',
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
            '会员ID', '昵称', '手机号', '上线ID', '上线手机', '股份', '红宝', '主站账号', '待审账号', '账号审核', 'VIP等级', '阶段', '状态', '创建时间', '更新时间',
        ], $data);
    }
}
