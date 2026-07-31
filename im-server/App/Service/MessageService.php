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

    public function sendGroup($fromUserId, $groupId, $content, $msgType = 1, $extra = null)
    {
        $fromUserId = (int)$fromUserId;
        $groupId = (int)$groupId;
        if ($fromUserId <= 0 || $groupId <= 0) {
            throw new \InvalidArgumentException('invalid group chat');
        }
        (new GroupService())->assertCanSpeak($groupId, $fromUserId);
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
        return $payload;
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
     * 维护每人最近会话 ZSET（score=消息 id），列表 O(logN) 取 Top，避免消息表 GROUP BY 越扫越慢
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
            try {
                $uids = (new GroupService())->memberUserIds((int)($payload['group_id'] ?? $cid));
            } catch (\Throwable $e) {
                $uids = [(int)$payload['from_user_id']];
            }
        }
        try {
            $r = RedisClient::conn();
            foreach ($uids as $uid) {
                $uid = (int)$uid;
                if ($uid <= 0) {
                    continue;
                }
                $key = RedisClient::key('inbox:' . $uid);
                $r->zAdd($key, $msgId, $member);
                // 只保留最近 200 个会话键
                $r->zRemRangeByRank($key, 0, -201);
                $r->expire($key, 86400 * 30);
            }
        } catch (\Throwable $e) {
        }
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

    public function history($conversationType, $conversationId, $beforeId = 0, $limit = 30)
    {
        $limit = max(1, min(100, (int)$limit));
        $conversationType = (int)$conversationType;
        $conversationId = (string)$conversationId;
        $beforeId = (int)$beforeId;

        // 首屏：优先 Redis recent（写入时已 LPUSH），避免每次打开会话扫表
        if ($beforeId <= 0) {
            try {
                $key = RedisClient::key('conv:' . $conversationType . ':' . $conversationId . ':recent');
                $rawList = RedisClient::conn()->lRange($key, 0, $limit - 1);
                if (is_array($rawList) && count($rawList) >= $limit) {
                    $rows = [];
                    foreach ($rawList as $raw) {
                        $j = json_decode((string)$raw, true);
                        if (is_array($j) && !empty($j['id'])) {
                            $rows[] = $this->normalizeMessage($j);
                        }
                    }
                    if (count($rows) >= $limit) {
                        return array_reverse($rows);
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        $sql = 'SELECT * FROM ' . Db::table('chat_messages')
            . ' WHERE conversation_type=? AND conversation_id=? AND status IN (1,2)';
        $bind = [$conversationType, $conversationId];
        if ($beforeId > 0) {
            $sql .= ' AND id < ?';
            $bind[] = $beforeId;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . $limit;
        $rows = Db::fetchAll($sql, $bind);
        $list = array_map([$this, 'normalizeMessage'], array_reverse($rows));
        // 回填 recent，供下次秒开（LPUSH 后左侧为最新）
        if ($beforeId <= 0 && $list) {
            try {
                $key = RedisClient::key('conv:' . $conversationType . ':' . $conversationId . ':recent');
                $r = RedisClient::conn();
                $r->del($key);
                foreach ($list as $m) {
                    $r->lPush($key, json_encode($m, JSON_UNESCAPED_UNICODE));
                }
                $r->lTrim($key, 0, 99);
                $r->expire($key, 86400 * 7);
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
        if ($isOwner && !$isAdmin) {
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
        $row['content'] = '[已撤回]';
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
        $inbox = $this->inboxMap($userId, max(80, $limit * 2));

        if ($inbox) {
            $msgIds = array_values(array_unique(array_filter(array_map('intval', array_values($inbox)))));
            $msgById = [];
            if ($msgIds) {
                $in = implode(',', array_fill(0, count($msgIds), '?'));
                $rows = Db::fetchAll(
                    "SELECT * FROM {$msgTable} WHERE id IN ({$in}) AND status=1",
                    $msgIds
                );
                foreach ($rows as $row) {
                    $msgById[(int)$row['id']] = $this->normalizeMessage($row);
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
                        ];
                    }
                }
            }

            // 补全未出现在 inbox 的群（尚无消息）
            $groups = Db::fetchAll(
                'SELECT g.* FROM ' . Db::table('chat_groups') . ' g'
                . ' INNER JOIN ' . Db::table('chat_group_members') . ' m ON m.group_id=g.id'
                . ' WHERE m.user_id=? AND m.status=1 AND g.status IN (1,3)',
                [$userId]
            );
            $haveG = [];
            foreach ($items as $it) {
                if ((int)$it['conversation_type'] === 2) {
                    $haveG[(int)$it['group_id']] = true;
                }
            }
            $needMeta = array_keys($groupIdsNeeded);
            $metaById = [];
            if ($needMeta) {
                $in = implode(',', array_fill(0, count($needMeta), '?'));
                $metaRows = Db::fetchAll(
                    'SELECT id,name,avatar,updatetime,createtime FROM ' . Db::table('chat_groups')
                    . " WHERE id IN ({$in})",
                    $needMeta
                );
                foreach ($metaRows as $g) {
                    $metaById[(int)$g['id']] = $g;
                }
            }
            foreach ($items as &$it) {
                if ((int)$it['conversation_type'] !== 2) {
                    continue;
                }
                $g = $metaById[(int)$it['group_id']] ?? null;
                if ($g) {
                    $it['title'] = (string)($g['name'] ?? '');
                    $it['avatar'] = (string)($g['avatar'] ?? '');
                    if (empty($it['last_message'])) {
                        $it['updatetime'] = (int)($g['updatetime'] ?: $g['createtime']);
                    }
                }
            }
            unset($it);
            foreach ($groups as $g) {
                $gid = (int)$g['id'];
                if (isset($haveG[$gid])) {
                    continue;
                }
                $items[] = [
                    'conversation_type' => 2,
                    'conversation_id'   => (string)$gid,
                    'peer_user_id'      => 0,
                    'group_id'          => $gid,
                    'title'             => (string)($g['name'] ?? ''),
                    'avatar'            => (string)($g['avatar'] ?? ''),
                    'last_message'      => null,
                    'updatetime'        => (int)($g['updatetime'] ?: $g['createtime']),
                    'unread_count'      => 0,
                ];
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
                $m = $this->normalizeMessage($m);
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

            $groups = Db::fetchAll(
                'SELECT g.* FROM ' . Db::table('chat_groups') . ' g'
                . ' INNER JOIN ' . Db::table('chat_group_members') . ' m ON m.group_id=g.id'
                . ' WHERE m.user_id=? AND m.status=1 AND g.status IN (1,3)',
                [$userId]
            );
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
                    $lastByGroup[(string)$row['conversation_id']] = $this->normalizeMessage($row);
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
            $this->seedInboxFromItems($userId, $items);
        }

        usort($items, function ($a, $b) {
            return ((int)$b['updatetime']) <=> ((int)$a['updatetime']);
        });

        // 普通用户：会话列表始终包含全部 IM 管理员（即使尚未聊天）
        if (!AdminService::isImAdmin($userId)) {
            $havePeers = [];
            foreach ($items as $it) {
                if ((int)$it['conversation_type'] === 1 && (int)$it['peer_user_id'] > 0) {
                    $havePeers[(int)$it['peer_user_id']] = true;
                }
            }
            $adminRows = AdminService::adminRows();
            $adminMap = AdminService::adminIdMap();
            $now = time();
            foreach ($adminRows as $adminId => $adminMeta) {
                if ($adminId === $userId || isset($havePeers[$adminId])) {
                    continue;
                }
                $items[] = [
                    'conversation_type' => 1,
                    'conversation_id'   => IdGenerator::privateConversationId($userId, $adminId),
                    'peer_user_id'      => $adminId,
                    'group_id'          => 0,
                    'title'             => (string)($adminMeta['label'] ?? ''),
                    'avatar'            => '',
                    'last_message'      => null,
                    'is_im_admin'       => true,
                    'updatetime'        => $now,
                    'unread_count'      => 0,
                ];
            }
            usort($items, function ($a, $b) use ($adminMap) {
                $aAdmin = !empty($a['is_im_admin']) || isset($adminMap[(int)($a['peer_user_id'] ?? 0)]);
                $bAdmin = !empty($b['is_im_admin']) || isset($adminMap[(int)($b['peer_user_id'] ?? 0)]);
                if ($aAdmin !== $bAdmin) {
                    return $aAdmin ? -1 : 1;
                }
                return ((int)$b['updatetime']) <=> ((int)$a['updatetime']);
            });
        }

        $items = array_slice($items, 0, $limit);
        $unreadMap = $this->batchUnreadCounts($userId, $items);
        foreach ($items as &$it) {
            $key = ((int)$it['conversation_type']) . ':' . (string)$it['conversation_id'];
            $it['unread_count'] = (int)($unreadMap[$key] ?? 0);
        }
        unset($it);

        return $items;
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

        $cursors = [];
        foreach ($targets as $key => $t) {
            $cursors[$key] = 0;
        }
        if ($this->readTableReady()) {
            $params = [$userId];
            $ors = [];
            foreach ($targets as $t) {
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
        foreach ($targets as $t) {
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

        $lastIds = [];
        foreach ($items as $it) {
            $ik = ((int)($it['conversation_type'] ?? 0)) . ':' . (string)($it['conversation_id'] ?? '');
            $lastIds[$ik] = (int)(($it['last_message']['id'] ?? 0));
        }

        // 拆成按会话的 COUNT UNION ALL，强制走 idx_conv_time，避免巨型 OR 选错索引
        $unions = [];
        $params = [];
        foreach ($targets as $key => $t) {
            $lastId = (int)($lastIds[$key] ?? 0);
            $cursor = (int)($cursors[$key] ?? 0);
            if ($lastId <= 0 || $lastId <= $cursor) {
                $out[$key] = 0;
                continue;
            }
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
        if (!$unions) {
            return $out;
        }
        $countRows = Db::fetchAll(implode(' UNION ALL ', $unions), $params);
        foreach ($countRows as $row) {
            $key = ((int)$row['conversation_type']) . ':' . (string)$row['conversation_id'];
            $out[$key] = (int)($row['c'] ?? 0);
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
        if (!in_array($msgType, [1, 2, 4, 5, 6, 7], true)) {
            $msgType = 1;
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
