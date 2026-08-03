<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubService;
use think\Db;

/**
 * IM 群组管理
 *
 * @icon fa fa-users
 */
class Imgroup extends Backend
{
    protected $noNeedRight = [];

    public function _initialize()
    {
        parent::_initialize();
        $this->view->assign('localeList', FansHubService::i18nLocaleCodes());
    }

    protected function decodeNoticeI18n($raw)
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

    protected function encodeNoticeI18n($raw)
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
            $out[$k] = mb_substr($v, 0, 500);
        }
        return json_encode($out, JSON_UNESCAPED_UNICODE);
    }
    public function index()
    {
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            $sort = $this->request->get('sort', 'weigh');
            $order = $this->request->get('order', 'desc');
            $offset = (int)$this->request->get('offset', 0);
            $limit = (int)$this->request->get('limit', 20);
            $filter = $this->request->get('filter', '');
            $op = $this->request->get('op', '');
            $where = [];
            $filterArr = $filter ? (array)json_decode($filter, true) : [];
            if (!empty($filterArr['name'])) {
                $where[] = ['name', 'like', '%' . $filterArr['name'] . '%'];
            }
            if (isset($filterArr['status']) && $filterArr['status'] !== '') {
                $where[] = ['status', '=', (int)$filterArr['status']];
            }
            $total = Db::name('chat_groups')->where($where)->count();
            $list = Db::name('chat_groups')
                ->where($where)
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();
            foreach ($list as &$row) {
                $row['owner_label'] = $this->userLabel((int)$row['owner_user_id']);
                $admins = Db::name('chat_group_members')
                    ->where(['group_id' => $row['id'], 'role' => 2, 'status' => 1])
                    ->column('user_id');
                $row['admin_user_ids'] = implode(',', $admins);
                $row['admin_labels'] = $admins
                    ? implode('、', array_map([$this, 'userLabel'], $admins))
                    : '-';
            }
            unset($row);
            return json(['total' => $total, 'rows' => $list]);
        }
        return $this->view->fetch();
    }

    public function add()
    {
        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');
            $name = trim((string)($params['name'] ?? ''));
            $ownerId = (int)($params['owner_user_id'] ?? 0);
            $adminIds = $this->parseIdList($params['admin_user_ids'] ?? '');
            $memberIds = $this->parseIdList($params['member_user_ids'] ?? '');
            $notice = trim((string)($params['notice'] ?? ''));
            $noticeI18n = $this->encodeNoticeI18n($params['notice_i18n'] ?? []);
            if ($name === '' || $ownerId <= 0) {
                $this->error('请填写群名称和群主会员ID');
            }
            if (!Db::name('user')->where('id', $ownerId)->find()) {
                $this->error('群主会员不存在');
            }
            $now = time();
            // 新建群自动拉入默认机器人（抢包仍只看后台自动任务配置）
            $robotUid = (int)\app\common\library\FansHubRedPacket::get('group_robot_user_id', 74282747);
            if ($robotUid > 0) {
                $memberIds[] = $robotUid;
            }
            $all = array_values(array_unique(array_merge([$ownerId], $adminIds, $memberIds)));
            Db::startTrans();
            try {
                $groupId = Db::name('chat_groups')->insertGetId([
                    'name'          => mb_substr($name, 0, 64),
                    'avatar'        => '',
                    'owner_user_id' => $ownerId,
                    'notice'        => mb_substr($notice, 0, 500),
                    'notice_i18n'   => $noticeI18n,
                    'member_count'  => count($all),
                    'max_members'   => 500,
                    'is_recommend'  => ((int)($params['is_recommend'] ?? 0) === 1) ? 1 : 0,
                    'weigh'         => (int)($params['weigh'] ?? 0),
                    'rp_enabled_types' => '1,2,3,4',
                    'status'        => 1,
                    'createtime'    => $now,
                    'updatetime'    => $now,
                ]);
                foreach ($all as $uid) {
                    if ($uid === $ownerId) {
                        $role = 3;
                    } elseif (in_array($uid, $adminIds, true)) {
                        $role = 2;
                    } else {
                        $role = 1;
                    }
                    Db::name('chat_group_members')->insert([
                        'group_id'   => $groupId,
                        'user_id'    => $uid,
                        'role'       => $role,
                        'nickname'   => '',
                        'status'     => 1,
                        'jointime'   => $now,
                        'updatetime' => $now,
                    ]);
                }
                Db::commit();
            } catch (\Throwable $e) {
                Db::rollback();
                $this->error($e->getMessage());
            }
            FansHubService::clearOfficialCommunityCache();
            $this->success('群组已创建');
        }
        $agents = Db::name('chat_agent_accounts')->where('status', 1)->order('id desc')->select();
        $this->view->assign('agents', $agents);
        return $this->view->fetch();
    }

    public function edit($ids = null)
    {
        $row = Db::name('chat_groups')->where('id', (int)$ids)->find();
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');
            $name = trim((string)($params['name'] ?? $row['name']));
            $ownerId = (int)($params['owner_user_id'] ?? $row['owner_user_id']);
            $adminIds = $this->parseIdList($params['admin_user_ids'] ?? '');
            $memberIds = $this->parseIdList($params['member_user_ids'] ?? '');
            $notice = trim((string)($params['notice'] ?? ''));
            $noticeI18n = $this->encodeNoticeI18n($params['notice_i18n'] ?? []);
            $status = (int)($params['status'] ?? 1);
            if ($name === '' || $ownerId <= 0) {
                $this->error('请填写群名称和群主会员ID');
            }
            $now = time();
            Db::startTrans();
            try {
                $privacy = in_array(($params['privacy_mode'] ?? ''), ['open', 'private'], true)
                    ? $params['privacy_mode'] : ($row['privacy_mode'] ?? 'private');
                if ((int)($params['hide_member_list'] ?? -1) >= 0 && !isset($params['privacy_mode'])) {
                    $privacy = ((int)$params['hide_member_list'] === 0) ? 'open' : 'private';
                }
                $chatMode = in_array(($params['chat_mode'] ?? ''), ['chat', 'grab'], true)
                    ? $params['chat_mode'] : ($row['chat_mode'] ?? 'chat');
                $finalStatus = ((int)$status === 2) ? 2 : 1;
                $forbidModesIn = $params['forbid_modes'] ?? [];
                if (!is_array($forbidModesIn)) {
                    $forbidModesIn = preg_split('/[,\s]+/', (string)$forbidModesIn) ?: [];
                }
                $forbidAllow = ['text', 'image', 'emoji', 'video', 'rp'];
                $forbidParts = [];
                foreach ($forbidModesIn as $fm) {
                    $fm = trim((string)$fm);
                    if (in_array($fm, $forbidAllow, true)) {
                        $forbidParts[] = $fm;
                    }
                }
                $forbidParts = array_values(array_unique($forbidParts));
                $forbidCsv = implode(',', $forbidParts);
                // 发言类全禁时兼容旧 mute_all（status=3）
                if ($finalStatus !== 2) {
                    $speechAll = !array_diff(['text', 'image', 'emoji', 'video'], $forbidParts);
                    if ($speechAll) {
                        $finalStatus = 3;
                    }
                }
                $enabledTypes = $params['rp_enabled_types'] ?? '1,2,3,4';
                if (is_array($enabledTypes)) {
                    $enabledTypes = implode(',', array_map('intval', $enabledTypes));
                }
                $enabledTypes = preg_replace('/[^0-9,]/', '', (string)$enabledTypes);
                if ($enabledTypes === '') {
                    $enabledTypes = '1,2,3,4';
                }
                $rpMinCount = max(1, (int)($params['rp_min_count'] ?? $row['rp_min_count'] ?? 5));
                $rpMaxCount = max($rpMinCount, (int)($params['rp_max_count'] ?? $row['rp_max_count'] ?? 10));
                Db::name('chat_groups')->where('id', $row['id'])->update([
                    'name'                 => mb_substr($name, 0, 64),
                    'owner_user_id'        => $ownerId,
                    'notice'               => mb_substr($notice, 0, 500),
                    'notice_i18n'          => $noticeI18n,
                    'status'               => $finalStatus,
                    'display_member_count' => max(0, (int)($params['display_member_count'] ?? $row['display_member_count'] ?? 0)),
                    'hide_member_list'     => ($privacy === 'private') ? 1 : 0,
                    'privacy_mode'         => $privacy,
                    'chat_mode'            => $chatMode,
                    'forbid_modes'         => mb_substr($forbidCsv, 0, 64),
                    'forbid_speak_hint'    => mb_substr(trim((string)($params['forbid_speak_hint'] ?? $row['forbid_speak_hint'] ?? '')), 0, 120),
                    'is_recommend'         => ((int)($params['is_recommend'] ?? 0) === 1) ? 1 : 0,
                    'weigh'                => (int)($params['weigh'] ?? ($row['weigh'] ?? 0)),
                    'is_vip_group'         => ((int)($params['is_vip_group'] ?? 0) === 1) ? 1 : 0,
                    'rp_min_amount'        => sprintf('%.2f', max(0, (float)($params['rp_min_amount'] ?? 10))),
                    'rp_min_count'         => $rpMinCount,
                    'rp_max_count'         => $rpMaxCount,
                    'rp_enabled_types'     => mb_substr($enabledTypes, 0, 32),
                    'rp_robot_only'        => ((int)($params['rp_robot_only'] ?? 0) === 1) ? 1 : 0,
                    'rp_fixed_amount'      => sprintf('%.2f', max(0, (float)($params['rp_fixed_amount'] ?? 0))),
                    'rp_agent_rebate_rate' => sprintf('%.4f', max(0, min(1, (float)($params['rp_agent_rebate_rate'] ?? 0.01)))),
                    'updatetime'           => $now,
                ]);
                $this->ensureMember((int)$row['id'], $ownerId, 3, $now);
                Db::name('chat_group_members')
                    ->where(['group_id' => $row['id'], 'role' => 3])
                    ->where('user_id', '<>', $ownerId)
                    ->update(['role' => 1, 'updatetime' => $now]);
                Db::name('chat_group_members')
                    ->where(['group_id' => $row['id'], 'role' => 2])
                    ->update(['role' => 1, 'updatetime' => $now]);
                foreach ($adminIds as $uid) {
                    if ($uid === $ownerId) {
                        continue;
                    }
                    $this->ensureMember((int)$row['id'], $uid, 2, $now);
                }
                foreach ($memberIds as $uid) {
                    if ($uid === $ownerId || in_array($uid, $adminIds, true)) {
                        continue;
                    }
                    $this->ensureMember((int)$row['id'], $uid, 1, $now);
                }
                $count = Db::name('chat_group_members')
                    ->where(['group_id' => $row['id'], 'status' => 1])
                    ->count();
                Db::name('chat_groups')->where('id', $row['id'])->update([
                    'member_count' => $count,
                    'updatetime'   => $now,
                ]);
                Db::commit();
            } catch (\Throwable $e) {
                Db::rollback();
                $this->error($e->getMessage());
            }
            FansHubService::clearOfficialCommunityCache();
            $this->bumpImGroupViewerCache((int)$row['id']);
            $this->success('已保存');
        }
        $admins = Db::name('chat_group_members')
            ->where(['group_id' => $row['id'], 'role' => 2, 'status' => 1])
            ->column('user_id');
        $members = Db::name('chat_group_members')
            ->where(['group_id' => $row['id'], 'status' => 1])
            ->where('role', 1)
            ->column('user_id');
        $row['admin_user_ids'] = implode(',', $admins);
        $row['member_user_ids'] = implode(',', $members);
        $row['notice_i18n_map'] = $this->decodeNoticeI18n($row['notice_i18n'] ?? '');
        $agents = Db::name('chat_agent_accounts')->where('status', 1)->order('id desc')->select();
        $this->view->assign('row', $row);
        $this->view->assign('agents', $agents);
        return $this->view->fetch();
    }

    public function del($ids = null)
    {
        $ids = $ids ?: $this->request->post('ids');
        $idArr = array_filter(array_map('intval', explode(',', (string)$ids)));
        if (!$idArr) {
            $this->error(__('Parameter %s can not be empty', 'ids'));
        }
        $now = time();
        Db::name('chat_groups')->where('id', 'in', $idArr)->update(['status' => 2, 'updatetime' => $now]);
        FansHubService::clearOfficialCommunityCache();
        $this->success('已解散');
    }

    /**
     * 硬删除：物理删除群组及成员/消息/红包/自动任务等关联数据（不可恢复）
     */
    public function harddel($ids = null)
    {
        $ids = $ids ?: $this->request->post('ids');
        $idArr = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$ids)))));
        if (!$idArr) {
            $this->error(__('Parameter %s can not be empty', 'ids'));
        }
        $ok = 0;
        $errors = [];
        foreach ($idArr as $gid) {
            try {
                $this->purgeGroupHard((int)$gid);
                $ok++;
            } catch (\Throwable $e) {
                $errors[] = '群' . $gid . ': ' . $e->getMessage();
            }
        }
        FansHubService::clearOfficialCommunityCache();
        if ($ok <= 0) {
            $this->error($errors ? implode('；', $errors) : '硬删除失败');
        }
        $msg = '已硬删除 ' . $ok . ' 个群';
        if ($errors) {
            $msg .= '；部分失败：' . implode('；', $errors);
        }
        $this->success($msg);
    }

    /**
     * 物理清理单个群及其关联数据
     */
    protected function purgeGroupHard($groupId)
    {
        $groupId = (int)$groupId;
        if ($groupId <= 0) {
            throw new \InvalidArgumentException('invalid group id');
        }
        $row = Db::name('chat_groups')->where('id', $groupId)->find();
        if (!$row) {
            throw new \RuntimeException('群不存在或已删除');
        }

        Db::startTrans();
        try {
            $packetIds = Db::name('chat_red_packets')->where('group_id', $groupId)->column('id');
            $packetIds = array_values(array_filter(array_map('intval', (array)$packetIds)));
            if ($packetIds) {
                Db::name('chat_red_packet_records')->where('packet_id', 'in', $packetIds)->delete();
                try {
                    Db::name('chat_red_packet_settlements')->where('packet_id', 'in', $packetIds)->delete();
                } catch (\Throwable $e) {
                }
                Db::name('chat_red_packets')->where('id', 'in', $packetIds)->delete();
            }

            $this->safeDeleteByGroup('chat_rp_auto_task', $groupId);
            $this->safeDeleteByGroup('chat_group_msg_cleared', $groupId);

            Db::name('chat_messages')->where('group_id', $groupId)->delete();
            Db::name('chat_messages')
                ->where(['conversation_type' => 2, 'conversation_id' => (string)$groupId])
                ->delete();

            try {
                Db::name('chat_conversation_read')
                    ->where(['conversation_type' => 2, 'conversation_id' => (string)$groupId])
                    ->delete();
            } catch (\Throwable $e) {
            }
            try {
                Db::name('chat_conversation_deleted')
                    ->where(['conversation_type' => 2, 'conversation_id' => (string)$groupId])
                    ->delete();
            } catch (\Throwable $e) {
            }

            Db::name('chat_group_members')->where('group_id', $groupId)->delete();
            Db::name('chat_groups')->where('id', $groupId)->delete();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    protected function safeDeleteByGroup($table, $groupId)
    {
        try {
            Db::name($table)->where('group_id', (int)$groupId)->delete();
        } catch (\Throwable $e) {
            // 表可能未建
        }
    }

    /**
     * 查看/管理群成员
     */
    public function members($ids = null)
    {
        $groupId = (int)($ids ?: $this->request->param('ids'));
        $group = Db::name('chat_groups')->where('id', $groupId)->find();
        if (!$group) {
            $this->error(__('No Results were found'));
        }
        if ($this->request->isAjax()) {
            $keyword = trim((string)$this->request->get('keyword', ''));
            $rows = Db::name('chat_group_members')
                ->where(['group_id' => $groupId, 'status' => 1])
                ->order('role desc,id asc')
                ->select();
            $now = time();
            $list = [];
            foreach ($rows as $row) {
                $uid = (int)$row['user_id'];
                $u = Db::name('user')->where('id', $uid)->field('id,nickname,username,mobile,avatar,status')->find();
                $nick = '';
                $mobile = '';
                if ($u) {
                    $nick = trim((string)($u['nickname'] ?: $u['username'] ?: ''));
                    $mobile = (string)($u['mobile'] ?? '');
                    if ($nick === '' && $mobile !== '') {
                        $nick = strlen($mobile) >= 7 ? (substr($mobile, 0, 3) . '****' . substr($mobile, -4)) : $mobile;
                    }
                }
                if ($nick === '') {
                    $nick = 'ID' . $uid;
                }
                $item = [
                    'id'         => (int)$row['id'],
                    'user_id'    => $uid,
                    'role'       => (int)$row['role'],
                    'role_text'  => $this->roleText((int)$row['role']),
                    'nickname'   => $nick,
                    'mobile'     => $mobile,
                    'mute_until' => (int)($row['mute_until'] ?? 0),
                    'is_muted'   => ((int)($row['mute_until'] ?? 0) > $now) ? 1 : 0,
                    'mute_text'  => ((int)($row['mute_until'] ?? 0) > $now)
                        ? date('Y-m-d H:i', (int)$row['mute_until'])
                        : '-',
                    'jointime'   => (int)$row['jointime'],
                ];
                if ($keyword !== '') {
                    $hay = mb_strtolower($nick . ' ' . $mobile . ' ' . $uid);
                    if (mb_strpos($hay, mb_strtolower($keyword)) === false) {
                        continue;
                    }
                }
                $list[] = $item;
            }
            return json(['total' => count($list), 'rows' => $list]);
        }
        $this->view->assign('group', $group);
        $this->view->assign('group_id', $groupId);
        return $this->view->fetch();
    }

    /**
     * 踢出群成员
     */
    public function kick()
    {
        $groupId = (int)$this->request->post('group_id');
        $userId = (int)$this->request->post('user_id');
        $group = Db::name('chat_groups')->where('id', $groupId)->find();
        if (!$group) {
            $this->error('群组不存在');
        }
        $member = Db::name('chat_group_members')
            ->where(['group_id' => $groupId, 'user_id' => $userId, 'status' => 1])
            ->find();
        if (!$member) {
            $this->error('成员不在群内');
        }
        if ((int)$member['role'] === 3) {
            $this->error('不能踢出群主，请先转让群主');
        }
        $now = time();
        Db::name('chat_group_members')->where('id', $member['id'])->update([
            'status'     => 2,
            'updatetime' => $now,
        ]);
        $this->refreshMemberCount($groupId);
        $this->insertSystemMessage($groupId, '管理员将 ' . $this->userLabel($userId) . ' 移出了群组');
        FansHubService::clearOfficialCommunityCache();
        $this->success('已移出');
    }

    /**
     * 单人禁言 / 取消禁言
     */
    public function mute()
    {
        $groupId = (int)$this->request->post('group_id');
        $userId = (int)$this->request->post('user_id');
        $seconds = (int)$this->request->post('seconds', 0);
        $group = Db::name('chat_groups')->where('id', $groupId)->find();
        if (!$group) {
            $this->error('群组不存在');
        }
        $member = Db::name('chat_group_members')
            ->where(['group_id' => $groupId, 'user_id' => $userId, 'status' => 1])
            ->find();
        if (!$member) {
            $this->error('成员不在群内');
        }
        if ((int)$member['role'] === 3) {
            $this->error('不能禁言群主');
        }
        $until = $seconds <= 0 ? 0 : (time() + $seconds);
        Db::name('chat_group_members')->where('id', $member['id'])->update([
            'mute_until' => $until,
            'updatetime' => time(),
        ]);
        if ($seconds <= 0) {
            $this->insertSystemMessage($groupId, '管理员取消了 ' . $this->userLabel($userId) . ' 的禁言');
            $this->success('已取消禁言');
        }
        $label = $this->formatMuteDuration($seconds);
        $this->insertSystemMessage($groupId, '管理员禁言了 ' . $this->userLabel($userId) . ' ' . $label);
        $this->success('已禁言 ' . $label);
    }

    /**
     * 全员禁言开关
     */
    public function muteall()
    {
        $groupId = (int)($this->request->param('ids') ?: $this->request->post('group_id') ?: $this->request->get('group_id'));
        $enabled = $this->request->post('enabled', $this->request->param('enabled'));
        $group = Db::name('chat_groups')->where('id', $groupId)->find();
        if (!$group) {
            $this->error('群组不存在');
        }
        if ((int)$group['status'] === 2) {
            $this->error('群组已解散');
        }
        if ($enabled === null || $enabled === '') {
            // 切换
            $enabled = (int)$group['status'] === 3 ? 0 : 1;
        } else {
            $enabled = (int)$enabled ? 1 : 0;
        }
        $status = $enabled ? 3 : 1;
        $forbid = $enabled ? 'text,image,emoji,video,rp' : '';
        Db::name('chat_groups')->where('id', $groupId)->update([
            'status'       => $status,
            'forbid_modes' => $forbid,
            'updatetime'   => time(),
        ]);
        $this->insertSystemMessage(
            $groupId,
            $enabled ? '管理员 开启了 全员禁言' : '管理员 关闭了 全员禁言'
        );
        FansHubService::clearOfficialCommunityCache();
        $this->success($enabled ? '已开启全员禁言（仅管理员可发言）' : '已关闭全员禁言');
    }

    /**
     * 添加群成员页
     */
    public function invite($ids = null)
    {
        $groupId = (int)($ids ?: $this->request->param('ids'));
        $group = Db::name('chat_groups')->where('id', $groupId)->find();
        if (!$group) {
            $this->error(__('No Results were found'));
        }
        $this->view->assign('group', $group);
        $this->view->assign('group_id', $groupId);
        return $this->view->fetch();
    }

    /**
     * 未入群候选用户列表
     */
    public function candidates()
    {
        $groupId = (int)$this->request->get('group_id');
        $keyword = trim((string)$this->request->get('keyword', ''));
        $offset = (int)$this->request->get('offset', 0);
        $limit = max(1, min(100, (int)$this->request->get('limit', 50)));
        if ($groupId <= 0) {
            $this->error('参数错误');
        }
        $existIds = Db::name('chat_group_members')
            ->where(['group_id' => $groupId, 'status' => 1])
            ->column('user_id');
        $existIds = array_map('intval', $existIds ?: []);

        $where = function ($query) use ($existIds, $keyword) {
            $query->where('status', 'normal');
            if ($existIds) {
                $query->where('id', 'not in', $existIds);
            }
            if ($keyword === '') {
                return;
            }
            if (ctype_digit($keyword)) {
                $query->where(function ($q) use ($keyword) {
                    $q->where('id', (int)$keyword)
                        ->whereOr('mobile', 'like', '%' . $keyword . '%')
                        ->whereOr('nickname', 'like', '%' . $keyword . '%')
                        ->whereOr('username', 'like', '%' . $keyword . '%');
                });
            } else {
                $query->where(function ($q) use ($keyword) {
                    $q->where('nickname', 'like', '%' . $keyword . '%')
                        ->whereOr('username', 'like', '%' . $keyword . '%')
                        ->whereOr('mobile', 'like', '%' . $keyword . '%');
                });
            }
        };

        $total = Db::name('user')->where($where)->count();
        $rows = Db::name('user')
            ->where($where)
            ->field('id,nickname,username,mobile,avatar')
            ->order('id desc')
            ->limit($offset, $limit)
            ->select();
        $list = [];
        foreach ($rows as $u) {
            $uid = (int)$u['id'];
            $nick = trim((string)($u['nickname'] ?: $u['username'] ?: ''));
            $mobile = (string)($u['mobile'] ?? '');
            if ($nick === '' && $mobile !== '') {
                $nick = strlen($mobile) >= 7 ? (substr($mobile, 0, 3) . '****' . substr($mobile, -4)) : $mobile;
            }
            $list[] = [
                'id'       => $uid,
                'user_id'  => $uid,
                'nickname' => $nick !== '' ? $nick : ('ID' . $uid),
                'mobile'   => $mobile,
                'username' => (string)($u['username'] ?? ''),
            ];
        }
        return json(['total' => $total, 'rows' => $list]);
    }

    /**
     * 批量添加群成员
     */
    public function addmembers()
    {
        $groupId = (int)$this->request->post('group_id');
        // 前端可能传数组，也可能传 "1,2,3"；勿用 post('user_ids/a')，否则字符串会被当成单元素再 intval 只剩第一个
        $raw = $this->request->post('user_ids');
        if (is_array($raw)) {
            $userIds = [];
            foreach ($raw as $item) {
                if (is_array($item)) {
                    continue;
                }
                $s = trim((string)$item);
                if (strpos($s, ',') !== false || strpos($s, '，') !== false) {
                    $userIds = array_merge($userIds, $this->parseIdList($s));
                } else {
                    $id = (int)$s;
                    if ($id > 0) {
                        $userIds[] = $id;
                    }
                }
            }
            $userIds = array_values(array_unique($userIds));
        } else {
            $userIds = $this->parseIdList((string)$raw);
        }
        $group = Db::name('chat_groups')->where('id', $groupId)->find();
        if (!$group) {
            $this->error('群组不存在');
        }
        if ((int)$group['status'] === 2) {
            $this->error('群组已解散');
        }
        if (!$userIds) {
            $this->error('请选择要添加的用户');
        }
        $now = time();
        $added = [];
        foreach ($userIds as $uid) {
            if ($uid <= 0) {
                continue;
            }
            if (!Db::name('user')->where('id', $uid)->find()) {
                continue;
            }
            $this->ensureMember($groupId, $uid, 1, $now);
            $added[] = $uid;
        }
        if (!$added) {
            $this->error('没有可添加的用户');
        }
        $this->refreshMemberCount($groupId);
        $names = array_map([$this, 'userLabel'], array_slice($added, 0, 5));
        $text = '管理员邀请 ' . implode('、', $names)
            . (count($added) > 5 ? (' 等' . count($added) . '人') : '')
            . ' 加入了群组';
        $this->insertSystemMessage($groupId, $text);
        FansHubService::clearOfficialCommunityCache();
        $this->success('已添加 ' . count($added) . ' 人');
    }

    protected function refreshMemberCount($groupId)
    {
        $count = Db::name('chat_group_members')
            ->where(['group_id' => (int)$groupId, 'status' => 1])
            ->count();
        Db::name('chat_groups')->where('id', (int)$groupId)->update([
            'member_count' => $count,
            'updatetime'   => time(),
        ]);
    }

    protected function insertSystemMessage($groupId, $content)
    {
        $groupId = (int)$groupId;
        $content = mb_substr(trim((string)$content), 0, 500);
        if ($groupId <= 0 || $content === '') {
            return;
        }
        Db::name('chat_messages')->insert([
            'msg_id'            => sprintf('m%s%04d', date('YmdHis'), mt_rand(0, 9999)),
            'conversation_type' => 2,
            'conversation_id'   => (string)$groupId,
            'group_id'          => $groupId,
            'from_user_id'      => 0,
            'to_user_id'        => 0,
            'msg_type'          => 3,
            'content'           => $content,
            'extra'             => null,
            'status'            => 1,
            'createtime'        => time(),
        ]);
    }

    protected function roleText($role)
    {
        if ($role === 3) {
            return '群主';
        }
        if ($role === 2) {
            return '管理员';
        }
        return '成员';
    }

    protected function formatMuteDuration($seconds)
    {
        $seconds = (int)$seconds;
        if ($seconds >= 86400) {
            return max(1, (int)round($seconds / 86400)) . '天';
        }
        if ($seconds >= 3600) {
            return max(1, (int)round($seconds / 3600)) . '小时';
        }
        if ($seconds >= 60) {
            return max(1, (int)round($seconds / 60)) . '分钟';
        }
        return $seconds . '秒';
    }

    protected function ensureMember($groupId, $userId, $role, $now)
    {
        $exist = Db::name('chat_group_members')->where(['group_id' => $groupId, 'user_id' => $userId])->find();
        if ($exist) {
            Db::name('chat_group_members')->where('id', $exist['id'])->update([
                'role'       => $role,
                'status'     => 1,
                'updatetime' => $now,
            ]);
            return;
        }
        Db::name('chat_group_members')->insert([
            'group_id'   => $groupId,
            'user_id'    => $userId,
            'role'       => $role,
            'nickname'   => '',
            'status'     => 1,
            'jointime'   => $now,
            'updatetime' => $now,
        ]);
    }

    /**
     * 使 IM 进程内 group.info 短缓存失效（rp_robot_only 等即时生效）
     */
    protected function bumpImGroupViewerCache($groupId)
    {
        $groupId = (int)$groupId;
        if ($groupId <= 0) {
            return;
        }
        try {
            $cfgFile = ROOT_PATH . 'im-server' . DS . 'config' . DS . 'app.php';
            if (!is_file($cfgFile)) {
                return;
            }
            $cfg = include $cfgFile;
            $r = is_array($cfg) ? ($cfg['redis'] ?? []) : [];
            if (!class_exists('\\Redis')) {
                return;
            }
            $redis = new \Redis();
            $redis->connect((string)($r['host'] ?? '127.0.0.1'), (int)($r['port'] ?? 6379), 1.5);
            if (!empty($r['password'])) {
                $redis->auth((string)$r['password']);
            }
            if (isset($r['db'])) {
                $redis->select((int)$r['db']);
            }
            $prefix = (string)($r['prefix'] ?? 'im:');
            $redis->incr($prefix . 'g:' . $groupId . ':infover');
        } catch (\Throwable $e) {
            // ignore
        }
    }

    protected function parseIdList($raw)
    {
        $parts = preg_split('/[,，\s]+/', trim((string)$raw));
        $ids = [];
        foreach ($parts as $p) {
            $id = (int)$p;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    protected function userLabel($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return '-';
        }
        $u = Db::name('user')->where('id', $userId)->field('id,nickname,username,mobile')->find();
        if (!$u) {
            return 'ID' . $userId;
        }
        $name = trim((string)($u['nickname'] ?: $u['username'] ?: ''));
        if ($name === '' && !empty($u['mobile'])) {
            $m = (string)$u['mobile'];
            $name = strlen($m) >= 7 ? (substr($m, 0, 3) . '****' . substr($m, -4)) : $m;
        }
        return ($name !== '' ? $name : '用户') . '(ID' . $userId . ')';
    }
}
