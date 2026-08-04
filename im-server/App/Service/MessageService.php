<?php

namespace Im\Service;

use Im\Support\Db;
use Im\Support\IdGenerator;
use Im\Support\RedisClient;

class MessageService
{
    public function sendPrivate($fromUserId, $toUserId, $content, $msgType = 1, $extra = null)
    {
        $fromUserId = (int)$fromUserId;
        $toUserId = (int)$toUserId;
        if ($fromUserId <= 0 || $toUserId <= 0 || $fromUserId === $toUserId) {
            throw new \InvalidArgumentException('invalid private chat target');
        }
        AdminService::assertCanPrivateChat($fromUserId, $toUserId);
        list($content, $msgType, $extra) = $this->prepareOutgoing($content, $msgType, $extra);
        $conv = IdGenerator::privateConversationId($fromUserId, $toUserId);
        return $this->insertMessage([
            'conversation_type' => 1,
            'conversation_id'   => $conv,
            'group_id'          => 0,
            'from_user_id'      => $fromUserId,
            'to_user_id'        => $toUserId,
            'msg_type'          => (int)$msgType,
            'content'           => $content,
            'extra'             => $extra,
        ]);
    }

    /**
     * 插入群消息且不校验发言权限（红包扣款已成功后的兜底落库）
     */
    public function insertGroupMessageUnchecked($fromUserId, $groupId, $content, $msgType = 1, $extra = null)
    {
        $fromUserId = (int)$fromUserId;
        $groupId = (int)$groupId;
        if ($fromUserId <= 0 || $groupId <= 0) {
            throw new \InvalidArgumentException('invalid group chat');
        }
        list($content, $msgType, $extra) = $this->prepareOutgoing($content, $msgType, $extra);
        return $this->insertMessage([
            'conversation_type' => 2,
            'conversation_id'   => (string)$groupId,
            'group_id'          => $groupId,
            'from_user_id'      => $fromUserId,
            'to_user_id'        => 0,
            'msg_type'          => (int)$msgType,
            'content'           => $content,
            'extra'             => $extra,
        ]);
    }

    public function sendGroup($fromUserId, $groupId, $content, $msgType = 1, $extra = null)
    {
        $fromUserId = (int)$fromUserId;
        $groupId = (int)$groupId;
        if ($fromUserId <= 0 || $groupId <= 0) {
            throw new \InvalidArgumentException('invalid group chat');
        }
        list($content, $msgType, $extra) = $this->prepareOutgoing($content, $msgType, $extra);
        $msgType = (int)$msgType;
        // msg_type=2 红包：权限已在 RedPacketService::assertCanSendGroupRedPacket 校验；
        // 禁止发言(text)时仍须能发红包，不可再走 assertCanSpeak→text
        if ($msgType !== 2) {
            $mode = GroupService::msgTypeToForbidMode($msgType);
            (new GroupService())->assertCanSpeak($groupId, $fromUserId, $mode);
        }
        return $this->insertMessage([
            'conversation_type' => 2,
            'conversation_id'   => (string)$groupId,
            'group_id'          => $groupId,
            'from_user_id'      => $fromUserId,
            'to_user_id'        => 0,
            'msg_type'          => $msgType,
            'content'           => $content,
            'extra'             => $extra,
        ]);
    }

    /**
     * 群内系统灰字提示（不校验发言权限）
     */
    public function sendGroupSystem($groupId, $content, $fromUserId = 0, $extra = null)
    {
        $groupId = (int)$groupId;
        $content = trim((string)$content);
        if ($groupId <= 0 || $content === '') {
            throw new \InvalidArgumentException('invalid system message');
        }
        return $this->insertMessage([
            'conversation_type' => 2,
            'conversation_id'   => (string)$groupId,
            'group_id'          => $groupId,
            'from_user_id'      => (int)$fromUserId,
            'to_user_id'        => 0,
            'msg_type'          => 3,
            'content'           => mb_substr($content, 0, 500),
            'extra'             => $extra,
        ]);
    }

    protected function insertMessage(array $row)
    {
        $msgId = IdGenerator::msgId();
        $now = time();
        $extraJson = null;
        if ($row['extra'] !== null) {
            $extraJson = is_string($row['extra']) ? $row['extra'] : json_encode($row['extra'], JSON_UNESCAPED_UNICODE);
        }
        $sql = 'INSERT INTO ' . Db::table('chat_messages')
            . ' (msg_id,conversation_type,conversation_id,group_id,from_user_id,to_user_id,msg_type,content,extra,status,createtime)'
            . ' VALUES (?,?,?,?,?,?,?,?,?,1,?)';
        Db::exec($sql, [
            $msgId,
            (int)$row['conversation_type'],
            (string)$row['conversation_id'],
            (int)$row['group_id'],
            (int)$row['from_user_id'],
            (int)$row['to_user_id'],
            (int)$row['msg_type'],
            (string)$row['content'],
            $extraJson,
            $now,
        ]);
        $id = Db::lastId();
        $payload = [
            'id'                => $id,
            'msg_id'            => $msgId,
            'conversation_type' => (int)$row['conversation_type'],
            'conversation_id'   => (string)$row['conversation_id'],
            'group_id'          => (int)$row['group_id'],
            'from_user_id'      => (int)$row['from_user_id'],
            'to_user_id'        => (int)$row['to_user_id'],
            'msg_type'          => (int)$row['msg_type'],
            'content'           => (string)$row['content'],
            'extra'             => $row['extra'],
            'status'            => 1,
            'createtime'        => $now,
        ];
        $this->cacheRecent($payload);
        $this->touchInbox($payload);
        $this->bumpUnreadCounters($payload);
        // 推送/ACK 附带发送者昵称，避免客户端在隐私群无成员列表时显示 ID
        return $this->attachSenderFields($payload);
    }

    /**
     * 批量为历史消息附带 from_nickname / from_avatar / from_user
     */
    public function enrichMessagesWithSenders(array $list)
    {
        if (!$list) {
            return $list;
        }
        $ids = [];
        foreach ($list as $m) {
            $fid = (int)($m['from_user_id'] ?? 0);
            if ($fid > 0) {
                $ids[] = $fid;
            }
        }
        if (!$ids) {
            return $list;
        }
        $auth = new AuthService([]);
        $map = $auth->usersBriefMap($ids);
        foreach ($list as &$m) {
            $m = $this->attachSenderFields($m, $map, $auth);
        }
        unset($m);
        return $list;
    }

    /**
     * @param array           $msg
     * @param array|null      $map  预取的 usersBriefMap；null 则单条查询
     * @param AuthService|null $auth
     */
    public function attachSenderFields(array $msg, $map = null, $auth = null)
    {
        $fid = (int)($msg['from_user_id'] ?? 0);
        if ($fid <= 0) {
            return $msg;
        }
        if ($auth === null) {
            $auth = new AuthService([]);
        }
        if ($map === null) {
            $map = $auth->usersBriefMap([$fid]);
        }
        $u = $map[$fid] ?? null;
        $nick = $auth->displayNameFromBrief($u, $fid);
        $avatar = is_array($u) ? (string)($u['avatar'] ?? '') : '';
        $msg['from_nickname'] = $nick;
        $msg['from_avatar'] = $avatar;
        $msg['from_user'] = [
            'id'       => $fid,
            'user_id'  => $fid,
            'nickname' => $nick,
            'avatar'   => $avatar,
        ];
        return $msg;
    }

    protected function cacheRecent(array $payload)
    {
        try {
            $key = RedisClient::key('conv:' . $payload['conversation_type'] . ':' . $payload['conversation_id'] . ':recent');
            $r = RedisClient::conn();
            $r->lPush($key, json_encode($payload, JSON_UNESCAPED_UNICODE));
            $r->lTrim($key, 0, 99);
            $r->expire($key, 86400 * 7);
        } catch (\Throwable $e) {
        }
    }

    /**
     * Redis 未读计数：收件人 INCR；已读时清零。列表优先读计数，避免 50× COUNT
     * 群聊：只给在线成员 INCR（全员扇出在万人群会打爆 Redis）；离线用户靠游标/SQL 回退
     */
    protected function bumpUnreadCounters(array $payload)
    {
        $type = (int)($payload['conversation_type'] ?? 0);
        $cid = (string)($payload['conversation_id'] ?? '');
        $from = (int)($payload['from_user_id'] ?? 0);
        if (($type !== 1 && $type !== 2) || $cid === '' || $from <= 0) {
            return;
        }
        $uids = [];
        if ($type === 1) {
            $to = (int)($payload['to_user_id'] ?? 0);
            if ($to > 0 && $to !== $from) {
                $uids[] = $to;
            }
        } else {
            try {
                $uids = (new GroupService())->onlineMemberIds((int)($payload['group_id'] ?? $cid));
                $uids = array_values(array_filter($uids, function ($uid) use ($from) {
                    return (int)$uid !== $from;
                }));
            } catch (\Throwable $e) {
            }
        }
        if (!$uids) {
            return;
        }
        try {
            $r = RedisClient::conn();
            $r->multi(\Redis::PIPELINE);
            foreach ($uids as $uid) {
                $key = RedisClient::key('unread:' . (int)$uid . ':' . $type . ':' . $cid);
                $r->incr($key);
                $r->expire($key, 86400 * 30);
            }
            $r->exec();
        } catch (\Throwable $e) {
            try {
                $r = RedisClient::conn();
                foreach ($uids as $uid) {
                    $key = RedisClient::key('unread:' . (int)$uid . ':' . $type . ':' . $cid);
                    $r->incr($key);
                    $r->expire($key, 86400 * 30);
                }
            } catch (\Throwable $e2) {
            }
        }
    }

    protected function clearUnreadCounter($userId, $conversationType, $conversationId)
    {
        try {
            RedisClient::conn()->del(
                RedisClient::key('unread:' . (int)$userId . ':' . (int)$conversationType . ':' . (string)$conversationId)
            );
        } catch (\Throwable $e) {
        }
    }

    /** @return array<string,int>|null null=redis 不可用 */
    protected function unreadFromRedis($userId, array $targets)
    {
        try {
            $r = RedisClient::conn();
            $out = [];
            $miss = 0;
            $keys = [];
            $order = [];
            foreach ($targets as $key => $t) {
                $keys[] = RedisClient::key('unread:' . (int)$userId . ':' . (int)$t['type'] . ':' . (string)$t['id']);
                $order[] = $key;
            }
            if (!$keys) {
                return $out;
            }
            // 一次 MGET，避免 N 次 RTT
            $vals = $r->mGet($keys);
            if (!is_array($vals)) {
                return null;
            }
            foreach ($order as $i => $key) {
                $raw = $vals[$i] ?? false;
                if ($raw === false || $raw === null) {
                    $miss++;
                    $out[$key] = -1;
                } else {
                    $out[$key] = max(0, (int)$raw);
                }
            }
            if ($miss === count($targets)) {
                return null;
            }
            return $out;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** 列表预览用：去掉超长 content / 无用 extra */
    protected function slimLastMessage($msg)
    {
        if (!$msg || !is_array($msg)) {
            return null;
        }
        $content = (string)($msg['content'] ?? '');
        if (function_exists('mb_strlen') && mb_strlen($content) > 80) {
            $msg['content'] = mb_substr($content, 0, 80) . '…';
        } elseif (strlen($content) > 80) {
            $msg['content'] = substr($content, 0, 80) . '…';
        }
        $msgType = (int)($msg['msg_type'] ?? 1);
        if (isset($msg['extra']) && is_array($msg['extra'])) {
            if ($msgType === 2) {
                // 红包预览只需 blessing/packet_id 等少量字段
                $keep = [];
                foreach (['packet_id', 'blessing', 'packet_type', 'total_amount', 'remain_count', 'status'] as $k) {
                    if (array_key_exists($k, $msg['extra'])) {
                        $keep[$k] = $msg['extra'][$k];
                    }
                }
                $msg['extra'] = $keep ?: null;
            } elseif ($msgType === 8) {
                $keep = [];
                foreach (['transfer_no', 'amount', 'remark', 'status'] as $k) {
                    if (array_key_exists($k, $msg['extra'])) {
                        $keep[$k] = $msg['extra'][$k];
                    }
                }
                $msg['extra'] = $keep ?: null;
            } elseif ($msgType === 7) {
                $msg['extra'] = ['name' => (string)($msg['extra']['name'] ?? '')];
            } elseif ($msgType === 6) {
                $msg['extra'] = ['code' => (string)($msg['extra']['code'] ?? '')];
            } else {
                unset($msg['extra']);
            }
        }
        return $msg;
    }

    protected function invalidateConvListCache($userId)
    {
        try {
            $uid = (int)$userId;
            RedisClient::conn()->del(
                RedisClient::key('convlist:' . $uid . ':50'),
                RedisClient::key('convlist:' . $uid . ':100'),
                RedisClient::key('convlist:' . $uid)
            );
        } catch (\Throwable $e) {
        }
    }

    /**
     * 维护每人最近会话 ZSET（score=消息 id），列表 O(logN) 取 Top，避免消息表 GROUP BY 越扫越慢
     * 群聊：只更新发送者 + 在线成员 inbox（全员写 inbox 在万人群不可扩展）
     * 另写 g:{gid}:last 供列表侧补活跃群
     */
    protected function touchInbox(array $payload)
    {
        $type = (int)($payload['conversation_type'] ?? 0);
        $cid = (string)($payload['conversation_id'] ?? '');
        $msgId = (int)($payload['id'] ?? 0);
        if (($type !== 1 && $type !== 2) || $cid === '' || $msgId <= 0) {
            return;
        }
        $member = $type . ':' . $cid;
        $uids = [];
        if ($type === 1) {
            $uids = [(int)$payload['from_user_id'], (int)$payload['to_user_id']];
        } else {
            $from = (int)($payload['from_user_id'] ?? 0);
            $uids = $from > 0 ? [$from] : [];
            try {
                $gid = (int)($payload['group_id'] ?? $cid);
                $online = (new GroupService())->onlineMemberIds($gid);
                foreach ($online as $uid) {
                    $uids[] = (int)$uid;
                }
                // 群维度最后一条：O(1)，离线用户回列表时可并入
                try {
                    RedisClient::conn()->setex(
                        RedisClient::key('g:' . $gid . ':last'),
                        86400 * 30,
                        json_encode([
                            'id'         => $msgId,
                            'createtime' => (int)($payload['createtime'] ?? time()),
                            'from'       => $from,
                        ], JSON_UNESCAPED_UNICODE)
                    );
                } catch (\Throwable $eLast) {
                }
            } catch (\Throwable $e) {
            }
            $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
        }
        try {
            $r = RedisClient::conn();
            $r->multi(\Redis::PIPELINE);
            foreach ($uids as $uid) {
                if ($uid <= 0) {
                    continue;
                }
                $key = RedisClient::key('inbox:' . $uid);
                $r->zAdd($key, $msgId, $member);
                // 只保留最近 200 个会话键
                $r->zRemRangeByRank($key, 0, -201);
                $r->expire($key, 86400 * 30);
            }
            $r->exec();
            // 列表短缓存靠 TTL 自然过期，避免群消息对上千在线用户逐个 DEL
        } catch (\Throwable $e) {
        }
        // 私聊有新消息：恢复双方「已删除」隐藏，会话重新出现在列表
        if ($type === 1) {
            foreach ($uids as $uid) {
                if ((int)$uid > 0) {
                    $this->restoreHiddenPrivateConversation((int)$uid, $cid);
                }
            }
        }
    }

    /** @return array<int,array{id:int,createtime:int}> */
    protected function groupLastMap(array $groupIds)
    {
        $out = [];
        $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds))));
        if (!$groupIds) {
            return $out;
        }
        try {
            $r = RedisClient::conn();
            $keys = [];
            foreach ($groupIds as $gid) {
                $keys[] = RedisClient::key('g:' . $gid . ':last');
            }
            $vals = $r->mGet($keys);
            if (!is_array($vals)) {
                return $out;
            }
            foreach ($groupIds as $i => $gid) {
                $raw = $vals[$i] ?? false;
                if ($raw === false || $raw === null || $raw === '') {
                    continue;
                }
                $j = json_decode((string)$raw, true);
                if (!is_array($j) || empty($j['id'])) {
                    continue;
                }
                $out[$gid] = [
                    'id'         => (int)$j['id'],
                    'createtime' => (int)($j['createtime'] ?? 0),
                ];
            }
        } catch (\Throwable $e) {
        }
        return $out;
    }

    /** @return array<string,int> member => last_msg_id */
    protected function inboxMap($userId, $limit = 80)
    {
        $userId = (int)$userId;
        $limit = max(1, min(200, (int)$limit));
        try {
            $rows = RedisClient::conn()->zRevRange(
                RedisClient::key('inbox:' . $userId),
                0,
                $limit - 1,
                true
            );
            if (!is_array($rows) || !$rows) {
                return [];
            }
            $out = [];
            foreach ($rows as $member => $score) {
                $member = (string)$member;
                if ($member === '' || strpos($member, ':') === false) {
                    continue;
                }
                $out[$member] = (int)$score;
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function cachedMyGroups($userId)
    {
        $userId = (int)$userId;
        $cacheKey = RedisClient::key('uid:' . $userId . ':my_groups');
        try {
            $raw = RedisClient::conn()->get($cacheKey);
            if ($raw) {
                $j = json_decode((string)$raw, true);
                if (is_array($j)) {
                    return $j;
                }
            }
        } catch (\Throwable $e) {
        }
        $groups = Db::fetchAll(
            'SELECT g.* FROM ' . Db::table('chat_groups') . ' g'
            . ' INNER JOIN ' . Db::table('chat_group_members') . ' m ON m.group_id=g.id'
            . ' WHERE m.user_id=? AND m.status=1 AND g.status IN (1,3)',
            [$userId]
        );
        try {
            RedisClient::conn()->setex($cacheKey, 60, json_encode($groups, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
        }
        return $groups;
    }

    /** 入群/退群后清缓存 */
    public function invalidateMyGroupsCache($userId)
    {
        try {
            RedisClient::conn()->del(RedisClient::key('uid:' . (int)$userId . ':my_groups'));
        } catch (\Throwable $e) {
        }
    }

    protected function seedInboxFromItems($userId, array $items)
    {
        $userId = (int)$userId;
        if ($userId <= 0 || !$items) {
            return;
        }
        try {
            $r = RedisClient::conn();
            $key = RedisClient::key('inbox:' . $userId);
            foreach ($items as $it) {
                $type = (int)($it['conversation_type'] ?? 0);
                $cid = (string)($it['conversation_id'] ?? '');
                $lastId = (int)(($it['last_message']['id'] ?? 0));
                if (($type !== 1 && $type !== 2) || $cid === '' || $lastId <= 0) {
                    continue;
                }
                $r->zAdd($key, $lastId, $type . ':' . $cid);
            }
            $r->zRemRangeByRank($key, 0, -201);
            $r->expire($key, 86400 * 30);
        } catch (\Throwable $e) {
        }
    }

    /**
     * inbox 里只有群时，私聊不会出现在列表；把库里仍有消息的私聊补回来并回填 inbox。
     */
    protected function mergeMissingPrivateConversations($userId, array &$items, $limit = 50)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return;
        }
        $have = [];
        foreach ($items as $it) {
            if ((int)($it['conversation_type'] ?? 0) === 1) {
                $have[(string)$it['conversation_id']] = true;
            }
        }
        $msgTable = Db::table('chat_messages');
        $limit = max(1, min(100, (int)$limit));
        try {
            $privates = Db::fetchAll(
                "SELECT m.* FROM {$msgTable} m
                 INNER JOIN (
                    SELECT conversation_id, MAX(id) AS max_id FROM (
                        SELECT conversation_id, id FROM {$msgTable}
                        WHERE conversation_type=1 AND status=1 AND from_user_id=?
                        UNION ALL
                        SELECT conversation_id, id FROM {$msgTable}
                        WHERE conversation_type=1 AND status=1 AND to_user_id=?
                    ) u
                    GROUP BY conversation_id
                 ) t ON m.id = t.max_id
                 ORDER BY m.id DESC LIMIT {$limit}",
                [$userId, $userId]
            );
        } catch (\Throwable $e) {
            return;
        }
        $hidden = $this->hiddenPrivateCidMap($userId);
        $added = [];
        foreach ($privates as $row) {
            $cid = (string)($row['conversation_id'] ?? '');
            if ($cid === '' || isset($have[$cid]) || isset($hidden[$cid])) {
                continue;
            }
            $m = $this->slimLastMessage($this->normalizeMessage($row));
            $peerId = ((int)$m['from_user_id'] === $userId)
                ? (int)$m['to_user_id']
                : (int)$m['from_user_id'];
            $it = [
                'conversation_type' => 1,
                'conversation_id'   => $cid,
                'peer_user_id'      => $peerId,
                'group_id'          => 0,
                'title'             => '',
                'avatar'            => '',
                'last_message'      => $m,
                'updatetime'        => (int)$m['createtime'],
                'unread_count'      => 0,
            ];
            $items[] = $it;
            $added[] = $it;
            $have[$cid] = true;
        }
        if ($added) {
            $this->seedInboxFromItems($userId, $added);
        }
    }

    public function history($conversationType, $conversationId, $beforeId = 0, $limit = 30, $userId = 0)
    {
        $limit = max(1, min(100, (int)$limit));
        $conversationType = (int)$conversationType;
        $conversationId = (string)$conversationId;
        $beforeId = (int)$beforeId;
        $userId = (int)$userId;
        $minId = 0;
        if ($conversationType === 2 && $userId > 0) {
            $minId = $this->groupClearedMsgId($userId, (int)$conversationId);
        }

        // 首屏：优先 Redis recent（写入时已 LPUSH），避免每次打开会话扫表
        // 有软删水位时跳过共享缓存，直接查库，避免漏补较早但仍可见的消息
        if ($beforeId <= 0 && $minId <= 0) {
            try {
                $key = RedisClient::key('conv:' . $conversationType . ':' . $conversationId . ':recent');
                $r = RedisClient::conn();
                $llen = (int)$r->lLen($key);
                $need = $limit;
                // 缓存条数够，或标记为「已完整」且条数>0 → 直接用 Redis
                $full = $r->get(RedisClient::key('conv:' . $conversationType . ':' . $conversationId . ':recent_full'));
                if ($llen > 0 && ($llen >= $need || $full === '1')) {
                    $rawList = $r->lRange($key, 0, $need - 1);
                    $rows = [];
                    foreach ($rawList ?: [] as $raw) {
                        $j = json_decode((string)$raw, true);
                        if (is_array($j) && !empty($j['id'])) {
                            $rows[] = $this->normalizeMessage($j);
                        }
                    }
                    if ($rows) {
                        return array_reverse($rows);
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        $sql = 'SELECT * FROM ' . Db::table('chat_messages')
            . ' WHERE conversation_type=? AND conversation_id=? AND status IN (1,2)';
        $bind = [$conversationType, $conversationId];
        if ($minId > 0) {
            $sql .= ' AND id > ?';
            $bind[] = $minId;
        }
        if ($beforeId > 0) {
            $sql .= ' AND id < ?';
            $bind[] = $beforeId;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . $limit;
        $rows = Db::fetchAll($sql, $bind);
        $list = array_map([$this, 'normalizeMessage'], array_reverse($rows));
        // 回填 recent，供下次秒开（LPUSH 后左侧为最新）；有水位时不写共享缓存
        if ($beforeId <= 0 && $minId <= 0 && $list) {
            try {
                $key = RedisClient::key('conv:' . $conversationType . ':' . $conversationId . ':recent');
                $fullKey = RedisClient::key('conv:' . $conversationType . ':' . $conversationId . ':recent_full');
                $r = RedisClient::conn();
                $r->del($key);
                foreach ($list as $m) {
                    $r->lPush($key, json_encode($m, JSON_UNESCAPED_UNICODE));
                }
                $r->lTrim($key, 0, 99);
                $r->expire($key, 86400 * 7);
                // DB 返回不足 limit → 说明会话消息已全部在缓存
                if (count($list) < $limit) {
                    $r->setex($fullKey, 86400 * 7, '1');
                } else {
                    $r->del($fullKey);
                }
            } catch (\Throwable $e) {
            }
        }
        return $list;
    }

    /**
     * 撤回消息（status=2）
     * 本人 2 分钟内可撤；IM 管理员可撤任意消息
     */
    public function recall($messageId, $operatorId)
    {
        $messageId = (int)$messageId;
        $operatorId = (int)$operatorId;
        $row = Db::fetch('SELECT * FROM ' . Db::table('chat_messages') . ' WHERE id=? LIMIT 1', [$messageId]);
        if (!$row) {
            throw new \RuntimeException('message not found');
        }
        if ((int)$row['status'] === 2) {
            return $this->normalizeMessage($row);
        }
        if ((int)$row['status'] !== 1) {
            throw new \RuntimeException('cannot recall');
        }
        $isOwner = (int)$row['from_user_id'] === $operatorId;
        $isAdmin = AdminService::isImAdmin($operatorId);
        if (!$isOwner && !$isAdmin) {
            throw new \RuntimeException('no permission');
        }
        // 私聊：本人可随时删除（前端展示为「删除」）；群聊本人仍限 2 分钟撤回
        $isPrivate = (int)($row['conversation_type'] ?? 0) === 1;
        if ($isOwner && !$isAdmin && !$isPrivate) {
            $age = time() - (int)$row['createtime'];
            if ($age > 120) {
                throw new \RuntimeException('recall expired');
            }
        }
        Db::exec(
            'UPDATE ' . Db::table('chat_messages') . ' SET status=2 WHERE id=? AND status=1',
            [$messageId]
        );
        $row['status'] = 2;
        $row['content'] = $isPrivate ? '[已删除]' : '[已撤回]';
        $normalized = $this->normalizeMessage($row);
        $this->cacheRecent($normalized);
        return $normalized;
    }

    /**
     * 会话列表：优先 Redis inbox（按最近消息 id），冷启动再回退 SQL 并回填
     */
    public function listConversations($userId, $limit = 50)
    {
        $userId = (int)$userId;
        $limit = max(1, min(100, (int)$limit));
        $msgTable = Db::table('chat_messages');
        $items = [];
        $inbox = $this->inboxMap($userId, $limit + 20);

        if ($inbox) {
            $msgIds = array_values(array_unique(array_filter(array_map('intval', array_values($inbox)))));
            $msgById = [];
            if ($msgIds) {
                $in = implode(',', array_fill(0, count($msgIds), '?'));
                // 列表只要预览字段，避免 SELECT * 拉超长 content/extra
                $rows = Db::fetchAll(
                    "SELECT id,msg_id,conversation_type,conversation_id,group_id,from_user_id,to_user_id,"
                    . "msg_type,content,extra,status,createtime FROM {$msgTable}"
                    . " WHERE id IN ({$in}) AND status=1",
                    $msgIds
                );
                foreach ($rows as $row) {
                    $msgById[(int)$row['id']] = $this->slimLastMessage($this->normalizeMessage($row));
                }
            }

            $groupIdsNeeded = [];
            foreach ($inbox as $member => $lastId) {
                $parts = explode(':', $member, 2);
                if (count($parts) !== 2) {
                    continue;
                }
                $ctype = (int)$parts[0];
                $cid = (string)$parts[1];
                $m = $msgById[(int)$lastId] ?? null;
                if ($ctype === 1) {
                    $peerId = 0;
                    if ($m) {
                        $peerId = ((int)$m['from_user_id'] === $userId)
                            ? (int)$m['to_user_id']
                            : (int)$m['from_user_id'];
                    } else {
                        $bits = explode('_', $cid);
                        if (count($bits) === 2) {
                            $a = (int)$bits[0];
                            $b = (int)$bits[1];
                            $peerId = ($a === $userId) ? $b : $a;
                        }
                    }
                    $items[] = [
                        'conversation_type' => 1,
                        'conversation_id'   => $cid,
                        'peer_user_id'      => $peerId,
                        'group_id'          => 0,
                        'title'             => '',
                        'avatar'            => '',
                        'last_message'      => $m,
                        'updatetime'        => $m ? (int)$m['createtime'] : (int)$lastId,
                        'unread_count'      => 0,
                    ];
                } elseif ($ctype === 2) {
                    $gid = (int)$cid;
                    if ($gid > 0) {
                        $groupIdsNeeded[$gid] = true;
                        $items[] = [
                            'conversation_type' => 2,
                            'conversation_id'   => (string)$gid,
                            'peer_user_id'      => 0,
                            'group_id'          => $gid,
                            'title'             => '',
                            'avatar'            => '',
                            'last_message'      => $m,
                            'updatetime'        => $m ? (int)$m['createtime'] : (int)$lastId,
                            'unread_count'      => 0,
                            '_last_msg_id'      => (int)$lastId,
                        ];
                    }
                }
            }

            // 仅展示我仍在的群：inbox 残留（退群后）不再补群名，直接剔除并清 inbox
            $groups = $this->cachedMyGroups($userId);
            $haveG = [];
            $metaById = [];
            foreach ($groups as $g) {
                $metaById[(int)$g['id']] = $g;
            }
            $ghostMembers = [];
            $keptItems = [];
            foreach ($items as $it) {
                if ((int)($it['conversation_type'] ?? 0) !== 2) {
                    $keptItems[] = $it;
                    continue;
                }
                $gid = (int)($it['group_id'] ?? 0);
                if ($gid <= 0 || !isset($metaById[$gid])) {
                    if ($gid > 0) {
                        $ghostMembers[] = '2:' . $gid;
                    }
                    continue;
                }
                $g = $metaById[$gid];
                $it['title'] = (string)($g['name'] ?? '');
                $it['avatar'] = (string)($g['avatar'] ?? '');
                if (empty($it['last_message'])) {
                    $it['updatetime'] = (int)($g['updatetime'] ?: $g['createtime']);
                }
                $haveG[$gid] = true;
                $keptItems[] = $it;
            }
            $items = $keptItems;
            if ($ghostMembers) {
                try {
                    $r = RedisClient::conn();
                    $ikey = RedisClient::key('inbox:' . $userId);
                    foreach (array_unique($ghostMembers) as $member) {
                        $r->zRem($ikey, $member);
                        $r->zRem(RedisClient::key('pins:' . $userId), $member);
                    }
                } catch (\Throwable $e) {
                }
            }
            unset($groupIdsNeeded);
            // 不再把「无消息的空群」塞进主会话列表（万人群会把列表撑爆）
            // 离线期间错过 inbox 扇出的活跃群：用 g:{id}:last 补进列表并回填 inbox
            $missGids = [];
            foreach ($groups as $g) {
                $gid = (int)$g['id'];
                if ($gid > 0 && !isset($haveG[$gid])) {
                    $missGids[] = $gid;
                }
            }
            if ($missGids) {
                $lastMap = $this->groupLastMap($missGids);
                $extraMsgIds = [];
                foreach ($missGids as $gid) {
                    $lastId = (int)($lastMap[$gid]['id'] ?? 0);
                    if ($lastId <= 0) {
                        continue;
                    }
                    $extraMsgIds[$lastId] = true;
                    $haveG[$gid] = true;
                    $g = $metaById[$gid] ?? null;
                    $items[] = [
                        'conversation_type' => 2,
                        'conversation_id'   => (string)$gid,
                        'peer_user_id'      => 0,
                        'group_id'          => $gid,
                        'title'             => $g ? (string)($g['name'] ?? '') : '',
                        'avatar'            => $g ? (string)($g['avatar'] ?? '') : '',
                        'last_message'      => null,
                        'updatetime'        => (int)($lastMap[$gid]['createtime'] ?? 0),
                        'unread_count'      => 0,
                        '_last_msg_id'      => $lastId,
                    ];
                }
                $extraIds = array_keys($extraMsgIds);
                if ($extraIds) {
                    $in = implode(',', array_fill(0, count($extraIds), '?'));
                    $rows = Db::fetchAll(
                        "SELECT id,msg_id,conversation_type,conversation_id,group_id,from_user_id,to_user_id,"
                        . "msg_type,content,extra,status,createtime FROM {$msgTable}"
                        . " WHERE id IN ({$in}) AND status=1",
                        $extraIds
                    );
                    $byId = [];
                    foreach ($rows as $row) {
                        $byId[(int)$row['id']] = $this->slimLastMessage($this->normalizeMessage($row));
                    }
                    foreach ($items as &$it2) {
                        if (empty($it2['_last_msg_id'])) {
                            continue;
                        }
                        $mid = (int)$it2['_last_msg_id'];
                        if (isset($byId[$mid])) {
                            $it2['last_message'] = $byId[$mid];
                            $it2['updatetime'] = (int)$byId[$mid]['createtime'];
                        }
                        unset($it2['_last_msg_id']);
                    }
                    unset($it2);
                }
                $this->seedInboxFromItems($userId, array_filter($items, function ($it) {
                    return (int)($it['conversation_type'] ?? 0) === 2 && !empty($it['last_message']);
                }));
            }
        } else {
            // 冷启动：UNION 替代 OR，减轻私聊聚合扫描；结果回填 inbox
            $privates = Db::fetchAll(
                "SELECT m.* FROM {$msgTable} m
                 INNER JOIN (
                    SELECT conversation_id, MAX(id) AS max_id FROM (
                        SELECT conversation_id, id FROM {$msgTable}
                        WHERE conversation_type=1 AND status=1 AND from_user_id=?
                        UNION ALL
                        SELECT conversation_id, id FROM {$msgTable}
                        WHERE conversation_type=1 AND status=1 AND to_user_id=?
                    ) u
                    GROUP BY conversation_id
                 ) t ON m.id = t.max_id
                 ORDER BY m.id DESC LIMIT {$limit}",
                [$userId, $userId]
            );
            foreach ($privates as $m) {
                $m = $this->slimLastMessage($this->normalizeMessage($m));
                $peerId = ((int)$m['from_user_id'] === $userId)
                    ? (int)$m['to_user_id']
                    : (int)$m['from_user_id'];
                $items[] = [
                    'conversation_type' => 1,
                    'conversation_id'   => (string)$m['conversation_id'],
                    'peer_user_id'      => $peerId,
                    'group_id'          => 0,
                    'title'             => '',
                    'avatar'            => '',
                    'last_message'      => $m,
                    'updatetime'        => (int)$m['createtime'],
                    'unread_count'      => 0,
                ];
            }

            $groups = $this->cachedMyGroups($userId);
            $groupIds = [];
            foreach ($groups as $g) {
                $groupIds[] = (string)((int)$g['id']);
            }
            $lastByGroup = [];
            if ($groupIds) {
                $in = implode(',', array_fill(0, count($groupIds), '?'));
                $lastRows = Db::fetchAll(
                    "SELECT m.* FROM {$msgTable} m
                     INNER JOIN (
                        SELECT conversation_id, MAX(id) AS max_id
                        FROM {$msgTable}
                        WHERE conversation_type=2 AND status=1 AND conversation_id IN ({$in})
                        GROUP BY conversation_id
                     ) t ON m.id = t.max_id",
                    $groupIds
                );
                foreach ($lastRows as $row) {
                    $lastByGroup[(string)$row['conversation_id']] = $this->slimLastMessage($this->normalizeMessage($row));
                }
            }
            foreach ($groups as $g) {
                $gid = (int)$g['id'];
                $convId = (string)$gid;
                $last = $lastByGroup[$convId] ?? null;
                $items[] = [
                    'conversation_type' => 2,
                    'conversation_id'   => $convId,
                    'peer_user_id'      => 0,
                    'group_id'          => $gid,
                    'title'             => (string)($g['name'] ?? ''),
                    'avatar'            => (string)($g['avatar'] ?? ''),
                    'last_message'      => $last,
                    'updatetime'        => $last
                        ? (int)$last['createtime']
                        : (int)($g['updatetime'] ?: $g['createtime']),
                    'unread_count'      => 0,
                ];
            }
            $this->filterHiddenPrivateConversations($userId, $items);
            $this->filterClearedGroupConversations($userId, $items);
            $this->seedInboxFromItems($userId, $items);
        }

        // inbox 非空时只会走 Redis 会话；私聊若不在 inbox 会被丢掉（例如只有群在 inbox）
        $this->mergeMissingPrivateConversations($userId, $items, $limit);

        usort($items, function ($a, $b) {
            return ((int)$b['updatetime']) <=> ((int)$a['updatetime']);
        });

        // 普通用户：会话列表仅固定出现默认客服 88888888（其它托管账号不自动出现）
        if (!AdminService::isImAdmin($userId)) {
            $havePeers = [];
            foreach ($items as $idx => $it) {
                if ((int)$it['conversation_type'] === 1 && (int)$it['peer_user_id'] > 0) {
                    $havePeers[(int)$it['peer_user_id']] = $idx;
                }
            }
            $csId = AdminService::defaultCsUserId();
            $adminRows = AdminService::adminRows();
            $csMeta = $adminRows[$csId] ?? ['user_id' => $csId, 'label' => '红宝客服'];
            if ($csId > 0 && $csId !== $userId) {
                if (isset($havePeers[$csId])) {
                    $idx = $havePeers[$csId];
                    $items[$idx]['is_im_admin'] = true;
                    $items[$idx]['is_default_cs'] = true;
                    $items[$idx]['pinned'] = true;
                    $items[$idx]['undeletable'] = true;
                    if ($items[$idx]['title'] === '' && !empty($csMeta['label'])) {
                        $items[$idx]['title'] = (string)$csMeta['label'];
                    }
                } else {
                    $items[] = [
                        'conversation_type' => 1,
                        'conversation_id'   => IdGenerator::privateConversationId($userId, $csId),
                        'peer_user_id'      => $csId,
                        'group_id'          => 0,
                        'title'             => (string)($csMeta['label'] ?? '红宝客服'),
                        'avatar'            => '',
                        'last_message'      => null,
                        'is_im_admin'       => true,
                        'is_default_cs'     => true,
                        'pinned'            => true,
                        'undeletable'       => true,
                        'updatetime'        => 0,
                        'unread_count'      => 0,
                    ];
                }
            }
        }

        // 用户主动删除的私聊：从列表隐藏（新消息会 restore）
        $this->filterHiddenPrivateConversations($userId, $items);
        // 用户删除的群聊：水位软删，列表仅保留 cleared_msg_id 之后的新消息
        $this->filterClearedGroupConversations($userId, $items);

        $this->applyPinnedFlags($userId, $items);
        foreach ($items as &$it) {
            if ((int)($it['conversation_type'] ?? 0) === 1 && AdminService::isDefaultCs((int)($it['peer_user_id'] ?? 0))) {
                $it['pinned'] = true;
                $it['is_default_cs'] = true;
                $it['undeletable'] = true;
                if (empty($it['title'])) {
                    $it['title'] = '红宝客服';
                }
            }
        }
        unset($it);
        usort($items, function ($a, $b) {
            $ap = !empty($a['pinned']) ? 1 : 0;
            $bp = !empty($b['pinned']) ? 1 : 0;
            if ($ap !== $bp) {
                return $bp <=> $ap;
            }
            return ((int)$b['updatetime']) <=> ((int)$a['updatetime']);
        });

        $items = array_slice($items, 0, $limit);
        $unreadMap = $this->batchUnreadCounts($userId, $items);
        foreach ($items as &$it) {
            $key = ((int)$it['conversation_type']) . ':' . (string)$it['conversation_id'];
            $it['unread_count'] = (int)($unreadMap[$key] ?? 0);
            unset($it['_last_msg_id']);
        }
        unset($it);

        return $items;
    }

    /** @return array<string,int> key => pin_score */
    protected function pinnedKeyMap($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return [];
        }
        try {
            $rows = RedisClient::conn()->zRevRange(
                RedisClient::key('pins:' . $userId),
                0,
                49,
                true
            );
            if (!is_array($rows) || !$rows) {
                return [];
            }
            $out = [];
            foreach ($rows as $member => $score) {
                $member = (string)$member;
                if ($member === '' || strpos($member, ':') === false) {
                    continue;
                }
                $out[$member] = (int)$score;
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function applyPinnedFlags($userId, array &$items)
    {
        $pins = $this->pinnedKeyMap($userId);
        foreach ($items as &$it) {
            $key = ((int)($it['conversation_type'] ?? 0)) . ':' . (string)($it['conversation_id'] ?? '');
            $it['pinned'] = isset($pins[$key]);
            if ($it['pinned']) {
                $it['pin_time'] = (int)$pins[$key];
            }
        }
        unset($it);
    }

    public function pinConversation($userId, $conversationType, $conversationId, $pinned = true)
    {
        $userId = (int)$userId;
        $ctype = (int)$conversationType;
        $cid = (string)$conversationId;
        if ($userId <= 0 || ($ctype !== 1 && $ctype !== 2) || $cid === '') {
            throw new \InvalidArgumentException('invalid conversation');
        }
        if ($ctype === 2) {
            $cid = (string)((int)$cid);
            if ((int)$cid <= 0) {
                throw new \InvalidArgumentException('invalid group');
            }
            if (!(new GroupService())->isMember((int)$cid, $userId)) {
                throw new \RuntimeException('not in group');
            }
        } else {
            // 私聊：允许客服会话即使尚无消息
            $bits = explode('_', $cid);
            if (count($bits) !== 2) {
                throw new \InvalidArgumentException('invalid conversation');
            }
            $a = (int)$bits[0];
            $b = (int)$bits[1];
            if ($userId !== $a && $userId !== $b) {
                throw new \RuntimeException('forbidden');
            }
        }
        $member = $ctype . ':' . $cid;
        if (!$pinned && $ctype === 1) {
            $bits = explode('_', $cid);
            if (count($bits) === 2) {
                $a = (int)$bits[0];
                $b = (int)$bits[1];
                $peer = ($a === $userId) ? $b : $a;
                if (AdminService::isDefaultCs($peer)) {
                    throw new \RuntimeException('红宝客服会话不可取消置顶');
                }
            }
        }
        try {
            $r = RedisClient::conn();
            $key = RedisClient::key('pins:' . $userId);
            if ($pinned) {
                $r->zAdd($key, time(), $member);
                $r->zRemRangeByRank($key, 0, -21); // 最多 20 个置顶
            } else {
                $r->zRem($key, $member);
            }
            $r->expire($key, 86400 * 365);
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException('pin failed');
        }
        $this->invalidateConvListCache($userId);
        return [
            'conversation_type' => $ctype,
            'conversation_id'   => $cid,
            'pinned'            => (bool)$pinned,
        ];
    }

    /**
     * 删除私聊会话（仅对本用户隐藏列表）：备份消息到删除表，不删对方可见的原消息
     */
    public function hidePrivateConversation($userId, $conversationId, $peerUserId = 0)
    {
        $userId = (int)$userId;
        $cid = (string)$conversationId;
        $peerUserId = (int)$peerUserId;
        if ($userId <= 0) {
            throw new \InvalidArgumentException('invalid user');
        }
        if ($cid === '' && $peerUserId > 0) {
            $cid = IdGenerator::privateConversationId($userId, $peerUserId);
        }
        $bits = explode('_', $cid);
        if (count($bits) !== 2) {
            throw new \InvalidArgumentException('invalid conversation');
        }
        $a = (int)$bits[0];
        $b = (int)$bits[1];
        if ($userId !== $a && $userId !== $b) {
            throw new \RuntimeException('forbidden');
        }
        if ($peerUserId <= 0) {
            $peerUserId = ($a === $userId) ? $b : $a;
        }
        if (AdminService::isDefaultCs($peerUserId)) {
            throw new \RuntimeException('红宝客服会话不可删除');
        }

        $msgTable = Db::table('chat_messages');
        $delTable = Db::table('chat_conversation_deleted');
        $bakTable = Db::table('chat_messages_deleted_backup');
        $now = time();

        // 全量备份（含撤回），便于审计恢复
        $rows = Db::fetchAll(
            "SELECT id,msg_id,conversation_type,conversation_id,group_id,from_user_id,to_user_id,"
            . "msg_type,content,extra,status,createtime FROM {$msgTable}"
            . " WHERE conversation_type=1 AND conversation_id=? ORDER BY id ASC",
            [$cid]
        );
        $lastMsgId = 0;
        $lastPreview = null;
        if ($rows) {
            $last = $rows[count($rows) - 1];
            $lastMsgId = (int)($last['id'] ?? 0);
            $lastPreview = [
                'id'         => $lastMsgId,
                'msg_type'   => (int)($last['msg_type'] ?? 0),
                'content'    => (string)($last['content'] ?? ''),
                'status'     => (int)($last['status'] ?? 0),
                'createtime' => (int)($last['createtime'] ?? 0),
            ];
        }
        $payloadJson = json_encode([
            'peer_user_id' => $peerUserId,
            'last_message' => $lastPreview,
            'msg_count'    => count($rows),
        ], JSON_UNESCAPED_UNICODE);

        Db::begin();
        try {
            Db::exec(
                "INSERT INTO {$delTable}"
                . " (user_id,conversation_type,conversation_id,peer_user_id,deleted_at,restored_at,backup_msg_count,last_msg_id,payload_json)"
                . " VALUES (?,?,?,?,?,0,?,?,?)",
                [
                    $userId,
                    1,
                    $cid,
                    $peerUserId,
                    $now,
                    count($rows),
                    $lastMsgId,
                    $payloadJson,
                ]
            );
            $backupId = Db::lastId();
            if ($rows) {
                $chunkSize = 80;
                for ($i = 0; $i < count($rows); $i += $chunkSize) {
                    $chunk = array_slice($rows, $i, $chunkSize);
                    $placeholders = [];
                    $bind = [];
                    foreach ($chunk as $row) {
                        $placeholders[] = '(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
                        $extra = $row['extra'] ?? null;
                        if (is_array($extra)) {
                            $extra = json_encode($extra, JSON_UNESCAPED_UNICODE);
                        }
                        $bind[] = $backupId;
                        $bind[] = $userId;
                        $bind[] = (int)$row['id'];
                        $bind[] = (string)($row['msg_id'] ?? '');
                        $bind[] = (int)($row['conversation_type'] ?? 1);
                        $bind[] = (string)($row['conversation_id'] ?? $cid);
                        $bind[] = (int)($row['group_id'] ?? 0);
                        $bind[] = (int)($row['from_user_id'] ?? 0);
                        $bind[] = (int)($row['to_user_id'] ?? 0);
                        $bind[] = (int)($row['msg_type'] ?? 1);
                        $bind[] = (string)($row['content'] ?? '');
                        $bind[] = $extra;
                        $bind[] = (int)($row['status'] ?? 1);
                        $bind[] = (int)($row['createtime'] ?? 0);
                        $bind[] = $now;
                    }
                    Db::exec(
                        "INSERT INTO {$bakTable}"
                        . " (backup_id,user_id,orig_msg_id,msg_id,conversation_type,conversation_id,group_id,"
                        . "from_user_id,to_user_id,msg_type,content,extra,status,createtime,backed_up_at)"
                        . " VALUES " . implode(',', $placeholders),
                        $bind
                    );
                }
            }
            Db::commit();
        } catch (\Throwable $e) {
            try {
                Db::rollBack();
            } catch (\Throwable $e2) {
            }
            throw new \RuntimeException('delete backup failed');
        }

        $member = '1:' . $cid;
        try {
            $r = RedisClient::conn();
            $r->zRem(RedisClient::key('inbox:' . $userId), $member);
            $r->zRem(RedisClient::key('pins:' . $userId), $member);
            $r->del(RedisClient::key('unread:' . $userId . ':1:' . $cid));
            $hk = RedisClient::key('hidden:' . $userId);
            $r->sAdd($hk, $cid);
            $r->expire($hk, 86400 * 365);
        } catch (\Throwable $e) {
        }
        $this->invalidateConvListCache($userId);

        return [
            'conversation_type' => 1,
            'conversation_id'   => $cid,
            'peer_user_id'      => $peerUserId,
            'backup_id'         => (int)$backupId,
            'backup_msg_count'  => count($rows),
            'deleted'           => true,
        ];
    }

    /**
     * 删除群聊会话（本端软删）：写入水位 cleared_msg_id，历史/列表只展示 id 更大的消息
     * @param int $clearedMsgId 指定水位；0 则取该群当前最大消息 id
     */
    public function clearGroupConversation($userId, $groupId, $clearedMsgId = 0)
    {
        $userId = (int)$userId;
        $groupId = (int)$groupId;
        $clearedMsgId = (int)$clearedMsgId;
        if ($userId <= 0 || $groupId <= 0) {
            throw new \InvalidArgumentException('invalid group');
        }
        if (!(new GroupService())->isMember($groupId, $userId)) {
            throw new \RuntimeException('not in group');
        }

        if ($clearedMsgId <= 0) {
            $row = Db::fetch(
                'SELECT MAX(id) AS mid FROM ' . Db::table('chat_messages')
                . ' WHERE conversation_type=2 AND conversation_id=? AND status IN (1,2)',
                [(string)$groupId]
            );
            $clearedMsgId = (int)($row['mid'] ?? 0);
        }
        if ($clearedMsgId <= 0) {
            // 无消息也记水位 0，并从 inbox 移除
            $clearedMsgId = 0;
        }

        $prev = $this->groupClearedMsgId($userId, $groupId);
        if ($clearedMsgId < $prev) {
            $clearedMsgId = $prev;
        }

        $now = time();
        $table = Db::table('chat_group_msg_cleared');
        Db::exec(
            "INSERT INTO {$table} (user_id, group_id, cleared_msg_id, updatetime, createtime)"
            . ' VALUES (?,?,?,?,?)'
            . ' ON DUPLICATE KEY UPDATE cleared_msg_id=GREATEST(cleared_msg_id, VALUES(cleared_msg_id)), updatetime=VALUES(updatetime)',
            [$userId, $groupId, $clearedMsgId, $now, $now]
        );
        $this->bustGroupClearedCache($userId);

        $member = '2:' . $groupId;
        try {
            $r = RedisClient::conn();
            $r->zRem(RedisClient::key('inbox:' . $userId), $member);
            $r->zRem(RedisClient::key('pins:' . $userId), $member);
            $r->del(RedisClient::key('unread:' . $userId . ':2:' . $groupId));
        } catch (\Throwable $e) {
        }
        $this->invalidateConvListCache($userId);

        // 同步已读游标，避免未读按旧水位再亮起来
        if ($clearedMsgId > 0) {
            try {
                $this->markConversationRead($userId, 2, (string)$groupId, $clearedMsgId);
            } catch (\Throwable $e) {
            }
        }

        return [
            'conversation_type' => 2,
            'conversation_id'   => (string)$groupId,
            'group_id'          => $groupId,
            'cleared_msg_id'    => $clearedMsgId,
            'deleted'           => true,
        ];
    }

    public function groupClearedMsgId($userId, $groupId)
    {
        $userId = (int)$userId;
        $groupId = (int)$groupId;
        if ($userId <= 0 || $groupId <= 0) {
            return 0;
        }
        $map = $this->groupClearedMap($userId);
        return (int)($map[$groupId] ?? 0);
    }

    /** @return array<int,int> group_id => cleared_msg_id */
    protected function groupClearedMap($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return [];
        }
        try {
            $r = RedisClient::conn();
            $ck = RedisClient::key('gclear:' . $userId);
            $cached = $r->get($ck);
            if ($cached !== false && $cached !== null && $cached !== '') {
                $decoded = json_decode((string)$cached, true);
                if (is_array($decoded)) {
                    $out = [];
                    foreach ($decoded as $gid => $mid) {
                        $out[(int)$gid] = (int)$mid;
                    }
                    return $out;
                }
            }
        } catch (\Throwable $e) {
        }

        $out = [];
        try {
            $rows = Db::fetchAll(
                'SELECT group_id, cleared_msg_id FROM ' . Db::table('chat_group_msg_cleared')
                . ' WHERE user_id=?',
                [$userId]
            );
            foreach ($rows as $row) {
                $gid = (int)($row['group_id'] ?? 0);
                if ($gid > 0) {
                    $out[$gid] = (int)($row['cleared_msg_id'] ?? 0);
                }
            }
        } catch (\Throwable $e) {
            return [];
        }
        try {
            RedisClient::conn()->setex(
                RedisClient::key('gclear:' . $userId),
                300,
                json_encode($out, JSON_UNESCAPED_UNICODE)
            );
        } catch (\Throwable $e) {
        }
        return $out;
    }

    protected function bustGroupClearedCache($userId)
    {
        try {
            RedisClient::conn()->del(RedisClient::key('gclear:' . (int)$userId));
        } catch (\Throwable $e) {
        }
    }

    protected function filterClearedGroupConversations($userId, array &$items)
    {
        $cleared = $this->groupClearedMap($userId);
        if (!$cleared) {
            return;
        }
        $dropMembers = [];
        $kept = [];
        foreach ($items as $it) {
            if ((int)($it['conversation_type'] ?? 0) !== 2) {
                $kept[] = $it;
                continue;
            }
            $gid = (int)($it['group_id'] ?? $it['conversation_id'] ?? 0);
            $watermark = (int)($cleared[$gid] ?? 0);
            if ($watermark <= 0) {
                $kept[] = $it;
                continue;
            }
            $lastId = (int)(($it['last_message']['id'] ?? 0) ?: ($it['_last_msg_id'] ?? 0));
            if ($lastId > 0 && $lastId <= $watermark) {
                $dropMembers[] = '2:' . $gid;
                continue;
            }
            // last_message 已被水位盖住但 inbox 分数未更新：尝试找下一条可见预览
            if ($lastId <= 0 && empty($it['last_message'])) {
                $kept[] = $it;
                continue;
            }
            if ($lastId > $watermark) {
                $kept[] = $it;
                continue;
            }
            $dropMembers[] = '2:' . $gid;
        }
        $items = $kept;
        if ($dropMembers) {
            try {
                $r = RedisClient::conn();
                $ikey = RedisClient::key('inbox:' . (int)$userId);
                foreach ($dropMembers as $member) {
                    $r->zRem($ikey, $member);
                }
            } catch (\Throwable $e) {
            }
        }
    }

    /** @return array<string,bool> conversation_id => true */
    protected function hiddenPrivateCidMap($userId)
    {
        $userId = (int)$userId;
        $out = [];
        if ($userId <= 0) {
            return $out;
        }
        try {
            $rows = Db::fetchAll(
                'SELECT conversation_id FROM ' . Db::table('chat_conversation_deleted')
                . ' WHERE user_id=? AND conversation_type=1 AND restored_at=0',
                [$userId]
            );
            foreach ($rows as $row) {
                $cid = (string)($row['conversation_id'] ?? '');
                if ($cid !== '') {
                    $out[$cid] = true;
                }
            }
        } catch (\Throwable $e) {
        }
        return $out;
    }

    protected function filterHiddenPrivateConversations($userId, array &$items)
    {
        $hidden = $this->hiddenPrivateCidMap($userId);
        if (!$hidden) {
            return;
        }
        try {
            $r = RedisClient::conn();
            $ikey = RedisClient::key('inbox:' . (int)$userId);
            foreach (array_keys($hidden) as $cid) {
                $r->zRem($ikey, '1:' . $cid);
            }
        } catch (\Throwable $e) {
        }
        $items = array_values(array_filter($items, function ($it) use ($hidden) {
            if ((int)($it['conversation_type'] ?? 0) !== 1) {
                return true;
            }
            // 默认客服会话永不可被「删除聊天」隐藏
            if (AdminService::isDefaultCs((int)($it['peer_user_id'] ?? 0))) {
                return true;
            }
            $cid = (string)($it['conversation_id'] ?? '');
            return $cid === '' || !isset($hidden[$cid]);
        }));
    }

    protected function restoreHiddenPrivateConversation($userId, $conversationId)
    {
        $userId = (int)$userId;
        $cid = (string)$conversationId;
        if ($userId <= 0 || $cid === '') {
            return;
        }
        $wasHidden = false;
        try {
            $wasHidden = (bool)RedisClient::conn()->sIsMember(RedisClient::key('hidden:' . $userId), $cid);
        } catch (\Throwable $e) {
        }
        if (!$wasHidden) {
            // Redis 未命中时也查库，避免漏恢复
            try {
                $row = Db::fetch(
                    'SELECT id FROM ' . Db::table('chat_conversation_deleted')
                    . ' WHERE user_id=? AND conversation_type=1 AND conversation_id=? AND restored_at=0 LIMIT 1',
                    [$userId, $cid]
                );
                $wasHidden = !empty($row);
            } catch (\Throwable $e) {
                return;
            }
        }
        if (!$wasHidden) {
            return;
        }
        try {
            Db::exec(
                'UPDATE ' . Db::table('chat_conversation_deleted')
                . ' SET restored_at=? WHERE user_id=? AND conversation_type=1 AND conversation_id=? AND restored_at=0',
                [time(), $userId, $cid]
            );
        } catch (\Throwable $e) {
        }
        try {
            RedisClient::conn()->sRem(RedisClient::key('hidden:' . $userId), $cid);
        } catch (\Throwable $e) {
        }
        $this->invalidateConvListCache($userId);
    }

    /**
     * 批量未读：先取全部已读游标，再一次 GROUP BY 统计（避免每个会话 2 次查询）
     */
    protected function batchUnreadCounts($userId, array $items)
    {
        $userId = (int)$userId;
        $out = [];
        $targets = [];
        foreach ($items as $it) {
            $ctype = (int)($it['conversation_type'] ?? 0);
            $cid = (string)($it['conversation_id'] ?? '');
            if ($userId <= 0 || ($ctype !== 1 && $ctype !== 2) || $cid === '') {
                continue;
            }
            $key = $ctype . ':' . $cid;
            $targets[$key] = ['type' => $ctype, 'id' => $cid];
            $out[$key] = 0;
        }
        if (!$targets) {
            return $out;
        }

        $lastIds = [];
        foreach ($items as $it) {
            $ik = ((int)($it['conversation_type'] ?? 0)) . ':' . (string)($it['conversation_id'] ?? '');
            $lastIds[$ik] = (int)(($it['last_message']['id'] ?? 0));
        }

        // 优先 Redis 未读：命中则跳过读游标 SQL；仅 miss 再查 cursor + COUNT
        $needSql = [];
        $redisMap = $this->unreadFromRedis($userId, $targets);
        foreach ($targets as $key => $t) {
            $lastId = (int)($lastIds[$key] ?? 0);
            if ($lastId <= 0) {
                $out[$key] = 0;
                continue;
            }
            if ($redisMap !== null && isset($redisMap[$key]) && $redisMap[$key] >= 0) {
                $out[$key] = (int)$redisMap[$key];
                continue;
            }
            $needSql[$key] = $t;
        }

        if (!$needSql) {
            return $out;
        }

        $cursors = [];
        foreach ($needSql as $key => $t) {
            $cursors[$key] = 0;
        }
        if ($this->readTableReady()) {
            $params = [$userId];
            $ors = [];
            foreach ($needSql as $t) {
                $ors[] = '(conversation_type=? AND conversation_id=?)';
                $params[] = $t['type'];
                $params[] = $t['id'];
            }
            $rows = Db::fetchAll(
                'SELECT conversation_type, conversation_id, last_read_msg_id FROM ' . Db::table('chat_conversation_read')
                . ' WHERE user_id=? AND (' . implode(' OR ', $ors) . ')',
                $params
            );
            foreach ($rows as $row) {
                $key = ((int)$row['conversation_type']) . ':' . (string)$row['conversation_id'];
                $cursors[$key] = max($cursors[$key] ?? 0, (int)$row['last_read_msg_id']);
            }
        }

        $groupIds = [];
        foreach ($needSql as $t) {
            if ($t['type'] === 2) {
                $gid = (int)$t['id'];
                if ($gid > 0) {
                    $groupIds[] = $gid;
                }
            }
        }
        if ($groupIds) {
            $in = implode(',', array_fill(0, count($groupIds), '?'));
            $memberRows = Db::fetchAll(
                'SELECT group_id, last_read_msg_id FROM ' . Db::table('chat_group_members')
                . " WHERE user_id=? AND status=1 AND group_id IN ({$in})",
                array_merge([$userId], $groupIds)
            );
            foreach ($memberRows as $row) {
                $key = '2:' . (string)((int)$row['group_id']);
                $cursors[$key] = max($cursors[$key] ?? 0, (int)$row['last_read_msg_id']);
            }
        }

        // 群软删水位：未读从 cleared_msg_id 之后算起
        $clearedMap = $this->groupClearedMap($userId);
        if ($clearedMap) {
            foreach ($needSql as $key => $t) {
                if ((int)$t['type'] !== 2) {
                    continue;
                }
                $wm = (int)($clearedMap[(int)$t['id']] ?? 0);
                if ($wm > 0) {
                    $cursors[$key] = max($cursors[$key] ?? 0, $wm);
                }
            }
        }

        foreach ($needSql as $key => $t) {
            $lastId = (int)($lastIds[$key] ?? 0);
            $cursor = (int)($cursors[$key] ?? 0);
            if ($lastId <= $cursor) {
                $out[$key] = 0;
                unset($needSql[$key]);
                $this->clearUnreadCounter($userId, $t['type'], $t['id']);
            }
        }

        if (!$needSql) {
            return $out;
        }

        $unions = [];
        $params = [];
        foreach ($needSql as $key => $t) {
            $cursor = (int)($cursors[$key] ?? 0);
            $unions[] = 'SELECT ? AS conversation_type, ? AS conversation_id, COUNT(*) AS c FROM '
                . Db::table('chat_messages')
                . ' WHERE conversation_type=? AND conversation_id=? AND status=1 AND id>? AND from_user_id<>?';
            $params[] = $t['type'];
            $params[] = $t['id'];
            $params[] = $t['type'];
            $params[] = $t['id'];
            $params[] = $cursor;
            $params[] = $userId;
        }
        $countRows = Db::fetchAll(implode(' UNION ALL ', $unions), $params);
        try {
            $r = RedisClient::conn();
            foreach ($countRows as $row) {
                $key = ((int)$row['conversation_type']) . ':' . (string)$row['conversation_id'];
                $c = (int)($row['c'] ?? 0);
                $out[$key] = $c;
                $r->setex(
                    RedisClient::key('unread:' . $userId . ':' . ((int)$row['conversation_type']) . ':' . (string)$row['conversation_id']),
                    86400 * 30,
                    (string)$c
                );
            }
        } catch (\Throwable $e) {
            foreach ($countRows as $row) {
                $key = ((int)$row['conversation_type']) . ':' . (string)$row['conversation_id'];
                $out[$key] = (int)($row['c'] ?? 0);
            }
        }
        return $out;
    }

    public function markConversationRead($userId, $conversationType, $conversationId, $lastReadMsgId = 0)
    {
        $userId = (int)$userId;
        $conversationType = (int)$conversationType;
        $conversationId = (string)$conversationId;
        $lastReadMsgId = max(0, (int)$lastReadMsgId);
        if ($userId <= 0 || ($conversationType !== 1 && $conversationType !== 2) || $conversationId === '') {
            return;
        }
        if (!$this->readTableReady()) {
            return;
        }
        $now = time();
        $table = Db::table('chat_conversation_read');
        $existing = Db::fetch(
            'SELECT id,last_read_msg_id FROM ' . $table
            . ' WHERE user_id=? AND conversation_type=? AND conversation_id=? LIMIT 1',
            [$userId, $conversationType, $conversationId]
        );
        if ($existing) {
            $next = max((int)$existing['last_read_msg_id'], $lastReadMsgId);
            Db::exec(
                'UPDATE ' . $table . ' SET last_read_msg_id=?, updatetime=? WHERE id=?',
                [$next, $now, (int)$existing['id']]
            );
        } else {
            Db::exec(
                'INSERT INTO ' . $table
                . ' (user_id,conversation_type,conversation_id,last_read_msg_id,updatetime) VALUES (?,?,?,?,?)',
                [$userId, $conversationType, $conversationId, $lastReadMsgId, $now]
            );
        }
        if ($conversationType === 2) {
            $gid = (int)$conversationId;
            if ($gid > 0 && $lastReadMsgId > 0) {
                Db::exec(
                    'UPDATE ' . Db::table('chat_group_members')
                    . ' SET last_read_msg_id=GREATEST(last_read_msg_id, ?), updatetime=?'
                    . ' WHERE group_id=? AND user_id=? AND status=1',
                    [$lastReadMsgId, $now, $gid, $userId]
                );
            }
        }
        $this->clearUnreadCounter($userId, $conversationType, $conversationId);
        $this->invalidateConvListCache($userId);
    }

    public function countUnread($userId, $conversationType, $conversationId)
    {
        $userId = (int)$userId;
        $conversationType = (int)$conversationType;
        $conversationId = (string)$conversationId;
        if ($userId <= 0 || $conversationId === '') {
            return 0;
        }
        $cursor = $this->readCursor($userId, $conversationType, $conversationId);
        $row = Db::fetch(
            'SELECT COUNT(*) AS c FROM ' . Db::table('chat_messages')
            . ' WHERE conversation_type=? AND conversation_id=? AND status=1'
            . ' AND id>? AND from_user_id<>?',
            [$conversationType, $conversationId, $cursor, $userId]
        );
        return (int)($row['c'] ?? 0);
    }

    protected function readCursor($userId, $conversationType, $conversationId)
    {
        $cursor = 0;
        if ($this->readTableReady()) {
            $row = Db::fetch(
                'SELECT last_read_msg_id FROM ' . Db::table('chat_conversation_read')
                . ' WHERE user_id=? AND conversation_type=? AND conversation_id=? LIMIT 1',
                [(int)$userId, (int)$conversationType, (string)$conversationId]
            );
            if ($row) {
                $cursor = (int)$row['last_read_msg_id'];
            }
        }
        if ((int)$conversationType === 2) {
            $gid = (int)$conversationId;
            if ($gid > 0) {
                $member = Db::fetch(
                    'SELECT last_read_msg_id FROM ' . Db::table('chat_group_members')
                    . ' WHERE group_id=? AND user_id=? AND status=1 LIMIT 1',
                    [$gid, (int)$userId]
                );
                if ($member) {
                    $cursor = max($cursor, (int)$member['last_read_msg_id']);
                }
            }
        }
        return $cursor;
    }

    /** @var bool|null */
    protected static $readTableReady;

    protected function readTableReady()
    {
        if (self::$readTableReady !== null) {
            return self::$readTableReady;
        }
        try {
            Db::fetch('SELECT id FROM ' . Db::table('chat_conversation_read') . ' LIMIT 1');
            self::$readTableReady = true;
        } catch (\Throwable $e) {
            self::$readTableReady = false;
        }
        return self::$readTableReady;
    }

    /**
     * @return array{0:string,1:int,2:array|null}
     */
    protected function prepareOutgoing($content, $msgType, $extra)
    {
        $msgType = (int)$msgType;
        $content = mb_substr(trim((string)$content), 0, 2000);
        if (!in_array($msgType, [1, 2, 4, 5, 6, 7, 8], true)) {
            $msgType = 1;
        }
        if ($msgType === 8) {
            if (is_string($extra) && $extra !== '') {
                $decoded = json_decode($extra, true);
                $extra = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($extra)) {
                $extra = [];
            }
            $clean = [];
            if (!empty($extra['transfer_no'])) {
                $clean['transfer_no'] = mb_substr(trim((string)$extra['transfer_no']), 0, 64);
            }
            if (isset($extra['amount'])) {
                $clean['amount'] = round((float)$extra['amount'], 2);
            }
            if (isset($extra['remark'])) {
                $clean['remark'] = mb_substr(trim((string)$extra['remark']), 0, 40);
            }
            if (isset($extra['status'])) {
                $clean['status'] = (int)$extra['status'];
            }
            if ($content === '') {
                $amt = isset($clean['amount']) ? sprintf('%.2f', $clean['amount']) : '0.00';
                $content = '[转账]￥' . $amt;
            }
            return [$content, 8, $clean ?: null];
        }
        if ($msgType === 2) {
            if (is_string($extra) && $extra !== '') {
                $decoded = json_decode($extra, true);
                $extra = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($extra)) {
                $extra = [];
            }
            $clean = [];
            if (!empty($extra['packet_id'])) {
                $clean['packet_id'] = (int)$extra['packet_id'];
            }
            if (!empty($extra['packet_no'])) {
                $clean['packet_no'] = mb_substr(trim((string)$extra['packet_no']), 0, 64);
            }
            if (isset($extra['total_amount'])) {
                $clean['total_amount'] = round((float)$extra['total_amount'], 2);
            }
            if (isset($extra['total_count'])) {
                $clean['total_count'] = (int)$extra['total_count'];
            }
            if (isset($extra['packet_type'])) {
                $clean['packet_type'] = (int)$extra['packet_type'];
            }
            // 扫雷雷号须落库到消息 extra，封面才能显示准确数字（0 也要保留）
            if (array_key_exists('mine_digit', $extra) && $extra['mine_digit'] !== null && $extra['mine_digit'] !== '') {
                $clean['mine_digit'] = max(0, min(9, (int)$extra['mine_digit']));
            }
            if (isset($extra['expiretime'])) {
                $clean['expiretime'] = max(0, (int)$extra['expiretime']);
            }
            if (!empty($extra['skin_id'])) {
                $clean['skin_id'] = (int)$extra['skin_id'];
            }
            if (isset($extra['blessing'])) {
                $clean['blessing'] = mb_substr(trim((string)$extra['blessing']), 0, 100);
            }
            if ($content === '') {
                $content = '[红包]' . ($clean['blessing'] ?? '恭喜发财');
            }
            return [$content, 2, $clean ?: null];
        }
        if ($msgType === 6) {
            $extra = $this->normalizeExtra($extra, true);
            $url = (string)($extra['url'] ?? '');
            $code = mb_substr(trim((string)($extra['code'] ?? '')), 0, 64);
            if ($code === '' || !$this->isAllowedStickerUrl($url)) {
                throw new \InvalidArgumentException('invalid sticker');
            }
            $extra['url'] = $url;
            $extra['code'] = $code;
            if (empty($extra['pack'])) {
                $extra['pack'] = 'wechat';
            }
            if ($content === '') {
                $content = '[' . $code . ']';
            }
            return [$content, 6, $extra];
        }
        if ($msgType === 4 || $msgType === 5 || $msgType === 7) {
            $extra = $this->normalizeExtra($extra, false, $msgType === 7);
            $url = (string)($extra['url'] ?? '');
            if ($msgType === 7) {
                if (!$this->isAllowedFileUrl($url)) {
                    throw new \InvalidArgumentException('invalid file url');
                }
            } elseif (!$this->isAllowedMediaUrl($url, $msgType)) {
                throw new \InvalidArgumentException('invalid media url');
            }
            $extra['url'] = $url;
            if (isset($extra['thumb']) && !$this->isAllowedMediaUrl((string)$extra['thumb'], 4)) {
                unset($extra['thumb']);
            }
            if ($content === '') {
                if ($msgType === 4) {
                    $content = '[图片]';
                } elseif ($msgType === 5) {
                    $content = '[视频]';
                } else {
                    $name = (string)($extra['name'] ?? '文件');
                    $content = '[文件]' . mb_substr($name, 0, 80);
                }
            }
            return [$content, $msgType, $extra];
        }
        if ($content === '') {
            throw new \InvalidArgumentException('empty content');
        }
        return [$content, 1, null];
    }

    protected function normalizeExtra($extra, $sticker = false, $file = false)
    {
        if (is_string($extra) && $extra !== '') {
            $decoded = json_decode($extra, true);
            $extra = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($extra)) {
            $extra = [];
        }
        $clean = [];
        if ($sticker) {
            $keys = ['url', 'fullurl', 'pack', 'code', 'name'];
        } elseif ($file) {
            $keys = ['url', 'fullurl', 'name', 'ext', 'mime'];
        } else {
            $keys = ['url', 'fullurl', 'thumb', 'name'];
        }
        foreach ($keys as $key) {
            if (!empty($extra[$key])) {
                $clean[$key] = mb_substr(trim((string)$extra[$key]), 0, 500);
            }
        }
        foreach (['w', 'h', 'duration', 'size'] as $key) {
            if (isset($extra[$key])) {
                $clean[$key] = max(0, (int)$extra[$key]);
            }
        }
        return $clean;
    }

    protected function isAllowedStickerUrl($url)
    {
        $url = trim((string)$url);
        if ($url === '' || strlen($url) > 500 || strpos($url, '..') !== false) {
            return false;
        }
        $path = $url;
        if (preg_match('#^https?://#i', $url)) {
            $parts = parse_url($url);
            $path = (string)($parts['path'] ?? '');
        }
        if ($path === '' || $path[0] !== '/') {
            return false;
        }
        if (strpos($path, '/888/stickers/') !== 0
            && strpos($path, '/stickers/') !== 0
            && strpos($path, '/uploads/stickers/') !== 0) {
            return false;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, ['png', 'gif', 'webp', 'jpg', 'jpeg'], true);
    }

    protected function isAllowedFileUrl($url)
    {
        $url = trim((string)$url);
        if ($url === '' || strlen($url) > 500 || strpos($url, '..') !== false) {
            return false;
        }
        $path = $url;
        if (preg_match('#^https?://#i', $url)) {
            $parts = parse_url($url);
            $path = (string)($parts['path'] ?? '');
        }
        if ($path === '' || $path[0] !== '/') {
            return false;
        }
        if (strpos($path, '/uploads/') !== 0) {
            return false;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $allow = [
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'zip', 'rar', '7z',
            'apk', 'ipa', 'json', 'xml', 'md', 'log', 'rtf', 'odt', 'ods',
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'mp4', 'webm', 'mov', 'm4v',
        ];
        return $ext !== '' && in_array($ext, $allow, true);
    }

    protected function isAllowedMediaUrl($url, $msgType)
    {
        $url = trim((string)$url);
        if ($url === '' || strlen($url) > 500) {
            return false;
        }
        if (strpos($url, '..') !== false) {
            return false;
        }
        $path = $url;
        if (preg_match('#^https?://#i', $url)) {
            $parts = parse_url($url);
            $path = (string)($parts['path'] ?? '');
        }
        if ($path === '' || $path[0] !== '/') {
            return false;
        }
        if (strpos($path, '/uploads/') !== 0) {
            return false;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $imageExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
        $videoExt = ['mp4', 'webm', 'mov', 'm4v'];
        if ((int)$msgType === 4) {
            return in_array($ext, $imageExt, true);
        }
        if ((int)$msgType === 5) {
            return in_array($ext, $videoExt, true);
        }
        return in_array($ext, array_merge($imageExt, $videoExt), true);
    }

    public function normalizeMessage(array $row)
    {
        if (isset($row['extra']) && is_string($row['extra']) && $row['extra'] !== '') {
            $decoded = json_decode($row['extra'], true);
            if (is_array($decoded)) {
                $row['extra'] = $decoded;
            }
        }
        $row['id'] = (int)($row['id'] ?? 0);
        $row['conversation_type'] = (int)($row['conversation_type'] ?? 1);
        $row['group_id'] = (int)($row['group_id'] ?? 0);
        $row['from_user_id'] = (int)($row['from_user_id'] ?? 0);
        $row['to_user_id'] = (int)($row['to_user_id'] ?? 0);
        $row['msg_type'] = (int)($row['msg_type'] ?? 1);
        $row['status'] = (int)($row['status'] ?? 1);
        $row['createtime'] = (int)($row['createtime'] ?? 0);
        if (empty($row['msg_id']) && $row['id'] > 0) {
            $row['msg_id'] = (string)$row['id'];
        }
        return $row;
    }
}
