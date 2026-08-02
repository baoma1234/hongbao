<?php

namespace Im\Service;

use Im\Support\Db;
use Im\Support\RedisClient;

class GroupService
{
    /** 旧版 JSON 成员列表缓存（兼容过渡） */
    const MEMBER_CACHE_TTL = 60;
    /** 成员 Redis Set 缓存 */
    const MEMBER_SET_TTL = 604800; // 7 天

    public static function maxMembers()
    {
        static $n = null;
        if ($n !== null) {
            return $n;
        }
        $n = 10000;
        try {
            $app = require dirname(__DIR__, 2) . '/config/app.php';
            if (isset($app['group']['max_members'])) {
                $n = max(100, (int)$app['group']['max_members']);
            }
        } catch (\Throwable $e) {
        }
        return $n;
    }

    /** 单条消息最多推送的在线人数（防止万人群同时在线打爆） */
    public static function maxPushOnline()
    {
        static $n = null;
        if ($n !== null) {
            return $n;
        }
        $n = 2500;
        try {
            $app = require dirname(__DIR__, 2) . '/config/app.php';
            if (isset($app['group']['max_push_online'])) {
                $n = max(100, (int)$app['group']['max_push_online']);
            }
        } catch (\Throwable $e) {
        }
        return $n;
    }

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
                [$name, $ownerUserId, count($members), self::maxMembers(), $status, $privacy, $chatMode, $hideList, $now, $now]
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
        // 建群引导卡：永久绑定群主 1% 发包管理津贴
        if (!empty($options['bind_owner_rebate'])) {
            try {
                Db::exec(
                    'UPDATE ' . Db::table('chat_groups')
                    . ' SET rp_agent_rebate_rate=?, updatetime=? WHERE id=?',
                    ['0.0100', time(), $groupId]
                );
            } catch (\Throwable $eBind) {
                // 列不存在时忽略，结算侧仍有默认 1%
            }
        }
        $this->invalidateMembersCache($groupId);
        $this->ensureMemberSet($groupId);
        return $this->get($groupId);
    }

    public function get($groupId)
    {
        return Db::fetch('SELECT * FROM ' . Db::table('chat_groups') . ' WHERE id=? LIMIT 1', [(int)$groupId]);
    }

    /**
     * 进房/历史附带的群资料（短缓存，吸收连刷）
     * @return array{group:mixed,my_role:int,mute_all:bool,member_count:int,online_count:int,member_list_hidden:bool,can_speak:bool,policy:array}
     */
    public function viewerInfoPayload($groupId, $uid)
    {
        $groupId = (int)$groupId;
        $uid = (int)$uid;
        $ver = 0;
        try {
            $ver = (int)RedisClient::conn()->get(RedisClient::key('g:' . $groupId . ':infover'));
        } catch (\Throwable $e) {
        }
        $cacheKey = RedisClient::key('ginfo:' . $groupId . ':' . $uid . ':v' . $ver);
        try {
            $cached = RedisClient::conn()->get($cacheKey);
            if ($cached !== false && $cached !== null && $cached !== '') {
                $decoded = json_decode((string)$cached, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        } catch (\Throwable $e) {
        }

        $group = $this->get($groupId);
        if ($group && !empty($group['notice_i18n']) && is_string($group['notice_i18n'])) {
            $map = json_decode($group['notice_i18n'], true);
            $group['notice_i18n'] = is_array($map) ? $map : new \stdClass();
        } elseif ($group) {
            $group['notice_i18n'] = new \stdClass();
        }
        $myRole = $this->memberRole($groupId, $uid);
        $policy = $this->buildPolicy($group ?: [], $myRole);
        $canSpeak = true;
        try {
            $this->assertCanSpeak($groupId, $uid);
        } catch (\Throwable $e) {
            $canSpeak = false;
        }
        $isOfficial = $group && OfficialStatsService::isOfficialRecommend($group);
        $payload = [
            'group'              => $group,
            'my_role'            => $myRole,
            'mute_all'           => $this->isMuteAll($groupId),
            'member_count'       => $this->publicMemberCount($group ?: []),
            'online_count'       => $isOfficial ? OfficialStatsService::onlineCount($groupId) : 0,
            'member_list_hidden' => !empty($policy['member_list_hidden']),
            'can_speak'          => $canSpeak,
            'policy'             => $policy,
        ];
        try {
            RedisClient::conn()->setex($cacheKey, 20, json_encode($payload, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
        }
        return $payload;
    }

    /** 群设置/禁言等变更后 bump，使 viewerInfoPayload 缓存失效 */
    public function bumpViewerInfoCache($groupId)
    {
        $groupId = (int)$groupId;
        if ($groupId <= 0) {
            return;
        }
        try {
            RedisClient::conn()->incr(RedisClient::key('g:' . $groupId . ':infover'));
        } catch (\Throwable $e) {
        }
    }

    /**
     * 确保群成员 Redis Set 存在（万人群用 SISMEMBER / SINTER，避免每次拉全量 JSON）
     */
    public function ensureMemberSet($groupId)
    {
        $groupId = (int)$groupId;
        if ($groupId <= 0) {
            return;
        }
        $setKey = RedisClient::key('g:' . $groupId . ':mset');
        try {
            $r = RedisClient::conn();
            if ($r->exists($setKey)) {
                $r->expire($setKey, self::MEMBER_SET_TTL);
                return;
            }
            $rows = Db::fetchAll(
                'SELECT user_id FROM ' . Db::table('chat_group_members') . ' WHERE group_id=? AND status=1',
                [$groupId]
            );
            $r->multi(\Redis::PIPELINE);
            $r->del($setKey);
            foreach ($rows as $row) {
                $uid = (int)$row['user_id'];
                if ($uid > 0) {
                    $r->sAdd($setKey, (string)$uid);
                }
            }
            $r->expire($setKey, self::MEMBER_SET_TTL);
            // 清掉旧 JSON 缓存
            $r->del(RedisClient::key('g:' . $groupId . ':members'));
            $r->exec();
        } catch (\Throwable $e) {
        }
    }

    public function memberUserIds($groupId)
    {
        $groupId = (int)$groupId;
        $this->ensureMemberSet($groupId);
        try {
            $ids = RedisClient::conn()->sMembers(RedisClient::key('g:' . $groupId . ':mset'));
            if (is_array($ids) && $ids) {
                return array_map('intval', $ids);
            }
            // 空群也算命中
            if (is_array($ids)) {
                return [];
            }
        } catch (\Throwable $e) {
        }

        $rows = Db::fetchAll(
            'SELECT user_id FROM ' . Db::table('chat_group_members') . ' WHERE group_id=? AND status=1',
            [$groupId]
        );
        return array_map(function ($r) {
            return (int)$r['user_id'];
        }, $rows);
    }

    /**
     * 群内当前在线成员（可推送目标）
     * 小群：按成员表 + ConnMap/Redis online 过滤（含本机已连接但 Redis online 漏记的情况）
     * 大群：SINTER(online, members)，避免拉全员
     * @return int[]
     */
    public function onlineMemberIds($groupId)
    {
        $groupId = (int)$groupId;
        if ($groupId <= 0) {
            return [];
        }
        $cap = self::maxPushOnline();

        // 小群优先准：本机连接 + Redis online / uid:conns，避免 SINTER 漏推
        try {
            $members = $this->memberUserIds($groupId);
            if (count($members) <= 500) {
                $out = \Im\Support\ConnMap::filterOnlineUserIds($members);
                // 再并上「有 uid:conns」但未进 online 集合的（TTL/异常场景）
                $out = $this->mergeMembersWithActiveConns($members, $out);
                if (count($out) > $cap) {
                    $out = array_slice($out, 0, $cap);
                }
                return $out;
            }
        } catch (\Throwable $e) {
        }

        $this->ensureMemberSet($groupId);
        try {
            $ids = RedisClient::conn()->sInter(
                RedisClient::key('online'),
                RedisClient::key('g:' . $groupId . ':mset')
            );
            $out = array_values(array_unique(array_filter(array_map('intval', $ids ?: []))));
            // 并入本机在线（SINTER 可能漏掉 Redis online 未写入的连接）
            try {
                foreach ($this->memberUserIds($groupId) as $uid) {
                    if (\Im\Support\ConnMap::isLocalOnline($uid)) {
                        $out[] = (int)$uid;
                    }
                }
                $out = array_values(array_unique(array_filter($out)));
            } catch (\Throwable $eLocal) {
            }
            if (count($out) > $cap) {
                error_log('[IM] group online fanout capped gid=' . $groupId . ' online=' . count($out) . ' cap=' . $cap);
                $out = array_slice($out, 0, $cap);
            }
            return $out;
        } catch (\Throwable $e) {
            return \Im\Support\ConnMap::filterOnlineUserIds($this->memberUserIds($groupId));
        }
    }

    /**
     * 把仍有 Redis 连接登记的成员并入在线列表（修复 online 集合漏记）
     * @param int[] $members
     * @param int[] $online
     * @return int[]
     */
    protected function mergeMembersWithActiveConns(array $members, array $online)
    {
        $map = [];
        foreach ($online as $uid) {
            $uid = (int)$uid;
            if ($uid > 0) {
                $map[$uid] = true;
            }
        }
        $missing = [];
        foreach ($members as $uid) {
            $uid = (int)$uid;
            if ($uid > 0 && empty($map[$uid])) {
                $missing[] = $uid;
            }
        }
        if (!$missing) {
            return array_keys($map);
        }
        try {
            $r = RedisClient::conn();
            $chunkSize = 100;
            for ($i = 0; $i < count($missing); $i += $chunkSize) {
                $chunk = array_slice($missing, $i, $chunkSize);
                $r->multi(\Redis::PIPELINE);
                foreach ($chunk as $uid) {
                    $r->sCard(RedisClient::key('uid:' . $uid . ':conns'));
                }
                $cards = $r->exec();
                if (!is_array($cards)) {
                    continue;
                }
                foreach ($chunk as $j => $uid) {
                    if (!empty($cards[$j])) {
                        $map[$uid] = true;
                        // 自愈：补回 online 集合
                        try {
                            $r->sAdd(RedisClient::key('online'), (string)$uid);
                        } catch (\Throwable $eAdd) {
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
        }
        return array_values(array_keys($map));
    }

    public function invalidateMembersCache($groupId)
    {
        try {
            $gid = (int)$groupId;
            RedisClient::conn()->del(
                RedisClient::key('g:' . $gid . ':members'),
                RedisClient::key('g:' . $gid . ':mset')
            );
        } catch (\Throwable $e) {
        }
    }

    /** 增量维护成员 Set，避免踢人/加人后整表重建 */
    public function memberSetAdd($groupId, $userId)
    {
        $groupId = (int)$groupId;
        $userId = (int)$userId;
        if ($groupId <= 0 || $userId <= 0) {
            return;
        }
        try {
            $this->ensureMemberSet($groupId);
            $r = RedisClient::conn();
            $key = RedisClient::key('g:' . $groupId . ':mset');
            $r->sAdd($key, (string)$userId);
            $r->expire($key, self::MEMBER_SET_TTL);
            $r->del(RedisClient::key('g:' . $groupId . ':members'));
        } catch (\Throwable $e) {
        }
    }

    public function memberSetRem($groupId, $userId)
    {
        $groupId = (int)$groupId;
        $userId = (int)$userId;
        if ($groupId <= 0 || $userId <= 0) {
            return;
        }
        try {
            $r = RedisClient::conn();
            $key = RedisClient::key('g:' . $groupId . ':mset');
            if ($r->exists($key)) {
                $r->sRem($key, (string)$userId);
                $r->expire($key, self::MEMBER_SET_TTL);
            }
            $r->del(RedisClient::key('g:' . $groupId . ':members'));
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
            . ' WHERE group_id=? AND status=1 ORDER BY role DESC, id ASC LIMIT 200',
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
        $this->ensureMemberSet($groupId);
        try {
            return (bool)RedisClient::conn()->sIsMember(
                RedisClient::key('g:' . $groupId . ':mset'),
                (string)$userId
            );
        } catch (\Throwable $e) {
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
        $hasWeigh = $this->hasWeighColumn();
        // 官方社群：以 is_recommend=1 为准（后台已勾选推荐即可展示），不要求 privacy_mode=open
        $sql = 'SELECT g.* FROM ' . Db::table('chat_groups') . ' g'
            . ' WHERE g.status IN (1,3)';
        if ($hasCol) {
            $sql .= ' AND IFNULL(g.is_recommend,0)=1';
        } else {
            $sql .= " AND (g.privacy_mode='open' OR (IFNULL(g.privacy_mode,'')='' AND IFNULL(g.hide_member_list,1)=0))";
        }
        if ($hasWeigh) {
            $sql .= ' ORDER BY IFNULL(g.weigh,0) DESC, g.id DESC LIMIT 50';
        } else {
            $sql .= ' ORDER BY g.id DESC LIMIT 50';
        }
        $rows = Db::fetchAll($sql);
        $ids = [];
        foreach ($rows as $g) {
            $ids[] = (int)$g['id'];
        }
        $joined = [];
        if ($userId > 0 && $ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $memRows = Db::fetchAll(
                'SELECT group_id FROM ' . Db::table('chat_group_members')
                . ' WHERE user_id=? AND status=1 AND group_id IN (' . $ph . ')',
                array_merge([$userId], $ids)
            );
            foreach ($memRows as $mr) {
                $joined[(int)$mr['group_id']] = true;
            }
        }
        $out = [];
        foreach ($rows as $g) {
            $gid = (int)$g['id'];
            $display = (int)($g['display_member_count'] ?? 0);
            $out[] = [
                'id'            => $gid,
                'name'          => (string)($g['name'] ?? ''),
                'avatar'        => (string)($g['avatar'] ?? ''),
                'notice'        => (string)($g['notice'] ?? ''),
                'member_count'  => OfficialStatsService::memberCount($gid, $display),
                'online_count'  => OfficialStatsService::onlineCount($gid),
                'is_member'     => !empty($joined[$gid]),
                'privacy_mode'  => (string)($g['privacy_mode'] ?? 'private'),
                'chat_mode'     => (string)($g['chat_mode'] ?? 'chat'),
                'weigh'         => (int)($g['weigh'] ?? 0),
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
        $isRecommend = $this->hasRecommendColumn() && (int)($group['is_recommend'] ?? 0) === 1;
        // 开放群 或 官方推荐群 都可从「官方社群」加入
        if (!$isOpen && !$isRecommend) {
            throw new \RuntimeException('private group');
        }
        if ($this->isMember($groupId, $userId)) {
            return $this->get($groupId);
        }
        $max = (int)($group['max_members'] ?? 0);
        if ($max <= 0) {
            $max = self::maxMembers();
        }
        $cnt = (int)($group['member_count'] ?? 0);
        if ($cnt >= $max) {
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

    protected function hasWeighColumn()
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        try {
            $row = Db::fetch('SHOW COLUMNS FROM ' . Db::table('chat_groups') . " LIKE 'weigh'");
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
        if (OfficialStatsService::isOfficialRecommend($group)) {
            $gid = (int)($group['id'] ?? 0);
            $display = (int)($group['display_member_count'] ?? 0);
            return OfficialStatsService::memberCount($gid, $display);
        }
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
        $robotOnly = (int)($group['rp_robot_only'] ?? 0) === 1;
        $fixedAmount = round((float)($group['rp_fixed_amount'] ?? 0), 2);
        if ($fixedAmount < 0) {
            $fixedAmount = 0.0;
        }
        $enabledTypes = (string)($group['rp_enabled_types'] ?? '2,3');
        // 仅自动任务/后台代发可发包时，前台任何人（含群主）都不可发
        $canSendRp = $robotOnly ? false : ($isGrab ? ($role >= 2) : true);
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
            'can_send_rp'        => $canSendRp,
            'rp_robot_only'      => $robotOnly,
            'rp_fixed_amount'    => $fixedAmount,
            'rp_enabled_types'   => $enabledTypes,
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

    public function assertCanSendGroupRedPacket($groupId, $userId, array $opts = [])
    {
        $group = $this->get($groupId);
        if (!$group) {
            throw new \RuntimeException('invalid group');
        }
        $robotOnly = (int)($group['rp_robot_only'] ?? 0) === 1;
        $isRobotSend = !empty($opts['robot_send']);
        if ($robotOnly) {
            if ($isRobotSend) {
                return;
            }
            throw new \RuntimeException('robot only: members cannot send red packets');
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
        $this->memberSetRem($groupId, $targetId);
        $this->invalidateUserGroupsCache($targetId);
        return true;
    }

    /**
     * 主动退群（群主不可退）
     * status=0 表示主动退出；踢出为 status=2
     */
    public function leave($groupId, $userId)
    {
        $groupId = (int)$groupId;
        $userId = (int)$userId;
        if ($groupId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException('invalid group');
        }
        if (!$this->isMember($groupId, $userId)) {
            throw new \RuntimeException('not in group');
        }
        if ($this->isOwner($groupId, $userId)) {
            throw new \RuntimeException('owner cannot leave');
        }
        $now = time();
        $n = Db::exec(
            'UPDATE ' . Db::table('chat_group_members')
            . ' SET status=0, role=1, updatetime=? WHERE group_id=? AND user_id=? AND status=1',
            [$now, $groupId, $userId]
        );
        if (!$n) {
            throw new \RuntimeException('not in group');
        }
        $this->refreshMemberCount($groupId);
        $this->memberSetRem($groupId, $userId);
        $this->invalidateUserGroupsCache($userId);
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
        $this->bumpViewerInfoCache($groupId);
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
        $this->bumpViewerInfoCache($groupId);
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
        $this->bumpViewerInfoCache($groupId);
        return $this->get($groupId);
    }

    public function inviteCandidates($groupId, $operatorId, $keyword = '', $limit = 50)
    {
        $this->assertCanModerate($groupId, $operatorId, 0);
        $limit = max(1, min(100, (int)$limit));
        $this->ensureMemberSet($groupId);
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
            if ($this->isMember($groupId, $uid)) {
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
        $group = $this->get($groupId);
        if (!$group) {
            throw new \InvalidArgumentException('invalid group');
        }
        $max = (int)($group['max_members'] ?? 0);
        if ($max <= 0) {
            $max = self::maxMembers();
        }
        $cnt = (int)($group['member_count'] ?? 0);
        $ids = array_values(array_unique(array_filter(array_map('intval', $memberIds), function ($uid) {
            return $uid > 0;
        })));
        $toAdd = [];
        foreach ($ids as $uid) {
            if ($this->isMember($groupId, $uid)) {
                // 已在群：仍可升级角色
                $this->ensureMember($groupId, $uid, $role, $now);
                continue;
            }
            $toAdd[] = $uid;
        }
        if ($toAdd && ($cnt + count($toAdd)) > $max) {
            throw new \RuntimeException('group full');
        }
        foreach ($toAdd as $uid) {
            $this->ensureMember($groupId, $uid, $role, $now);
            $this->memberSetAdd($groupId, $uid);
            $this->invalidateUserGroupsCache($uid);
        }
        if ($toAdd) {
            $this->refreshMemberCount($groupId);
        }
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
        $this->bumpViewerInfoCache($groupId);
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
