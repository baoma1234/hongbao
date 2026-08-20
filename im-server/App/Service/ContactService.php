<?php

namespace Im\Service;

use Im\Support\CatchLog;

use Im\Support\Db;
use Im\Support\IdGenerator;
use Im\Support\RedisClient;

class ContactService
{
    /** 好友关系短缓存；ensureRow 建好好友后立即失效 */
    const FRIEND_CACHE_TTL = 30;

    /** 普通用户互加好友后的默认问候语 */
    const FRIEND_GREETING = '您好~我们已经是好友了~';

    public function isFriend($userId, $peerId)
    {
        $userId = (int)$userId;
        $peerId = (int)$peerId;
        if ($userId <= 0 || $peerId <= 0 || $userId === $peerId) {
            return false;
        }
        $cacheKey = RedisClient::key('friend:' . $userId . ':' . $peerId);
        try {
            $cached = RedisClient::conn()->get($cacheKey);
            if ($cached === '1') {
                return true;
            }
            if ($cached === '0') {
                return false;
            }
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.ContactService');
        }
        $row = Db::fetch(
            'SELECT id FROM ' . Db::table('chat_contacts')
            . ' WHERE user_id=? AND peer_user_id=? AND status=1 LIMIT 1',
            [$userId, $peerId]
        );
        $ok = (bool)$row;
        try {
            RedisClient::conn()->setex($cacheKey, self::FRIEND_CACHE_TTL, $ok ? '1' : '0');
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.ContactService');
        }
        return $ok;
    }

    /** 好友关系变更后清双向短缓存 */
    protected function invalidateFriendCache($userId, $peerId)
    {
        $userId = (int)$userId;
        $peerId = (int)$peerId;
        if ($userId <= 0 || $peerId <= 0) {
            return;
        }
        try {
            RedisClient::conn()->del(
                RedisClient::key('friend:' . $userId . ':' . $peerId),
                RedisClient::key('friend:' . $peerId . ':' . $userId)
            );
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.ContactService');
        }
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
                'peer'            => $this->userBrief($toUserId),
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
                'peer'            => $this->userBrief($toUserId),
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
            'peer'            => $this->userBrief($toUserId),
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
            try {
                // Trusted：避免好友短缓存仍为 0 时 assertCanPrivateChat 拦掉问候语
                $greetingMsg = (new MessageService())->sendPrivateTrusted(
                    $toId,
                    $fromId,
                    self::FRIEND_GREETING,
                    1
                );
            } catch (\Throwable $e) {
                error_log('[FRIEND] greeting fail req=' . $requestId . ' ' . $e->getMessage());
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
        $rawDigits = preg_replace('/\D+/', '', (string)$mobile);
        $candidates = $this->mobileCandidates($mobile, $countryDial);
        // 区号选错时仍要能查到：合并无区号启发式 + 全部支持区号候选
        $candidates = array_merge($candidates, $this->mobileCandidates($mobile, ''));
        foreach ($this->supportedCountryDials() as $d) {
            if ($d === '' || $d === preg_replace('/\D+/', '', (string)$countryDial)) {
                continue;
            }
            $candidates = array_merge($candidates, $this->mobileCandidates($mobile, $d));
        }
        $candidates = array_values(array_unique(array_filter($candidates)));
        if (!$candidates && $rawDigits === '') {
            throw new \InvalidArgumentException('mobile required');
        }
        // 库内多为 E.164（如 +8613...），查询时同时匹配去 + 后的数字串
        $digitForms = [];
        foreach ($candidates as $c) {
            $d = preg_replace('/\D+/', '', (string)$c);
            if ($d !== '' && strlen($d) >= 6 && strlen($d) <= 18) {
                $digitForms[] = $d;
            }
        }
        if ($rawDigits !== '' && strlen($rawDigits) >= 6) {
            $digitForms[] = $rawDigits;
        }
        $digitForms = array_values(array_unique($digitForms));
        if (!$digitForms) {
            return null;
        }
        $placeholders = implode(',', array_fill(0, count($digitForms), '?'));
        $normExpr = "REPLACE(REPLACE(IFNULL(mobile,''), '+', ''), ' ', '')";
        $row = Db::fetch(
            'SELECT id,nickname,username,mobile,avatar FROM ' . Db::table('user')
            . " WHERE {$normExpr} IN ({$placeholders})"
            . ' ORDER BY id ASC LIMIT 1',
            $digitForms
        );
        // 兜底：输入本国号时，用「规范化手机号后缀」匹配（避免区号选错查不到）
        if (!$row && $rawDigits !== '' && strlen($rawDigits) >= 8 && strlen($rawDigits) <= 15) {
            $row = Db::fetch(
                'SELECT id,nickname,username,mobile,avatar FROM ' . Db::table('user')
                . " WHERE {$normExpr} = ? OR {$normExpr} LIKE ?"
                . ' ORDER BY id ASC LIMIT 1',
                [$rawDigits, '%' . $rawDigits]
            );
            // 后缀命中时要求「整段国家码+本国号」长度合理，降低误匹配
            if ($row) {
                $norm = preg_replace('/\D+/', '', (string)($row['mobile'] ?? ''));
                $ok = ($norm === $rawDigits)
                    || (strlen($norm) >= strlen($rawDigits) + 1
                        && strlen($norm) <= strlen($rawDigits) + 4
                        && substr($norm, -strlen($rawDigits)) === $rawDigits);
                if (!$ok) {
                    $row = null;
                }
            }
        }
        if (!$row) {
            return null;
        }
        $uid = (int)$row['id'];
        // 自己：交给上层返回「不能添加自己」，不要伪装成未找到
        if ((int)$seekerId > 0 && $uid !== (int)$seekerId && !$this->isDiscoverableBy($uid, (int)$seekerId)) {
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
        if ((int)$seekerId > 0 && $uid !== (int)$seekerId && !$this->isDiscoverableBy($uid, (int)$seekerId)) {
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

    /** H5 支持的国家区号（与 public/888/i18n/countries.js 对齐） */
    protected function supportedCountryDials()
    {
        return ['86', '63', '84', '60', '855', '62'];
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
            // 菲律宾：63 + 10 位（9xxxxxxxxx）
            if ($dial === '63' && preg_match('/^9\d{9}$/', $national)) {
                $out[] = $national;
                $out[] = '63' . $national;
                $out[] = '+63' . $national;
            }
        } else {
            // 无区号时也兼容已存 +86xxxxxxxxxxx / +63xxxxxxxxxx
            if (preg_match('/^1\d{10}$/', $mobile)) {
                $out[] = '86' . $mobile;
                $out[] = '+86' . $mobile;
            }
            if (preg_match('/^86(1\d{10})$/', $mobile, $m)) {
                $out[] = $m[1];
                $out[] = '+' . $mobile;
            }
            if (preg_match('/^9\d{9}$/', $mobile)) {
                $out[] = '63' . $mobile;
                $out[] = '+63' . $mobile;
            }
            if (preg_match('/^63(9\d{9})$/', $mobile, $m)) {
                $out[] = $m[1];
                $out[] = '+' . $mobile;
            }
            // 粘贴完整 E.164 数字（去 + 后）
            foreach ($this->supportedCountryDials() as $d) {
                if (strpos($mobile, $d) === 0 && strlen($mobile) > strlen($d) + 6) {
                    $rest = substr($mobile, strlen($d));
                    $out[] = $rest;
                    $out[] = '+' . $mobile;
                }
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
        $remarks = $this->remarksMap($userId, $ids);
        foreach ($list as &$item) {
            $pid = (int)$item['user_id'];
            $remark = isset($remarks[$pid]) ? (string)$remarks[$pid] : '';
            $item['remark'] = $remark;
            $item['peer_nickname'] = (string)$item['nickname'];
            if ($remark !== '') {
                $item['nickname'] = $remark; // 好友列表优先显示备注
            }
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

    /**
     * 我对若干对方的备注映射 peer_user_id => remark
     * @param int[] $peerIds
     * @return array<int,string>
     */
    public function remarksMap($userId, array $peerIds)
    {
        $userId = (int)$userId;
        $ids = [];
        foreach ($peerIds as $pid) {
            $pid = (int)$pid;
            if ($pid > 0 && $pid !== $userId) {
                $ids[$pid] = $pid;
            }
        }
        if ($userId <= 0 || !$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$userId], array_values($ids));
        $rows = Db::fetchAll(
            'SELECT peer_user_id, remark FROM ' . Db::table('chat_user_remarks')
            . ' WHERE user_id=? AND peer_user_id IN (' . $placeholders . ')'
            . " AND remark<>''",
            $params
        );
        $map = [];
        foreach ($rows as $row) {
            $pid = (int)$row['peer_user_id'];
            $r = trim((string)($row['remark'] ?? ''));
            if ($pid > 0 && $r !== '') {
                $map[$pid] = $r;
            }
        }
        return $map;
    }

    public function getRemark($userId, $peerId)
    {
        $userId = (int)$userId;
        $peerId = (int)$peerId;
        if ($userId <= 0 || $peerId <= 0 || $userId === $peerId) {
            return '';
        }
        $row = Db::fetch(
            'SELECT remark FROM ' . Db::table('chat_user_remarks')
            . ' WHERE user_id=? AND peer_user_id=? LIMIT 1',
            [$userId, $peerId]
        );
        return $row ? trim((string)($row['remark'] ?? '')) : '';
    }

    /**
     * 设置/清除我对对方的备注（最多 32 字；空串清除）
     * @return array{peer_user_id:int,remark:string,peer_nickname:string,title:string}
     */
    public function setRemark($userId, $peerId, $remark)
    {
        $userId = (int)$userId;
        $peerId = (int)$peerId;
        $remark = mb_substr(trim((string)$remark), 0, 32);
        if ($userId <= 0 || $peerId <= 0 || $userId === $peerId) {
            throw new \InvalidArgumentException('invalid user');
        }
        $brief = $this->userBrief($peerId);
        if (!$brief) {
            throw new \RuntimeException('user not found');
        }
        $now = time();
        $existing = Db::fetch(
            'SELECT id FROM ' . Db::table('chat_user_remarks')
            . ' WHERE user_id=? AND peer_user_id=? LIMIT 1',
            [$userId, $peerId]
        );
        if ($remark === '') {
            if ($existing) {
                Db::exec(
                    'DELETE FROM ' . Db::table('chat_user_remarks') . ' WHERE id=?',
                    [(int)$existing['id']]
                );
            }
        } elseif ($existing) {
            Db::exec(
                'UPDATE ' . Db::table('chat_user_remarks')
                . ' SET remark=?, updatetime=? WHERE id=?',
                [$remark, $now, (int)$existing['id']]
            );
        } else {
            Db::exec(
                'INSERT INTO ' . Db::table('chat_user_remarks')
                . ' (user_id,peer_user_id,remark,createtime,updatetime) VALUES (?,?,?,?,?)',
                [$userId, $peerId, $remark, $now, $now]
            );
        }
        $this->bustConvListCache($userId);
        $nick = (string)($brief['nickname'] ?? ('ID' . $peerId));
        return [
            'peer_user_id'   => $peerId,
            'remark'         => $remark,
            'peer_nickname'  => $nick,
            'title'          => $remark !== '' ? $remark : $nick,
        ];
    }

    protected function bustConvListCache($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return;
        }
        try {
            $redis = \Im\Support\RedisClient::conn();
            foreach ([20, 50, 100] as $lim) {
                $redis->del(\Im\Support\RedisClient::key('convlist:' . $userId . ':' . $lim));
            }
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.ContactService');
        }
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
        $userId = (int)$userId;
        $peerId = (int)$peerId;
        $existing = Db::fetch(
            'SELECT id,status FROM ' . Db::table('chat_contacts')
            . ' WHERE user_id=? AND peer_user_id=? LIMIT 1',
            [$userId, $peerId]
        );
        if ($existing) {
            if ((int)$existing['status'] !== 1) {
                Db::exec(
                    'UPDATE ' . Db::table('chat_contacts') . ' SET status=1 WHERE id=?',
                    [(int)$existing['id']]
                );
            }
            $this->warmFriendCache($userId, $peerId, true);
            return;
        }
        Db::exec(
            'INSERT INTO ' . Db::table('chat_contacts')
            . ' (user_id,peer_user_id,status,createtime) VALUES (?,?,1,?)',
            [$userId, $peerId, $now]
        );
        $this->warmFriendCache($userId, $peerId, true);
    }

    /** 清短缓存并写成好友=1，避免紧随其后的私聊鉴权误判 */
    protected function warmFriendCache($userId, $peerId, $isFriend = true)
    {
        $userId = (int)$userId;
        $peerId = (int)$peerId;
        if ($userId <= 0 || $peerId <= 0) {
            return;
        }
        $flag = $isFriend ? '1' : '0';
        try {
            $r = RedisClient::conn();
            $r->setex(RedisClient::key('friend:' . $userId . ':' . $peerId), self::FRIEND_CACHE_TTL, $flag);
            $r->setex(RedisClient::key('friend:' . $peerId . ':' . $userId), self::FRIEND_CACHE_TTL, $flag);
        } catch (\Throwable $e) {
            $this->invalidateFriendCache($userId, $peerId);
        }
    }
}
