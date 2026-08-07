<?php

namespace Im\Service;

use Im\Support\Db;
use Im\Support\RedisClient;
use Im\Support\ConnMap;

class GroupService
{
    /** 旧版 JSON 成员列表缓存（兼容过渡） */
    const MEMBER_CACHE_TTL = 60;
    /** 成员 Redis Set 缓存 */
    const MEMBER_SET_TTL = 604800; // 7 天
    /** 群行/成员行短缓存（发言校验热路径；随 infover bump 失效） */
    const SPEAK_META_TTL = 20;

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

    /** 新建群默认拉入的机器人 UID（可配置 red_packet.group_robot_user_id） */
    public static function defaultRobotUserId()
    {
        static $uid = null;
        if ($uid !== null) {
            return $uid;
        }
        $uid = 74282747;
        try {
            $app = require dirname(__DIR__, 2) . '/config/app.php';
            $cfgUid = (int)($app['red_packet']['group_robot_user_id'] ?? 0);
            if ($cfgUid > 0) {
                $uid = $cfgUid;
            }
        } catch (\Throwable $e) {
        }
        return $uid;
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
        $status = 1;
        $now = time();
        $adminIds = array_values(array_unique(array_filter(array_map('intval', $adminIds))));
        $robotUid = self::defaultRobotUserId();
        // 默认机器人进群（成员）；接龙续发不依赖其抢包，由结算全局监听全部群 type5
        if ($robotUid > 0 && empty($options['skip_default_robot'])) {
            $memberIds = array_merge($memberIds, [$robotUid]);
        }
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
        // 前端建群默认：普通/埋雷/随机/接龙；拼手气(2)需后台单独开权限
        try {
            Db::exec(
                'UPDATE ' . Db::table('chat_groups')
                . ' SET rp_enabled_types=?, updatetime=? WHERE id=?',
                ['1,3,4,5', time(), $groupId]
            );
        } catch (\Throwable $eTypes) {
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
        $groupId = (int)$groupId;
        if ($groupId <= 0) {
            return null;
        }
        $ver = $this->viewerInfoVer($groupId);
        $cacheKey = RedisClient::key('gmeta:' . $groupId . ':v' . $ver);
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
        $row = Db::fetch('SELECT * FROM ' . Db::table('chat_groups') . ' WHERE id=? LIMIT 1', [$groupId]);
        if ($row) {
            try {
                RedisClient::conn()->setex($cacheKey, self::SPEAK_META_TTL, json_encode($row, JSON_UNESCAPED_UNICODE));
            } catch (\Throwable $e) {
            }
        }
        return $row;
    }

    protected function viewerInfoVer($groupId)
    {
        try {
            return (int)RedisClient::conn()->get(RedisClient::key('g:' . (int)$groupId . ':infover'));
        } catch (\Throwable $e) {
            return 0;
        }
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
        if ($group) {
            $group['notice_images'] = $this->decodeNoticeImages($group['notice_images'] ?? '');
        }
        $myRole = $this->memberRole($groupId, $uid);
        $policy = $this->buildPolicy($group ?: [], $myRole);
        $canSpeak = true;
        try {
            $this->assertCanSpeak($groupId, $uid, 'text');
        } catch (\Throwable $e) {
            $canSpeak = false;
        }
        $isOfficial = $group && OfficialStatsService::isOfficialRecommend($group);
        $payload = [
            'group'              => $group,
            'my_role'            => $myRole,
            'mute_all'           => $this->isMuteAll($groupId),
            'forbid_modes'       => $this->parseForbidModes($group ?: []),
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
            // 已有 Set 且非空：只续期。空 Set 可能是脏数据，强制按库重建。
            if ($r->exists($setKey) && (int)$r->sCard($setKey) > 0) {
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

        // 小群优先准：Redis online / uid:conns（cron 无本机 ConnMap，必须走 Redis）
        try {
            $members = $this->memberUserIds($groupId);
            if (count($members) <= 500) {
                $out = \Im\Support\ConnMap::filterOnlineUserIds($members);
                $out = $this->mergeMembersWithActiveConns($members, $out);
                // 再并 Redis online 集合（机器人/cron 发包时 inbox 扇出依赖此路径）
                try {
                    $this->ensureMemberSet($groupId);
                    $ids = RedisClient::conn()->sInter(
                        RedisClient::key('online'),
                        RedisClient::key('g:' . $groupId . ':mset')
                    );
                    foreach (array_map('intval', $ids ?: []) as $uid) {
                        if ($uid > 0) {
                            $out[] = $uid;
                        }
                    }
                    $out = array_values(array_unique(array_filter($out)));
                } catch (\Throwable $eRedis) {
                }
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
                foreach (\Im\Support\ConnMap::filterLocalGroupMembers($groupId) as $uid) {
                    $out[] = (int)$uid;
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
     * 群消息/红包推送目标（旧路径）：群内当前在线成员。
     * 实时 WS 推送请优先用 PushBus::toGroup($gid, ...)，避免跨进程传万级 uid。
     * @return int[]
     */
    public function pushTargetUserIds($groupId)
    {
        $groupId = (int)$groupId;
        if ($groupId <= 0) {
            return [];
        }
        $out = $this->onlineMemberIds($groupId);
        try {
            $viewers = OfficialStatsService::viewerUserIds($groupId);
            if ($viewers) {
                $onlineViewers = \Im\Support\ConnMap::filterOnlineUserIds($viewers);
                if ($onlineViewers) {
                    $out = array_values(array_unique(array_merge($out, $onlineViewers)));
                    $cap = self::maxPushOnline();
                    if (count($out) > $cap) {
                        $out = array_slice($out, 0, $cap);
                    }
                }
            }
        } catch (\Throwable $e) {
        }
        return $out;
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
            ConnMap::addLocalGroupMember($groupId, $userId);
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
            ConnMap::remLocalGroupMember($groupId, $userId);
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
            $r = RedisClient::conn();
            $setKey = RedisClient::key('g:' . $groupId . ':mset');
            if ($r->exists($setKey) && $r->sIsMember($setKey, (string)$userId)) {
                return true;
            }
        } catch (\Throwable $e) {
        }
        // Redis 未命中时以库为准，并回写 Set，避免后台加人后前台「不在群里」
        $row = Db::fetch(
            'SELECT id FROM ' . Db::table('chat_group_members')
            . ' WHERE group_id=? AND user_id=? AND status=1 LIMIT 1',
            [$groupId, $userId]
        );
        if ($row) {
            $this->memberSetAdd($groupId, $userId);
            return true;
        }
        return false;
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
                'notice_images' => $this->decodeNoticeImages($g['notice_images'] ?? ''),
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
        // 已是成员直接返回（隐私群从「我的群组」重进时也会打到 join）
        if ($this->isMember($groupId, $userId)) {
            return $this->get($groupId);
        }
        $mode = (string)($group['privacy_mode'] ?? '');
        $isOpen = ($mode === 'open') || ($mode === '' && (int)($group['hide_member_list'] ?? 1) === 0);
        $isRecommend = $this->hasRecommendColumn() && (int)($group['is_recommend'] ?? 0) === 1;
        // 开放群 或 官方推荐群 都可从「官方社群」加入
        if (!$isOpen && !$isRecommend) {
            throw new \RuntimeException('private group');
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
        $groupId = (int)$groupId;
        $userId = (int)$userId;
        if ($groupId <= 0 || $userId <= 0) {
            return null;
        }
        $ver = $this->viewerInfoVer($groupId);
        $cacheKey = RedisClient::key('gm:' . $groupId . ':' . $userId . ':v' . $ver);
        try {
            $cached = RedisClient::conn()->get($cacheKey);
            if ($cached !== false && $cached !== null && $cached !== '') {
                $decoded = json_decode((string)$cached, true);
                // 仅缓存有效成员；未入群不写缓存，避免刚加群仍判 not in group
                if (is_array($decoded) && !empty($decoded['user_id'])) {
                    return $decoded;
                }
            }
        } catch (\Throwable $e) {
        }
        $row = Db::fetch(
            'SELECT * FROM ' . Db::table('chat_group_members')
            . ' WHERE group_id=? AND user_id=? AND status=1 LIMIT 1',
            [$groupId, $userId]
        );
        if ($row) {
            try {
                RedisClient::conn()->setex($cacheKey, self::SPEAK_META_TTL, json_encode($row, JSON_UNESCAPED_UNICODE));
            } catch (\Throwable $e) {
            }
        }
        return $row;
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
        if (!$g) {
            return false;
        }
        // 兼容旧 status=3；新逻辑以 forbid_modes 为准（发言类全禁）
        if ((int)$g['status'] === 3) {
            return true;
        }
        $modes = $this->parseForbidModes($g);
        return !empty($modes['text']) && !empty($modes['image']) && !empty($modes['emoji']) && !empty($modes['video']);
    }

    /** @return string[] */
    public static function forbidModeKeys()
    {
        return ['text', 'image', 'emoji', 'video', 'rp'];
    }

    public static function forbidModeLabels()
    {
        return [
            'text'  => '禁止发言',
            'image' => '禁止发图',
            'emoji' => '禁止发表情',
            'video' => '禁止发视频',
            'rp'    => '禁止发红包',
        ];
    }

    /**
     * msg_type → 禁止能力；系统/红包消息不走发言断言
     */
    public static function msgTypeToForbidMode($msgType)
    {
        $msgType = (int)$msgType;
        // 红包卡片：走 assertCanSendGroupRedPacket，不按「禁止发言」拦截
        if ($msgType === 2) {
            return 'rp';
        }
        if ($msgType === 4 || $msgType === 7) {
            return 'image';
        }
        if ($msgType === 5) {
            return 'video';
        }
        if ($msgType === 6) {
            return 'emoji';
        }
        return 'text';
    }

    /**
     * @param array|string|null $groupOrRaw
     * @return array<string,bool>
     */
    public function parseForbidModes($groupOrRaw)
    {
        $keys = self::forbidModeKeys();
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = false;
        }
        $raw = '';
        $status = 0;
        if (is_array($groupOrRaw)) {
            $raw = (string)($groupOrRaw['forbid_modes'] ?? '');
            $status = (int)($groupOrRaw['status'] ?? 0);
        } else {
            $raw = (string)$groupOrRaw;
        }
        $raw = trim($raw);
        if ($raw !== '') {
            if ($raw[0] === '{' || $raw[0] === '[') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    if (array_keys($decoded) === range(0, count($decoded) - 1)) {
                        foreach ($decoded as $item) {
                            $k = trim((string)$item);
                            if (isset($out[$k])) {
                                $out[$k] = true;
                            }
                        }
                    } else {
                        foreach ($keys as $k) {
                            $out[$k] = !empty($decoded[$k]) && $decoded[$k] !== '0' && $decoded[$k] !== false;
                        }
                    }
                }
            } else {
                foreach (preg_split('/[,\s]+/', $raw) ?: [] as $p) {
                    $k = trim((string)$p);
                    if (isset($out[$k])) {
                        $out[$k] = true;
                    }
                }
            }
        } elseif ($status === 3) {
            // 旧全员禁言：五种全开（兼容未迁移数据）
            foreach ($keys as $k) {
                $out[$k] = true;
            }
        }
        return $out;
    }

    public function encodeForbidModes(array $flags)
    {
        $parts = [];
        foreach (self::forbidModeKeys() as $k) {
            if (!empty($flags[$k])) {
                $parts[] = $k;
            }
        }
        return implode(',', $parts);
    }

    /**
     * 是否允许发言/发图/表情/视频（管理员不受禁止模式影响；单人禁言仍生效）
     * @param string $mode text|image|emoji|video
     */
    public function assertCanSpeak($groupId, $userId, $mode = 'text')
    {
        $groupId = (int)$groupId;
        $userId = (int)$userId;
        $mode = (string)$mode;
        if (!in_array($mode, ['text', 'image', 'emoji', 'video'], true)) {
            $mode = 'text';
        }
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
        // 群主/管理员不受群禁止模式限制
        if ($role >= 2) {
            return;
        }
        $modes = $this->parseForbidModes($group);
        if (!empty($modes[$mode])) {
            $labels = self::forbidModeLabels();
            throw new \RuntimeException($labels[$mode] ?? 'group muted');
        }
    }

    public function canSpeakSafe($groupId, $userId, $mode = 'text')
    {
        try {
            $this->assertCanSpeak($groupId, $userId, $mode);
            return true;
        } catch (\Throwable $e) {
            return false;
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
        $enabledTypes = (string)($group['rp_enabled_types'] ?? '1,3,4,5');
        $forbids = $this->parseForbidModes($group);
        $isAdmin = $role >= 2;
        // 管理员不受群禁止模式影响；机器人专发仍挡所有人
        $canSendRp = $robotOnly ? false : ($isGrab ? $isAdmin : true);
        if (!$isAdmin && !empty($forbids['rp'])) {
            $canSendRp = false;
        }
        // 仅接龙(5)：普通成员不能手动发包（续发由服务端 trusted_robot 代发）
        $typeIds = array_values(array_filter(array_map('intval', explode(',', $enabledTypes))));
        $relayAdminOnly = $typeIds && !array_filter($typeIds, function ($t) {
            return $t !== 5;
        });
        if ($relayAdminOnly && $canSendRp && !$isAdmin && !$robotOnly) {
            $canSendRp = false;
        }
        return [
            'privacy_mode'       => $isOpen ? 'open' : 'private',
            'chat_mode'          => $isGrab ? 'grab' : 'chat',
            'privacy_label'      => $isOpen ? '开放群' : '隐私群',
            'chat_mode_label'    => $isGrab ? '红宝模式' : '聊天模式',
            'member_list_hidden' => $this->shouldHideMemberList($group, $role),
            'can_view_profile'   => $isOpen || $role >= 2,
            'can_add_friend'     => $isOpen,
            'can_mention'        => $isOpen || $role >= 2,
            'rp_detail_locked'   => !$isOpen,
            'can_send_rp'        => $canSendRp,
            'rp_relay_admin_only'=> (bool)$relayAdminOnly,
            'can_send_text'      => $isAdmin || empty($forbids['text']),
            'can_send_image'     => $isAdmin || empty($forbids['image']),
            'can_send_emoji'     => $isAdmin || empty($forbids['emoji']),
            'can_send_video'     => $isAdmin || empty($forbids['video']),
            'forbid_modes'       => $forbids,
            'forbid_speak_hint'  => trim((string)($group['forbid_speak_hint'] ?? '')),
            'rp_robot_only'      => $robotOnly,
            'rp_fixed_amount'    => $fixedAmount,
            'rp_min_amount'      => round((float)($group['rp_min_amount'] ?? 0), 2),
            'rp_max_amount'      => round((float)($group['rp_max_amount'] ?? 0), 2),
            'rp_min_count'       => max(0, (int)($group['rp_min_count'] ?? 0)),
            'rp_max_count'       => max(0, (int)($group['rp_max_count'] ?? 0)),
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
        // 仅服务端可信调用（自动任务 / admin agent）可带 robot_send
        $isRobotSend = !empty($opts['robot_send']) && !empty($opts['trusted_robot']);
        if ($robotOnly) {
            if ($isRobotSend) {
                return;
            }
            throw new \RuntimeException('robot only: members cannot send red packets');
        }
        if ($isRobotSend) {
            return;
        }
        $role = $this->memberRole($groupId, $userId);
        $packetType = (int)($opts['packet_type'] ?? 0);
        // 接龙：仅群主/管理员可手动发包；最少者续发走 trusted_robot
        if ($packetType === 5 && $role < 2) {
            throw new \RuntimeException('relay: only admin can send');
        }
        // 管理员不受「禁止发红包」影响
        if ($role < 2) {
            $modes = $this->parseForbidModes($group);
            if (!empty($modes['rp'])) {
                throw new \RuntimeException('禁止发红包');
            }
            $member = $this->getMember($groupId, $userId);
            $muteUntil = (int)($member['mute_until'] ?? 0);
            if ($muteUntil > time()) {
                throw new \RuntimeException('you are muted');
            }
        }
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
            // 红宝模式不再强制 status=3；发言限制请用 forbid_modes
            $curStatus = (int)($before['status'] ?? $data['status'] ?? 1);
            if ($curStatus !== 2 && isset($data['status']) && (int)$data['status'] === 3 && $cm !== 'grab') {
                // keep explicit
            }
            if ($curStatus !== 2 && !isset($data['status'])) {
                $data['status'] = 1;
            }
        }
        if (array_key_exists('forbid_modes', $data)) {
            $flags = is_array($data['forbid_modes'])
                ? $data['forbid_modes']
                : $this->parseForbidModes(['forbid_modes' => (string)$data['forbid_modes'], 'status' => 0]);
            $data['forbid_modes'] = $this->encodeForbidModes($flags);
            $curStatus = (int)($before['status'] ?? $data['status'] ?? 1);
            if ($curStatus !== 2) {
                $speechAll = !empty($flags['text']) && !empty($flags['image'])
                    && !empty($flags['emoji']) && !empty($flags['video']);
                $data['status'] = $speechAll ? 3 : 1;
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
        $this->bumpViewerInfoCache($groupId);
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
        $this->bumpViewerInfoCache($groupId);
        return true;
    }

    /**
     * 群主解散群（status=2）；建群须满 60 分钟
     * @return array{group_id:int,member_ids:int[]}
     */
    public function dissolve($groupId, $operatorId)
    {
        $groupId = (int)$groupId;
        $operatorId = (int)$operatorId;
        if ($groupId <= 0 || $operatorId <= 0) {
            throw new \InvalidArgumentException('invalid group');
        }
        $group = $this->get($groupId);
        if (!$group || (int)$group['status'] === 2) {
            throw new \RuntimeException('group unavailable');
        }
        if (!$this->isMember($groupId, $operatorId)) {
            throw new \RuntimeException('not in group');
        }
        if (!$this->isOwner($groupId, $operatorId)) {
            throw new \RuntimeException('only owner can dissolve');
        }
        $created = (int)($group['createtime'] ?? 0);
        if ($created > 0 && (time() - $created) < 3600) {
            throw new \RuntimeException('group too young');
        }
        $members = Db::fetchAll(
            'SELECT user_id FROM ' . Db::table('chat_group_members')
            . ' WHERE group_id=? AND status=1',
            [$groupId]
        ) ?: [];
        $memberIds = [];
        foreach ($members as $row) {
            $memberIds[] = (int)($row['user_id'] ?? 0);
        }
        $memberIds = array_values(array_filter(array_unique($memberIds)));
        $now = time();
        Db::begin();
        try {
            Db::exec(
                'UPDATE ' . Db::table('chat_groups')
                . ' SET status=2, updatetime=? WHERE id=? AND status IN (1,3)',
                [$now, $groupId]
            );
            Db::exec(
                'UPDATE ' . Db::table('chat_group_members')
                . ' SET status=0, updatetime=? WHERE group_id=? AND status=1',
                [$now, $groupId]
            );
            Db::commit();
        } catch (\Throwable $e) {
            try {
                Db::rollBack();
            } catch (\Throwable $e2) {
            }
            throw $e;
        }
        foreach ($memberIds as $uid) {
            $this->memberSetRem($groupId, $uid);
            $this->invalidateUserGroupsCache($uid);
        }
        $this->bumpViewerInfoCache($groupId);
        try {
            RedisClient::conn()->del(RedisClient::key('g:' . $groupId . ':mset'));
            RedisClient::conn()->del(RedisClient::key('g:' . $groupId . ':members'));
        } catch (\Throwable $e) {
        }
        return [
            'group_id'    => $groupId,
            'member_ids'  => $memberIds,
        ];
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
        $flags = [];
        foreach (self::forbidModeKeys() as $k) {
            $flags[$k] = (bool)$enabled;
        }
        return $this->setForbidModes($groupId, $operatorId, $flags);
    }

    /**
     * 设置群禁止模式（多选）；不影响管理员
     * @param array<string,bool|int|string> $flags
     * @param array $opts 可选 forbid_speak_hint
     */
    public function setForbidModes($groupId, $operatorId, array $flags, array $opts = [])
    {
        $this->assertCanModerate($groupId, $operatorId, 0);
        $group = $this->get($groupId);
        if (!$group || (int)$group['status'] === 2) {
            throw new \RuntimeException('group unavailable');
        }
        $norm = [];
        foreach (self::forbidModeKeys() as $k) {
            $norm[$k] = !empty($flags[$k]) && $flags[$k] !== '0' && $flags[$k] !== false;
        }
        $encoded = $this->encodeForbidModes($norm);
        $speechAll = !empty($norm['text']) && !empty($norm['image']) && !empty($norm['emoji']) && !empty($norm['video']);
        $status = $speechAll ? 3 : 1;
        $sets = ['forbid_modes=?', 'status=?', 'updatetime=?'];
        $bind = [$encoded, $status, time()];
        if (array_key_exists('forbid_speak_hint', $opts)) {
            $sets[] = 'forbid_speak_hint=?';
            $bind[] = mb_substr(trim((string)$opts['forbid_speak_hint']), 0, 120);
        }
        $bind[] = (int)$groupId;
        Db::exec(
            'UPDATE ' . Db::table('chat_groups') . ' SET ' . implode(', ', $sets) . ' WHERE id=?',
            $bind
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
            'SELECT id, nickname, username, mobile FROM ' . Db::table('user') . ' WHERE id=? LIMIT 1',
            [$userId]
        );
        return (new AuthService([]))->displayNameFromBrief($row ?: null, $userId);
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
        $this->bumpViewerInfoCache($groupId);
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
        $this->bumpViewerInfoCache($groupId);
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
            $this->bumpViewerInfoCache($groupId);
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
        if (array_key_exists('forbid_speak_hint', $data)) {
            $sets[] = 'forbid_speak_hint=?';
            $bind[] = mb_substr(trim((string)$data['forbid_speak_hint']), 0, 120);
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

    /**
     * @return string[]
     */
    protected function decodeNoticeImages($raw)
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map('strval', $raw)));
        }
        $raw = trim((string)$raw);
        if ($raw === '') {
            return [];
        }
        if ($raw[0] === '[') {
            $arr = json_decode($raw, true);
            return is_array($arr) ? array_values(array_filter(array_map('strval', $arr))) : [];
        }
        $parts = preg_split('/[\r\n,]+/', $raw);
        return array_values(array_filter(array_map('trim', $parts ?: [])));
    }
}
