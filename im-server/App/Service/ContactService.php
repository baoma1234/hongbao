<?php

namespace Im\Service;

use Im\Support\Db;
use Im\Support\IdGenerator;

class ContactService
{
    public function isFriend($userId, $peerId)
    {
        $userId = (int)$userId;
        $peerId = (int)$peerId;
        if ($userId <= 0 || $peerId <= 0 || $userId === $peerId) {
            return false;
        }
        $row = Db::fetch(
            'SELECT id FROM ' . Db::table('chat_contacts')
            . ' WHERE user_id=? AND peer_user_id=? AND status=1 LIMIT 1',
            [$userId, $peerId]
        );
        return (bool)$row;
    }

    /**
     * 兼容旧调用：改为发起好友申请（客服号自动通过）
     * @return array{status:string,request_id:int,peer_user_id:int,conversation_id:string,auto_accepted?:bool,reply?:string}
     */
    public function addFriend($userId, $peerId, $message = '')
    {
        return $this->requestFriend($userId, $peerId, $message);
    }

    /**
     * 发起好友申请。客服号被添加时自动通过并发送自定义回复。
     */
    public function requestFriend($fromUserId, $toUserId, $message = '')
    {
        $fromUserId = (int)$fromUserId;
        $toUserId = (int)$toUserId;
        $message = mb_substr(trim((string)$message), 0, 200);
        if ($fromUserId <= 0 || $toUserId <= 0 || $fromUserId === $toUserId) {
            throw new \InvalidArgumentException('invalid user');
        }
        if ($this->isFriend($fromUserId, $toUserId)) {
            return [
                'status'          => 'already_friends',
                'request_id'      => 0,
                'peer_user_id'    => $toUserId,
                'conversation_id' => $this->privateConversationId($fromUserId, $toUserId),
                'auto_accepted'   => true,
            ];
        }
        if (!$this->isDiscoverableBy($toUserId, $fromUserId)) {
            throw new \RuntimeException('user not discoverable');
        }

        $pending = $this->findPendingRequest($fromUserId, $toUserId);
        if ($pending) {
            return [
                'status'          => 'pending',
                'request_id'      => (int)$pending['id'],
                'peer_user_id'    => $toUserId,
                'conversation_id' => $this->privateConversationId($fromUserId, $toUserId),
                'auto_accepted'   => false,
            ];
        }
        // 对方已向我发起申请 → 直接互为好友
        $incoming = $this->findPendingRequest($toUserId, $fromUserId);
        if ($incoming) {
            $result = $this->acceptRequest($fromUserId, (int)$incoming['id']);
            $result['status'] = 'accepted';
            $result['auto_accepted'] = true;
            return $result;
        }

        $now = time();
        // 客服号：自动通过 + 自定义回复
        if (AdminService::isImAdmin($toUserId) && !AdminService::isImAdmin($fromUserId)) {
            $this->ensureRow($fromUserId, $toUserId, $now);
            // 客服侧也建联系，便于后台会话
            $this->ensureRow($toUserId, $fromUserId, $now);
            $reqId = $this->insertRequest($fromUserId, $toUserId, $message, 1, $toUserId, $now);
            $reply = AdminService::csFriendReply($toUserId);
            $msg = null;
            if ($reply !== '') {
                try {
                    $msg = (new MessageService())->sendPrivate($toUserId, $fromUserId, $reply, 1);
                } catch (\Throwable $e) {
                    $msg = null;
                }
            }
            return [
                'status'          => 'accepted',
                'request_id'      => $reqId,
                'peer_user_id'    => $toUserId,
                'conversation_id' => $this->privateConversationId($fromUserId, $toUserId),
                'auto_accepted'   => true,
                'reply'           => $reply,
                'message'         => $msg,
            ];
        }

        $reqId = $this->insertRequest($fromUserId, $toUserId, $message, 0, 0, $now);
        return [
            'status'          => 'pending',
            'request_id'      => $reqId,
            'peer_user_id'    => $toUserId,
            'conversation_id' => $this->privateConversationId($fromUserId, $toUserId),
            'auto_accepted'   => false,
            'from_user'       => $this->userBrief($fromUserId),
            'message'         => $message,
        ];
    }

    /**
     * 通过好友申请（操作者须为被申请人）
     */
    public function acceptRequest($operatorId, $requestId, $sendGreeting = true)
    {
        $operatorId = (int)$operatorId;
        $requestId = (int)$requestId;
        $row = $this->getRequest($requestId);
        if (!$row) {
            throw new \RuntimeException('request not found');
        }
        if ((int)$row['status'] !== 0) {
            throw new \RuntimeException('request already handled');
        }
        if ((int)$row['to_user_id'] !== $operatorId) {
            throw new \RuntimeException('not your request');
        }
        $fromId = (int)$row['from_user_id'];
        $toId = (int)$row['to_user_id'];
        $now = time();
        Db::exec(
            'UPDATE ' . Db::table('chat_friend_requests')
            . ' SET status=1, handle_user_id=?, updatetime=? WHERE id=? AND status=0',
            [$operatorId, $now, $requestId]
        );
        $this->ensureRow($fromId, $toId, $now);
        $this->ensureRow($toId, $fromId, $now);
        $greetingMsg = null;
        if ($sendGreeting) {
            $text = '我们已经是好友了~';
            try {
                $greetingMsg = (new MessageService())->sendPrivate($toId, $fromId, $text, 1);
            } catch (\Throwable $e) {
                $greetingMsg = null;
            }
        }
        return [
            'status'          => 'accepted',
            'request_id'      => $requestId,
            'peer_user_id'    => $fromId,
            'from_user_id'    => $fromId,
            'to_user_id'      => $toId,
            'conversation_id' => $this->privateConversationId($fromId, $toId),
            'greeting'        => $greetingMsg,
            'from_user'       => $this->userBrief($fromId),
            'to_user'         => $this->userBrief($toId),
        ];
    }

    /**
     * 后台代为通过（不校验操作者身份）
     */
    public function acceptRequestByAdmin($requestId, $adminUserId = 0)
    {
        $requestId = (int)$requestId;
        $row = $this->getRequest($requestId);
        if (!$row) {
            throw new \RuntimeException('request not found');
        }
        if ((int)$row['status'] !== 0) {
            throw new \RuntimeException('request already handled');
        }
        return $this->acceptRequest((int)$row['to_user_id'], $requestId, true);
    }

    public function rejectRequest($operatorId, $requestId)
    {
        $operatorId = (int)$operatorId;
        $requestId = (int)$requestId;
        $row = $this->getRequest($requestId);
        if (!$row) {
            throw new \RuntimeException('request not found');
        }
        if ((int)$row['status'] !== 0) {
            throw new \RuntimeException('request already handled');
        }
        if ((int)$row['to_user_id'] !== $operatorId && (int)$row['from_user_id'] !== $operatorId) {
            throw new \RuntimeException('not your request');
        }
        $now = time();
        $status = ((int)$row['from_user_id'] === $operatorId) ? 3 : 2; // 取消 / 拒绝
        Db::exec(
            'UPDATE ' . Db::table('chat_friend_requests')
            . ' SET status=?, handle_user_id=?, updatetime=? WHERE id=? AND status=0',
            [$status, $operatorId, $now, $requestId]
        );
        return [
            'status'       => $status === 3 ? 'cancelled' : 'rejected',
            'request_id'   => $requestId,
            'from_user_id' => (int)$row['from_user_id'],
            'to_user_id'   => (int)$row['to_user_id'],
            'peer_user_id' => ((int)$row['to_user_id'] === $operatorId)
                ? (int)$row['from_user_id']
                : (int)$row['to_user_id'],
        ];
    }

    public function rejectRequestByAdmin($requestId)
    {
        $row = $this->getRequest((int)$requestId);
        if (!$row) {
            throw new \RuntimeException('request not found');
        }
        if ((int)$row['status'] !== 0) {
            throw new \RuntimeException('request already handled');
        }
        $now = time();
        Db::exec(
            'UPDATE ' . Db::table('chat_friend_requests')
            . ' SET status=2, handle_user_id=0, updatetime=? WHERE id=? AND status=0',
            [$now, (int)$requestId]
        );
        return [
            'status'       => 'rejected',
            'request_id'   => (int)$requestId,
            'from_user_id' => (int)$row['from_user_id'],
            'to_user_id'   => (int)$row['to_user_id'],
        ];
    }

    /**
     * 我的申请列表：incoming + outgoing
     */
    public function listRequests($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return ['incoming' => [], 'outgoing' => [], 'pending_count' => 0];
        }
        $rows = Db::fetchAll(
            'SELECT * FROM ' . Db::table('chat_friend_requests')
            . ' WHERE (to_user_id=? OR from_user_id=?) AND status IN (0,1,2,3)'
            . ' ORDER BY id DESC LIMIT 100',
            [$userId, $userId]
        );
        $incoming = [];
        $outgoing = [];
        $pendingCount = 0;
        foreach ($rows as $row) {
            $item = $this->formatRequestRow($row, $userId);
            if ((int)$row['to_user_id'] === $userId) {
                $incoming[] = $item;
                if ((int)$row['status'] === 0) {
                    $pendingCount++;
                }
            } else {
                $outgoing[] = $item;
            }
        }
        return [
            'incoming'      => $incoming,
            'outgoing'      => $outgoing,
            'pending_count' => $pendingCount,
        ];
    }

    public function requestStatusBetween($userId, $peerId)
    {
        $userId = (int)$userId;
        $peerId = (int)$peerId;
        if ($this->isFriend($userId, $peerId)) {
            return 'friends';
        }
        if ($this->findPendingRequest($userId, $peerId)) {
            return 'outgoing_pending';
        }
        if ($this->findPendingRequest($peerId, $userId)) {
            return 'incoming_pending';
        }
        return 'none';
    }

    protected function insertRequest($fromId, $toId, $message, $status, $handleUserId, $now)
    {
        Db::exec(
            'INSERT INTO ' . Db::table('chat_friend_requests')
            . ' (from_user_id,to_user_id,message,status,handle_user_id,createtime,updatetime) VALUES (?,?,?,?,?,?,?)',
            [(int)$fromId, (int)$toId, (string)$message, (int)$status, (int)$handleUserId, (int)$now, (int)$now]
        );
        return Db::lastId();
    }

    protected function getRequest($id)
    {
        $id = (int)$id;
        if ($id <= 0) {
            return null;
        }
        return Db::fetch(
            'SELECT * FROM ' . Db::table('chat_friend_requests') . ' WHERE id=? LIMIT 1',
            [$id]
        ) ?: null;
    }

    protected function findPendingRequest($fromId, $toId)
    {
        return Db::fetch(
            'SELECT * FROM ' . Db::table('chat_friend_requests')
            . ' WHERE from_user_id=? AND to_user_id=? AND status=0 ORDER BY id DESC LIMIT 1',
            [(int)$fromId, (int)$toId]
        ) ?: null;
    }

    protected function formatRequestRow(array $row, $viewerId)
    {
        $fromId = (int)$row['from_user_id'];
        $toId = (int)$row['to_user_id'];
        $peerId = ($fromId === (int)$viewerId) ? $toId : $fromId;
        $statusMap = [0 => 'pending', 1 => 'accepted', 2 => 'rejected', 3 => 'cancelled'];
        $st = (int)$row['status'];
        return [
            'id'            => (int)$row['id'],
            'from_user_id'  => $fromId,
            'to_user_id'    => $toId,
            'peer_user_id'  => $peerId,
            'direction'     => ($toId === (int)$viewerId) ? 'incoming' : 'outgoing',
            'message'       => (string)($row['message'] ?? ''),
            'status'        => $statusMap[$st] ?? 'pending',
            'status_code'   => $st,
            'createtime'    => (int)($row['createtime'] ?? 0),
            'updatetime'    => (int)($row['updatetime'] ?? 0),
            'peer'          => $this->userBrief($peerId),
            'from_user'     => $this->userBrief($fromId),
        ];
    }

    public function lookupByPhone($mobile, $seekerId = 0, $countryDial = '')
    {
        $candidates = $this->mobileCandidates($mobile, $countryDial);
        if (!$candidates) {
            throw new \InvalidArgumentException('mobile required');
        }
        // 库内多为 E.164（如 +8613...），查询时同时匹配去 + 后的数字串
        $digitForms = [];
        foreach ($candidates as $c) {
            $d = preg_replace('/\D+/', '', (string)$c);
            if ($d !== '') {
                $digitForms[] = $d;
            }
        }
        $digitForms = array_values(array_unique($digitForms));
        if (!$digitForms) {
            return null;
        }
        $placeholders = implode(',', array_fill(0, count($digitForms), '?'));
        $row = Db::fetch(
            'SELECT id,nickname,username,mobile,avatar FROM ' . Db::table('user')
            . " WHERE REPLACE(REPLACE(IFNULL(mobile,''), '+', ''), ' ', '') IN ({$placeholders})"
            . ' ORDER BY id ASC LIMIT 1',
            $digitForms
        );
        if (!$row) {
            return null;
        }
        $uid = (int)$row['id'];
        if (!$this->isDiscoverableBy($uid, (int)$seekerId)) {
            return null;
        }
        $nick = trim((string)($row['nickname'] ?: $row['username'] ?: ''));
        if ($nick === '' && !empty($row['mobile'])) {
            $mob = (string)$row['mobile'];
            $nick = strlen($mob) >= 7 ? (substr($mob, 0, 3) . '****' . substr($mob, -4)) : $mob;
        }
        return [
            'user_id'  => $uid,
            'nickname' => $nick !== '' ? $nick : ('ID' . $uid),
            'avatar'   => (string)($row['avatar'] ?? ''),
            'is_im_admin' => AdminService::isImAdmin($uid),
        ];
    }

    /**
     * 按 8 位数字会员 ID 精确查找（不可用昵称等模糊搜索）
     */
    public function lookupByUserId($memberId, $seekerId = 0)
    {
        $raw = preg_replace('/\D+/', '', (string)$memberId);
        if (!preg_match('/^\d{8}$/', $raw)) {
            throw new \InvalidArgumentException('member id must be 8 digits');
        }
        $uid = (int)$raw;
        // 拒绝前导零伪装（如 00000012 ≠ 真实 8 位 ID）
        if ((string)$uid !== $raw) {
            return null;
        }
        $row = Db::fetch(
            'SELECT id,nickname,username,mobile,avatar FROM ' . Db::table('user')
            . ' WHERE id=? LIMIT 1',
            [$uid]
        );
        if (!$row) {
            return null;
        }
        if (!$this->isDiscoverableBy($uid, (int)$seekerId)) {
            return null;
        }
        $nick = trim((string)($row['nickname'] ?: $row['username'] ?: ''));
        if ($nick === '' && !empty($row['mobile'])) {
            $mob = (string)$row['mobile'];
            $nick = strlen($mob) >= 7 ? (substr($mob, 0, 3) . '****' . substr($mob, -4)) : $mob;
        }
        return [
            'user_id'     => $uid,
            'nickname'    => $nick !== '' ? $nick : ('ID' . $uid),
            'avatar'      => (string)($row['avatar'] ?? ''),
            'is_im_admin' => AdminService::isImAdmin($uid),
        ];
    }

    /**
     * 规范化手机号候选（支持区号+本国号码 / E.164）
     * @return string[]
     */
    public function mobileCandidates($mobile, $countryDial = '')
    {
        $mobile = preg_replace('/\D+/', '', (string)$mobile);
        $dial = preg_replace('/\D+/', '', (string)$countryDial);
        $out = [];
        if ($mobile === '') {
            return [];
        }
        $out[] = $mobile;
        if ($dial !== '') {
            $national = ltrim($mobile, '0');
            if ($national !== '') {
                $out[] = $national;
                $out[] = $dial . $national;
                $out[] = '+' . $dial . $national;
            }
            // 输入已带国家码
            if (strpos($mobile, $dial) === 0 && strlen($mobile) > strlen($dial)) {
                $rest = substr($mobile, strlen($dial));
                if ($rest !== '') {
                    $out[] = $rest;
                    $out[] = '+' . $mobile;
                }
            }
            // 中国大陆：86 + 11 位
            if ($dial === '86' && preg_match('/^1\d{10}$/', $national)) {
                $out[] = $national;
                $out[] = '86' . $national;
                $out[] = '+86' . $national;
            }
        } else {
            // 无区号时也兼容已存 +86xxxxxxxxxxx
            if (preg_match('/^1\d{10}$/', $mobile)) {
                $out[] = '86' . $mobile;
                $out[] = '+86' . $mobile;
            }
            if (preg_match('/^86(1\d{10})$/', $mobile, $m)) {
                $out[] = $m[1];
                $out[] = '+' . $mobile;
            }
        }
        return array_values(array_unique(array_filter($out)));
    }

    public function listFriends($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return [];
        }
        $rows = Db::fetchAll(
            'SELECT c.peer_user_id, u.nickname, u.username, u.mobile, u.avatar'
            . ' FROM ' . Db::table('chat_contacts') . ' c'
            . ' INNER JOIN ' . Db::table('user') . ' u ON u.id=c.peer_user_id'
            . ' WHERE c.user_id=? AND c.status=1'
            . ' ORDER BY c.id DESC LIMIT 200',
            [$userId]
        );
        $list = [];
        $ids = [];
        foreach ($rows as $row) {
            $pid = (int)$row['peer_user_id'];
            if ($pid <= 0) {
                continue;
            }
            $ids[] = $pid;
            $nick = trim((string)($row['nickname'] ?: $row['username'] ?: ''));
            if ($nick === '' && !empty($row['mobile'])) {
                $mob = (string)$row['mobile'];
                $nick = strlen($mob) >= 7 ? (substr($mob, 0, 3) . '****' . substr($mob, -4)) : $mob;
            }
            $list[] = [
                'user_id'         => $pid,
                'nickname'        => $nick !== '' ? $nick : ('ID' . $pid),
                'avatar'          => (string)($row['avatar'] ?? ''),
                'online'          => false,
                'is_default_cs'   => AdminService::isDefaultCs($pid),
                'is_im_admin'     => AdminService::isImAdmin($pid),
                'pinned'          => AdminService::isDefaultCs($pid),
                'undeletable'     => AdminService::isDefaultCs($pid),
            ];
        }
        $onlineMap = [];
        foreach (\Im\Support\ConnMap::filterOnlineUserIds($ids) as $oid) {
            $onlineMap[(int)$oid] = true;
        }
        foreach ($list as &$item) {
            $item['online'] = !empty($onlineMap[(int)$item['user_id']]);
        }
        unset($item);
        // 默认客服置顶，其余在线优先
        usort($list, function ($a, $b) {
            $ap = !empty($a['is_default_cs']) ? 1 : 0;
            $bp = !empty($b['is_default_cs']) ? 1 : 0;
            if ($ap !== $bp) {
                return $bp <=> $ap;
            }
            if (!empty($a['online']) === !empty($b['online'])) {
                return 0;
            }
            return !empty($a['online']) ? -1 : 1;
        });
        return $list;
    }

    public function privateConversationId($uidA, $uidB)
    {
        return IdGenerator::privateConversationId($uidA, $uidB);
    }

    /**
     * 两人是否同在开放群（可互看资料/加好友）
     */
    public function shareOpenGroup($userA, $userB)
    {
        $userA = (int)$userA;
        $userB = (int)$userB;
        if ($userA <= 0 || $userB <= 0 || $userA === $userB) {
            return false;
        }
        $m = Db::table('chat_group_members');
        $g = Db::table('chat_groups');
        $row = Db::fetch(
            "SELECT g.id FROM {$m} ma"
            . " INNER JOIN {$m} mb ON mb.group_id=ma.group_id AND mb.user_id=? AND mb.status=1"
            . " INNER JOIN {$g} g ON g.id=ma.group_id"
            . " WHERE ma.user_id=? AND ma.status=1 AND g.status IN (1,3)"
            . " AND (g.privacy_mode='open' OR (IFNULL(g.privacy_mode,'')='' AND IFNULL(g.hide_member_list,1)=0))"
            . ' LIMIT 1',
            [$userB, $userA]
        );
        return (bool)$row;
    }

    /**
     * 隐私群成员不可被陌生人通过全局搜索发现；
     * 同开放群成员可发现并可加好友。
     */
    public function isDiscoverableBy($targetId, $seekerId)
    {
        $targetId = (int)$targetId;
        $seekerId = (int)$seekerId;
        if ($targetId <= 0 || $seekerId <= 0 || $targetId === $seekerId) {
            return false;
        }
        // 手机号 / 会员 ID 精确查找属于主动搜索，不受群隐私模式影响。
        // privacy_mode / hide_member_list 只约束群内成员列表展示。
        return true;
    }

    public function userBrief($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return null;
        }
        $row = Db::fetch(
            'SELECT id,nickname,username,mobile,avatar FROM ' . Db::table('user') . ' WHERE id=? LIMIT 1',
            [$userId]
        );
        if (!$row) {
            return null;
        }
        $nick = trim((string)($row['nickname'] ?: $row['username'] ?: ''));
        if ($nick === '' && !empty($row['mobile'])) {
            $mob = (string)$row['mobile'];
            $nick = strlen($mob) >= 7 ? (substr($mob, 0, 3) . '****' . substr($mob, -4)) : $mob;
        }
        return [
            'user_id'     => $userId,
            'nickname'    => $nick !== '' ? $nick : ('ID' . $userId),
            'avatar'      => (string)($row['avatar'] ?? ''),
            'is_im_admin' => AdminService::isImAdmin($userId),
        ];
    }

    protected function ensureRow($userId, $peerId, $now = null)
    {
        $now = $now ?: time();
        $existing = Db::fetch(
            'SELECT id,status FROM ' . Db::table('chat_contacts')
            . ' WHERE user_id=? AND peer_user_id=? LIMIT 1',
            [(int)$userId, (int)$peerId]
        );
        if ($existing) {
            if ((int)$existing['status'] !== 1) {
                Db::exec(
                    'UPDATE ' . Db::table('chat_contacts') . ' SET status=1 WHERE id=?',
                    [(int)$existing['id']]
                );
            }
            return;
        }
        Db::exec(
            'INSERT INTO ' . Db::table('chat_contacts')
            . ' (user_id,peer_user_id,status,createtime) VALUES (?,?,1,?)',
            [(int)$userId, (int)$peerId, $now]
        );
    }
}
