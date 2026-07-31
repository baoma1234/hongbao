<?php

namespace Im\Service;

use Im\Support\Db;
use Im\Support\RedisClient;

class GroupService
{
    /** 群成员列表 Redis 缓存秒数 */
    const MEMBER_CACHE_TTL = 60;

    public function create($ownerUserId, $name, array $memberIds = [], array $adminIds = [], array $options = [])
    {
        $ownerUserId = (int)$ownerUserId;
        $name = mb_substr(trim((string)$name), 0, 64);
        if ($ownerUserId <= 0 || $name === '') {
            throw new \InvalidArgumentException('invalid group');
        }
        $privacy = ((string)($options['privacy_mode'] ?? 'private') === 'open') ? 'open' : 'private';
        $chatMode = ((string)($options['chat_mode'] ?? 'chat') === 'grab') ? 'grab' : 'chat';
        $hideList = ($privacy === 'private') ? 1 : 0;
        $status = ($chatMode === 'grab') ? 3 : 1;
        $now = time();
        $adminIds = array_values(array_unique(array_filter(array_map('intval', $adminIds))));
        $members = array_unique(array_merge([$ownerUserId], $adminIds, array_map('intval', $memberIds)));
        $members = array_values(array_filter($members, function ($id) {
            return $id > 0;
        }));

        Db::begin();
        try {
            Db::exec(
                'INSERT INTO ' . Db::table('chat_groups')
                . ' (name,owner_user_id,member_count,max_members,status,privacy_mode,chat_mode,hide_member_list,createtime,updatetime) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$name, $ownerUserId, count($members), 500, $status, $privacy, $chatMode, $hideList, $now, $now]
            );
            $groupId = Db::lastId();
            foreach ($members as $uid) {
                if ($uid === $ownerUserId) {
                    $role = 3;
                } elseif (in_array($uid, $adminIds, true)) {
                    $role = 2;
                } else {
                    $role = 1;
                }
                Db::exec(
                    'INSERT INTO ' . Db::table('chat_group_members')
                    . ' (group_id,user_id,role,status,jointime,updatetime) VALUES (?,?,?,1,?,?)',
                    [$groupId, $uid, $role, $now, $now]
                );
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            throw $e;
        }
        $this->invalidateMembersCache($groupId);
        return $this->get($groupId);
    }

    public function get($groupId)
    {
        return Db::fetch('SELECT * FROM ' . Db::table('chat_groups') . ' WHERE id=? LIMIT 1', [(int)$groupId]);
    }

    public function memberUserIds($groupId)
    {
        $groupId = (int)$groupId;
        $cacheKey = RedisClient::key('g:' . $groupId . ':members');
        try {
            $r = RedisClient::conn();
            $cached = $r->get($cacheKey);
            if ($cached !== false && $cached !== null && $cached !== '') {
                $ids = json_decode($cached, true);
                if (is_array($ids)) {
                    return array_map('intval', $ids);
                }
            }
        } catch (\Throwable $e) {
        }

        $rows = Db::fetchAll(
            'SELECT user_id FROM ' . Db::table('chat_group_members') . ' WHERE group_id=? AND status=1',
            [$groupId]
        );
        $ids = array_map(function ($r) {
            return (int)$r['user_id'];
        }, $rows);
        try {
            RedisClient::conn()->setex($cacheKey, self::MEMBER_CACHE_TTL, json_encode($ids));
        } catch (\Throwable $e) {
        }
        return $ids;
    }

    public function invalidateMembersCache($groupId)
    {
        try {
            RedisClient::conn()->del(RedisClient::key('g:' . (int)$groupId . ':members'));
        } catch (\Throwable $e) {
        }
    }

    /** 清用户「我的群」列表缓存（MessageService 60s 缓存） */
    public function invalidateUserGroupsCache($userId)
    {
        try {
            RedisClient::conn()->del(RedisClient::key('uid:' . (int)$userId . ':my_groups'));
        } catch (\Throwable $e) {
        }
    }

    public function members($groupId)
    {
        return Db::fetchAll(
            'SELECT * FROM ' . Db::table('chat_group_members')
            . ' WHERE group_id=? AND status=1 ORDER BY role DESC, id ASC',
            [(int)$groupId]
        );
    }

    public function isMember($groupId, $userId)
    {
        $groupId = (int)$groupId;
        $userId = (int)$userId;
        if ($groupId <= 0 || $userId <= 0) {
            return false;
        }
        $ids = $this->memberUserIds($groupId);
        if ($ids) {
            return in_array($userId, $ids, true);
        }
        $row = Db::fetch(
            'SELECT id FROM ' . Db::table('chat_group_members')
            . ' WHERE group_id=? AND user_id=? AND status=1 LIMIT 1',
            [$groupId, $userId]
        );
        return (bool)$row;
    }

    public function myGroups($userId)
    {
        $sql = 'SELECT g.* FROM ' . Db::table('chat_groups') . ' g'
            . ' INNER JOIN ' . Db::table('chat_group_members') . ' m ON m.group_id=g.id'
            . ' WHERE m.user_id=? AND m.status=1 AND g.status IN (1,3) ORDER BY g.id DESC';
        return Db::fetchAll($sql, [(int)$userId]);
    }

    /**
     * 后台标记推荐的开放社群
     */
    public function recommendGroups($userId = 0)
    {
        $userId = (int)$userId;
        $hasCol = $this->hasRecommendColumn();
        $sql = 'SELECT g.* FROM ' . Db::table('chat_groups') . ' g'
            . ' WHERE g.status IN (1,3)'
            . " AND (g.privacy_mode='open' OR (IFNULL(g.privacy_mode,'')='' AND IFNULL(g.hide_member_list,1)=0))";
        if ($hasCol) {
            $sql .= ' AND IFNULL(g.is_recommend,0)=1';
        }
        $sql .= ' ORDER BY g.id DESC LIMIT 30';
        $rows = Db::fetchAll($sql);
        $out = [];
        foreach ($rows as $g) {
            $gid = (int)$g['id'];
            $memberIds = $this->memberUserIds($gid);
            $onlineCnt = count(\Im\Support\ConnMap::filterOnlineUserIds($memberIds));
            $display = (int)($g['display_member_count'] ?? 0);
            $memberCount = $display > 0 ? $display : (int)($g['member_count'] ?? count($memberIds));
            $joined = $userId > 0 ? $this->isMember($gid, $userId) : false;
            $out[] = [
                'id'            => $gid,
                'name'          => (string)($g['name'] ?? ''),
                'avatar'        => (string)($g['avatar'] ?? ''),
                'notice'        => (string)($g['notice'] ?? ''),
                'member_count'  => $memberCount,
                'online_count'  => $onlineCnt,
                'is_member'     => $joined,
                'privacy_mode'  => 'open',
                'chat_mode'     => (string)($g['chat_mode'] ?? 'chat'),
                'is_recommend'  => 1,
            ];
        }
        return $out;
    }

    public function joinOpenGroup($groupId, $userId)
    {
        $groupId = (int)$groupId;
        $userId = (int)$userId;
        if ($groupId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException('invalid params');
        }
        $group = $this->get($groupId);
        if (!$group || !in_array((int)$group['status'], [1, 3], true)) {
            throw new \RuntimeException('group unavailable');
        }
        $mode = (string)($group['privacy_mode'] ?? '');
        $isOpen = ($mode === 'open') || ($mode === '' && (int)($group['hide_member_list'] ?? 1) === 0);
        if (!$isOpen) {
            throw new \RuntimeException('private group');
        }
        if ($this->hasRecommendColumn() && (int)($group['is_recommend'] ?? 0) !== 1) {
            // 允许加入任意开放群；推荐位仅展示，不强制
        }
        if ($this->isMember($groupId, $userId)) {
            return $this->get($groupId);
        }
        $max = (int)($group['max_members'] ?? 500);
        $cnt = (int)($group['member_count'] ?? 0);
        if ($max > 0 && $cnt >= $max) {
            throw new \RuntimeException('group full');
        }
        $this->addMembers($groupId, [$userId], 1);
        return $this->get($groupId);
    }

    protected function hasRecommendColumn()
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        try {
            $row = Db::fetch('SHOW COLUMNS FROM ' . Db::table('chat_groups') . " LIKE 'is_recommend'");
            $cached = (bool)$row;
        } catch (\Throwable $e) {
            $cached = false;
        }
        return $cached;
    }

    public function getMember($groupId, $userId)
    {
        return Db::fetch(
            'SELECT * FROM ' . Db::table('chat_group_members')
            . ' WHERE group_id=? AND user_id=? AND status=1 LIMIT 1',
            [(int)$groupId, (int)$userId]
        );
    }

    public function memberRole($groupId, $userId)
    {
        $row = $this->getMember($groupId, $userId);
        return $row ? (int)$row['role'] : 0;
    }

    public function isModerator($groupId, $userId)
    {
        return $this->memberRole($groupId, $userId) >= 2;
    }

    public function isOwner($groupId, $userId)
    {
        return $this->memberRole($groupId, $userId) === 3;
    }

    public function isMuteAll($groupId)
    {
        $g = $this->get($groupId);
        return $g && (int)$g['status'] === 3;
    }

    /**
     * 是否允许发言（含全员禁言 / 单人禁言）
     */
    public function assertCanSpeak($groupId, $userId)
    {
        $groupId = (int)$groupId;
        $userId = (int)$userId;
        $group = $this->get($groupId);
        if (!$group || (int)$group['status'] === 2) {
            throw new \RuntimeException('group unavailable');
        }
        $member = $this->getMember($groupId, $userId);
        if (!$member) {
            throw new \RuntimeException('not in group');
        }
        $role = (int)$member['role'];
        $now = time();
        $muteUntil = (int)($member['mute_until'] ?? 0);
        if ($muteUntil > $now) {
            throw new \RuntimeException('you are muted');
        }
        if ((int)$group['status'] === 3 && $role < 2) {
            throw new \RuntimeException('group muted');
        }
    }

    public function assertCanModerate($groupId, $operatorId, $targetId = 0)
    {
        $opRole = $this->memberRole($groupId, $operatorId);
        if ($opRole < 2) {
            throw new \RuntimeException('no permission');
        }
        $targetId = (int)$targetId;
        if ($targetId > 0) {
            if ($targetId === (int)$operatorId) {
                throw new \RuntimeException('cannot operate self');
            }
            $targetRole = $this->memberRole($groupId, $targetId);
            if ($targetRole <= 0) {
                throw new \RuntimeException('target not in group');
            }
            // 只能管理比自己角色低的成员；群主可管管理员
            if ($targetRole >= $opRole) {
                throw new \RuntimeException('no permission');
            }
        }
        return $opRole;
    }

    public function listMembersDetailed($groupId, $operatorId, $keyword = '')
    {
        $groupId = (int)$groupId;
        if (!$this->isMember($groupId, $operatorId)) {
            throw new \RuntimeException('not in group');
        }
        $groupRow = $this->get($groupId) ?: [];
        $myRole = $this->memberRole($groupId, $operatorId);
        $hideList = $this->shouldHideMemberList($groupRow, $myRole);
        if ($hideList) {
            $policy = $this->buildPolicy($groupRow, $myRole);
            return [
                'group'              => $groupRow,
                'my_role'            => $myRole,
                'mute_all'           => $this->isMuteAll($groupId),
                'list'               => [],
                'member_count'       => $this->publicMemberCount($groupRow),
                'member_list_hidden' => true,
                'policy'             => $policy,
            ];
        }
        $rows = $this->members($groupId);
        $ids = array_map(function ($r) {
            return (int)$r['user_id'];
        }, $rows);
        $users = (new AuthService([]))->usersBriefMap($ids);
        $kw = mb_strtolower(trim((string)$keyword));
        $now = time();
        $list = [];
        foreach ($rows as $row) {
            $uid = (int)$row['user_id'];
            $u = $users[$uid] ?? null;
            $nick = '';
            $mobile = '';
            $avatar = '';
            if ($u) {
                $nick = trim((string)($u['nickname'] ?: $u['username'] ?: ''));
                $mobile = (string)($u['mobile'] ?? '');
                $avatar = (string)($u['avatar'] ?? '');
                if ($nick === '' && $mobile !== '') {
                    $nick = strlen($mobile) >= 7 ? (substr($mobile, 0, 3) . '****' . substr($mobile, -4)) : $mobile;
                }
            }
            if ($nick === '') {
                $nick = 'ID' . $uid;
            }
            $item = [
                'user_id'    => $uid,
                'role'       => (int)$row['role'],
                'nickname'   => $nick,
                'mobile'     => $mobile,
                'avatar'     => $avatar,
                'mute_until' => (int)($row['mute_until'] ?? 0),
                'is_muted'   => ((int)($row['mute_until'] ?? 0) > $now),
            ];
            if ($kw !== '') {
                $hay = mb_strtolower($nick . ' ' . $mobile . ' ' . $uid);
                if (mb_strpos($hay, $kw) === false) {
                    continue;
                }
            }
            $list[] = $item;
        }
        $policy = $this->buildPolicy($groupRow, $myRole);
        $canView = !empty($policy['can_view_profile']);
        foreach ($list as &$item) {
            $item['profile_clickable'] = $canView && (int)$item['user_id'] !== (int)$operatorId;
        }
        unset($item);
        return [
            'group'              => $groupRow,
            'my_role'            => $myRole,
            'mute_all'           => $this->isMuteAll($groupId),
            'list'               => $list,
            'member_count'       => $this->publicMemberCount($groupRow),
            'member_list_hidden' => false,
            'policy'             => $policy,
        ];
    }

    public function publicMemberCount(array $group)
    {
        $display = (int)($group['display_member_count'] ?? 0);
        if ($display > 0) {
            return $display;
        }
        return (int)($group['member_count'] ?? 0);
    }

    public function shouldHideMemberList(array $group, $operatorRole)
    {
        if ($this->privacyMode($group) === 'open') {
            return false;
        }
        if ((int)($group['hide_member_list'] ?? 1) !== 1) {
            return false;
        }
        return (int)$operatorRole < 2;
    }

    public function privacyMode(array $group)
    {
        $mode = (string)($group['privacy_mode'] ?? '');
        if ($mode === 'open' || $mode === 'private') {
            return $mode;
        }
        return ((int)($group['hide_member_list'] ?? 1) === 0) ? 'open' : 'private';
    }

    public function chatMode(array $group)
    {
        $mode = (string)($group['chat_mode'] ?? '');
        return ($mode === 'grab') ? 'grab' : 'chat';
    }

    public function isOpenGroup(array $group)
    {
        return $this->privacyMode($group) === 'open';
    }

    public function isGrabMode(array $group)
    {
        return $this->chatMode($group) === 'grab';
    }

    public function buildPolicy(array $group, $operatorRole)
    {
        $role = (int)$operatorRole;
        $isOpen = $this->isOpenGroup($group);
        $isGrab = $this->isGrabMode($group);
        return [
            'privacy_mode'       => $isOpen ? 'open' : 'private',
            'chat_mode'          => $isGrab ? 'grab' : 'chat',
            'privacy_label'      => $isOpen ? '开放群' : '隐私群',
            'chat_mode_label'    => $isGrab ? '红宝模式' : '聊天模式',
            'member_list_hidden' => $this->shouldHideMemberList($group, $role),
            'can_view_profile'   => $isOpen || $role >= 2,
            'can_add_friend'     => $isOpen,
            'can_mention'        => $isOpen || $role >= 2,
            // 红宝记录弹窗：隐私群一律锁死（与是否红宝模式无关）
            'rp_detail_locked'   => !$isOpen,
            'can_send_rp'        => $isGrab ? ($role >= 2) : true,
        ];
    }

    /**
     * 隐私群展示用脱敏昵称
     */
    public function maskNickname($nickname, $userId = 0)
    {
        $name = trim((string)$nickname);
        if ($name === '') {
            $uid = (int)$userId;
            return $uid > 0 ? ('群友' . substr((string)$uid, -2) . '**') : '群友**';
        }
        $len = mb_strlen($name, 'UTF-8');
        if ($len <= 1) {
            return '*';
        }
        if ($len === 2) {
            return mb_substr($name, 0, 1, 'UTF-8') . '*';
        }
        $stars = str_repeat('*', min(4, $len - 2));
        return mb_substr($name, 0, 1, 'UTF-8') . $stars . mb_substr($name, -1, 1, 'UTF-8');
    }

    public function assertCanMention(array $group, $operatorRole)
    {
        if ($this->buildPolicy($group, $operatorRole)['can_mention']) {
            return;
        }
        throw new \RuntimeException('private group: mention disabled');
    }

    public function assertCanSendGroupRedPacket($groupId, $userId)
    {
        $group = $this->get($groupId);
        if (!$group) {
            throw new \RuntimeException('invalid group');
        }
        $role = $this->memberRole($groupId, $userId);
        if (!$this->buildPolicy($group, $role)['can_send_rp']) {
            throw new \RuntimeException('grab mode: only admin can send red packets');
        }
    }

    public function syncModeFields(array $data, array $before = [])
    {
        if (isset($data['privacy_mode'])) {
            $pm = ((string)$data['privacy_mode'] === 'open') ? 'open' : 'private';
            $data['privacy_mode'] = $pm;
            $data['hide_member_list'] = ($pm === 'private') ? 1 : 0;
        }
        if (isset($data['chat_mode'])) {
            $cm = ((string)$data['chat_mode'] === 'grab') ? 'grab' : 'chat';
            $data['chat_mode'] = $cm;
            $curStatus = (int)($before['status'] ?? $data['status'] ?? 1);
            if ($curStatus !== 2) {
                $data['status'] = ($cm === 'grab') ? 3 : 1;
            }
        }
        return $data;
    }

    public function kick($groupId, $operatorId, $targetId)
    {
        $this->assertCanModerate($groupId, $operatorId, $targetId);
        $now = time();
        Db::exec(
            'UPDATE ' . Db::table('chat_group_members')
            . ' SET status=2, updatetime=? WHERE group_id=? AND user_id=? AND status=1',
            [$now, (int)$groupId, (int)$targetId]
        );
        $this->refreshMemberCount($groupId);
        $this->invalidateMembersCache($groupId);
        $this->invalidateUserGroupsCache($targetId);
        return true;
    }

    public function muteMember($groupId, $operatorId, $targetId, $seconds)
    {
        $this->assertCanModerate($groupId, $operatorId, $targetId);
        $seconds = (int)$seconds;
        $until = $seconds <= 0 ? 0 : (time() + $seconds);
        Db::exec(
            'UPDATE ' . Db::table('chat_group_members')
            . ' SET mute_until=?, updatetime=? WHERE group_id=? AND user_id=? AND status=1',
            [$until, time(), (int)$groupId, (int)$targetId]
        );
        return ['mute_until' => $until];
    }

    public function setMemberAdmin($groupId, $operatorId, $targetId, $isAdmin)
    {
        if (!$this->isOwner($groupId, $operatorId)) {
            throw new \RuntimeException('owner only');
        }
        $targetId = (int)$targetId;
        if ($targetId === (int)$operatorId) {
            throw new \RuntimeException('cannot operate self');
        }
        $target = $this->getMember($groupId, $targetId);
        if (!$target) {
            throw new \RuntimeException('target not in group');
        }
        if ((int)$target['role'] === 3) {
            throw new \RuntimeException('cannot change owner');
        }
        $role = $isAdmin ? 2 : 1;
        Db::exec(
            'UPDATE ' . Db::table('chat_group_members')
            . ' SET role=?, updatetime=? WHERE group_id=? AND user_id=? AND status=1',
            [$role, time(), (int)$groupId, $targetId]
        );
        return ['role' => $role];
    }

    public function setMuteAll($groupId, $operatorId, $enabled)
    {
        $this->assertCanModerate($groupId, $operatorId, 0);
        $group = $this->get($groupId);
        if (!$group || (int)$group['status'] === 2) {
            throw new \RuntimeException('group unavailable');
        }
        $status = $enabled ? 3 : 1;
        Db::exec(
            'UPDATE ' . Db::table('chat_groups') . ' SET status=?, updatetime=? WHERE id=?',
            [$status, time(), (int)$groupId]
        );
        return $this->get($groupId);
    }

    public function inviteCandidates($groupId, $operatorId, $keyword = '', $limit = 50)
    {
        $this->assertCanModerate($groupId, $operatorId, 0);
        $limit = max(1, min(100, (int)$limit));
        $existIds = $this->memberUserIds($groupId);
        $existMap = array_fill_keys($existIds, true);
        $kw = trim((string)$keyword);
        $userTable = Db::table('user');
        $sql = "SELECT id, username, nickname, mobile, avatar, status FROM {$userTable} WHERE status='normal'";
        $bind = [];
        if ($kw !== '') {
            if (ctype_digit($kw)) {
                $sql .= ' AND (id=? OR mobile LIKE ? OR nickname LIKE ? OR username LIKE ?)';
                $like = '%' . $kw . '%';
                $bind = [(int)$kw, $like, $like, $like];
            } else {
                $sql .= ' AND (nickname LIKE ? OR username LIKE ? OR mobile LIKE ?)';
                $like = '%' . $kw . '%';
                $bind = [$like, $like, $like];
            }
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . ($limit * 3);
        $rows = Db::fetchAll($sql, $bind);
        $list = [];
        foreach ($rows as $u) {
            $uid = (int)$u['id'];
            if (isset($existMap[$uid])) {
                continue;
            }
            $nick = trim((string)($u['nickname'] ?: $u['username'] ?: ''));
            $mobile = (string)($u['mobile'] ?? '');
            if ($nick === '' && $mobile !== '') {
                $nick = strlen($mobile) >= 7 ? (substr($mobile, 0, 3) . '****' . substr($mobile, -4)) : $mobile;
            }
            $list[] = [
                'user_id'  => $uid,
                'nickname' => $nick !== '' ? $nick : ('ID' . $uid),
                'mobile'   => $mobile,
                'avatar'   => (string)($u['avatar'] ?? ''),
            ];
            if (count($list) >= $limit) {
                break;
            }
        }
        return ['list' => $list];
    }

    public function addMembersByOperator($groupId, $operatorId, array $memberIds)
    {
        $this->assertCanModerate($groupId, $operatorId, 0);
        $ids = array_values(array_unique(array_filter(array_map('intval', $memberIds))));
        if (!$ids) {
            throw new \InvalidArgumentException('empty members');
        }
        $this->addMembers($groupId, $ids, 1);
        return ['added' => $ids, 'members' => $this->listMembersDetailed($groupId, $operatorId)];
    }

    public function displayName($userId)
    {
        $userId = (int)$userId;
        $row = Db::fetch(
            'SELECT nickname, username, mobile FROM ' . Db::table('user') . ' WHERE id=? LIMIT 1',
            [$userId]
        );
        if (!$row) {
            return 'ID' . $userId;
        }
        $nick = trim((string)($row['nickname'] ?: $row['username'] ?: ''));
        if ($nick !== '') {
            return $nick;
        }
        $mobile = (string)($row['mobile'] ?? '');
        if ($mobile !== '') {
            return strlen($mobile) >= 7 ? (substr($mobile, 0, 3) . '****' . substr($mobile, -4)) : $mobile;
        }
        return 'ID' . $userId;
    }

    public function setOwner($groupId, $ownerUserId)
    {
        $groupId = (int)$groupId;
        $ownerUserId = (int)$ownerUserId;
        $group = $this->get($groupId);
        if (!$group || $ownerUserId <= 0) {
            throw new \InvalidArgumentException('invalid group owner');
        }
        $now = time();
        Db::begin();
        try {
            $this->ensureMember($groupId, $ownerUserId, 3, $now);
            Db::exec(
                'UPDATE ' . Db::table('chat_group_members')
                . ' SET role=1, updatetime=? WHERE group_id=? AND user_id<>? AND role=3 AND status=1',
                [$now, $groupId, $ownerUserId]
            );
            Db::exec(
                'UPDATE ' . Db::table('chat_group_members')
                . ' SET role=3, updatetime=? WHERE group_id=? AND user_id=? AND status=1',
                [$now, $groupId, $ownerUserId]
            );
            Db::exec(
                'UPDATE ' . Db::table('chat_groups')
                . ' SET owner_user_id=?, updatetime=? WHERE id=?',
                [$ownerUserId, $now, $groupId]
            );
            $this->refreshMemberCount($groupId);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            throw $e;
        }
        $this->invalidateMembersCache($groupId);
        return $this->get($groupId);
    }

    public function setAdmins($groupId, array $adminIds)
    {
        $groupId = (int)$groupId;
        $group = $this->get($groupId);
        if (!$group) {
            throw new \InvalidArgumentException('invalid group');
        }
        $ownerId = (int)$group['owner_user_id'];
        $adminIds = array_values(array_unique(array_filter(array_map('intval', $adminIds), function ($id) use ($ownerId) {
            return $id > 0 && $id !== $ownerId;
        })));
        $now = time();
        Db::begin();
        try {
            Db::exec(
                'UPDATE ' . Db::table('chat_group_members')
                . ' SET role=1, updatetime=? WHERE group_id=? AND role=2 AND status=1',
                [$now, $groupId]
            );
            foreach ($adminIds as $uid) {
                $this->ensureMember($groupId, $uid, 2, $now);
                Db::exec(
                    'UPDATE ' . Db::table('chat_group_members')
                    . ' SET role=2, status=1, updatetime=? WHERE group_id=? AND user_id=?',
                    [$now, $groupId, $uid]
                );
            }
            $this->refreshMemberCount($groupId);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            throw $e;
        }
        $this->invalidateMembersCache($groupId);
        return $this->members($groupId);
    }

    public function addMembers($groupId, array $memberIds, $role = 1)
    {
        $groupId = (int)$groupId;
        $role = in_array((int)$role, [1, 2, 3], true) ? (int)$role : 1;
        $now = time();
        foreach (array_unique(array_map('intval', $memberIds)) as $uid) {
            if ($uid <= 0) {
                continue;
            }
            $this->ensureMember($groupId, $uid, $role, $now);
            $this->invalidateUserGroupsCache($uid);
        }
        $this->refreshMemberCount($groupId);
        $this->invalidateMembersCache($groupId);
        return $this->members($groupId);
    }

    public function updateGroup($groupId, array $data)
    {
        $groupId = (int)$groupId;
        $group = $this->get($groupId);
        if (!$group) {
            throw new \InvalidArgumentException('invalid group');
        }
        $sets = [];
        $bind = [];
        if (isset($data['name'])) {
            $sets[] = 'name=?';
            $bind[] = mb_substr(trim((string)$data['name']), 0, 64);
        }
        if (isset($data['notice'])) {
            $sets[] = 'notice=?';
            $bind[] = mb_substr(trim((string)$data['notice']), 0, 500);
        }
        if (isset($data['avatar'])) {
            $sets[] = 'avatar=?';
            $bind[] = mb_substr(trim((string)$data['avatar']), 0, 255);
        }
        if (isset($data['status'])) {
            $sets[] = 'status=?';
            $bind[] = (int)$data['status'];
        }
        if (isset($data['privacy_mode'])) {
            $sets[] = 'privacy_mode=?';
            $bind[] = ((string)$data['privacy_mode'] === 'open') ? 'open' : 'private';
        }
        if (isset($data['chat_mode'])) {
            $sets[] = 'chat_mode=?';
            $bind[] = ((string)$data['chat_mode'] === 'grab') ? 'grab' : 'chat';
        }
        if (isset($data['hide_member_list'])) {
            $sets[] = 'hide_member_list=?';
            $bind[] = (int)$data['hide_member_list'] ? 1 : 0;
        }
        if (isset($data['display_member_count'])) {
            $sets[] = 'display_member_count=?';
            $bind[] = max(0, (int)$data['display_member_count']);
        }
        if (!$sets) {
            return $group;
        }
        $sets[] = 'updatetime=?';
        $bind[] = time();
        $bind[] = $groupId;
        Db::exec('UPDATE ' . Db::table('chat_groups') . ' SET ' . implode(',', $sets) . ' WHERE id=?', $bind);
        return $this->get($groupId);
    }

    protected function ensureMember($groupId, $userId, $role, $now)
    {
        $row = Db::fetch(
            'SELECT id FROM ' . Db::table('chat_group_members') . ' WHERE group_id=? AND user_id=? LIMIT 1',
            [$groupId, $userId]
        );
        if ($row) {
            Db::exec(
                'UPDATE ' . Db::table('chat_group_members')
                . ' SET role=?, status=1, updatetime=? WHERE id=?',
                [$role, $now, (int)$row['id']]
            );
            return;
        }
        Db::exec(
            'INSERT INTO ' . Db::table('chat_group_members')
            . ' (group_id,user_id,role,status,jointime,updatetime) VALUES (?,?,?,1,?,?)',
            [$groupId, $userId, $role, $now, $now]
        );
    }

    protected function refreshMemberCount($groupId)
    {
        $cnt = Db::fetch(
            'SELECT COUNT(*) AS c FROM ' . Db::table('chat_group_members')
            . ' WHERE group_id=? AND status=1',
            [(int)$groupId]
        );
        Db::exec(
            'UPDATE ' . Db::table('chat_groups') . ' SET member_count=?, updatetime=? WHERE id=?',
            [(int)($cnt['c'] ?? 0), time(), (int)$groupId]
        );
    }
}
