<?php

namespace Im\Handler;

use Im\Service\AuthService;
use Im\Service\AdminService;
use Im\Service\ChatForbidService;
use Im\Service\ContactService;
use Im\Service\GroupService;
use Im\Service\MessageService;
use Im\Service\RedPacketService;
use Im\Support\ConnMap;
use Im\Support\IdGenerator;
use Im\Support\PushBus;
use Im\Support\RedisClient;
use Im\Support\AdminNotify;
use Im\Support\NotifyDispatcher;
use Im\Support\GrabGuard;
use Workerman\Connection\TcpConnection;

class MessageRouter
{
    /** @var AuthService */
    protected $auth;
    /** @var MessageService */
    protected $messages;
    /** @var GroupService */
    protected $groups;
    /** @var RedPacketService */
    protected $redPackets;
    /** @var ContactService */
    protected $contacts;
    /** @var \Workerman\Worker */
    protected $worker;
    /** @var array */
    protected $cfg;

    public function __construct($worker, AuthService $auth, MessageService $messages, GroupService $groups, RedPacketService $redPackets, array $cfg = [])
    {
        $this->worker = $worker;
        $this->auth = $auth;
        $this->messages = $messages;
        $this->groups = $groups;
        $this->redPackets = $redPackets;
        $this->contacts = new ContactService();
        $this->cfg = $cfg;
    }

    public function onConnect(TcpConnection $connection)
    {
        $this->send($connection, 'hello', [
            'server' => 'FansHubIM',
            'ts'     => time(),
        ]);
    }

    public function onClose(TcpConnection $connection)
    {
        ConnMap::unbindConn((string)$connection->id);
    }

    public function onMessage(TcpConnection $connection, $raw)
    {
        $data = json_decode((string)$raw, true);
        if (!is_array($data) || empty($data['type'])) {
            $this->error($connection, 'bad_packet');
            return;
        }
        $type = (string)$data['type'];
        $payload = isset($data['data']) && is_array($data['data']) ? $data['data'] : [];
        $reqId = isset($data['req_id']) ? (string)$data['req_id'] : '';

        try {
            if ($type === 'internal.notify') {
                $this->handleInternalNotify($connection, $payload);
                return;
            }
            if ($type === 'auth') {
                $this->handleAuth($connection, $payload, $reqId);
                return;
            }
            $uid = ConnMap::userIdOf((string)$connection->id);
            if ($uid <= 0) {
                $this->error($connection, 'unauthorized', $reqId);
                return;
            }
            switch ($type) {
                case 'ping':
                    ConnMap::touchUser($uid);
                    $this->send($connection, 'pong', ['ts' => time()], $reqId);
                    break;
                case 'private.send':
                    $this->handlePrivateSend($connection, $uid, $payload, $reqId);
                    break;
                case 'group.send':
                    $this->handleGroupSend($connection, $uid, $payload, $reqId);
                    break;
                case 'history':
                    $this->handleHistory($connection, $uid, $payload, $reqId);
                    break;
                case 'group.create':
                    $this->handleGroupCreate($connection, $uid, $payload, $reqId);
                    break;
                case 'group.list':
                    $this->send($connection, 'group.list', ['list' => $this->groups->myGroups($uid)], $reqId);
                    break;
                case 'group.recommend':
                    $this->send($connection, 'group.recommend', [
                        'list' => $this->groups->recommendGroups($uid),
                    ], $reqId);
                    break;
                case 'group.join':
                    $this->handleGroupJoin($connection, $uid, $payload, $reqId);
                    break;
                case 'group.members':
                    $this->handleGroupMembers($connection, $uid, $payload, $reqId);
                    break;
                case 'group.kick':
                    $this->handleGroupKick($connection, $uid, $payload, $reqId);
                    break;
                case 'group.mute':
                    $this->handleGroupMute($connection, $uid, $payload, $reqId);
                    break;
                case 'group.set_admin':
                    $this->handleGroupSetAdmin($connection, $uid, $payload, $reqId);
                    break;
                case 'group.mute_all':
                    $this->handleGroupMuteAll($connection, $uid, $payload, $reqId);
                    break;
                case 'group.candidates':
                    $this->handleGroupCandidates($connection, $uid, $payload, $reqId);
                    break;
                case 'group.add_members':
                    $this->handleGroupAddMembers($connection, $uid, $payload, $reqId);
                    break;
                case 'group.info':
                    $this->handleGroupInfo($connection, $uid, $payload, $reqId);
                    break;
                case 'group.update':
                    $this->handleGroupUpdate($connection, $uid, $payload, $reqId);
                    break;
                case 'conversation.list':
                    $this->handleConversationList($connection, $uid, $payload, $reqId);
                    break;
                case 'conversation.read':
                    $this->handleConversationRead($connection, $uid, $payload, $reqId);
                    break;
                case 'redpacket.send':
                    $this->handleRedSend($connection, $uid, $payload, $reqId);
                    break;
                case 'redpacket.grab':
                    $this->handleRedGrab($connection, $uid, $payload, $reqId);
                    break;
                case 'redpacket.detail':
                    try {
                        $detail = $this->redPackets->detail((int)($payload['packet_id'] ?? 0), $uid);
                        $this->send($connection, 'redpacket.detail', $detail ?: new \stdClass(), $reqId);
                    } catch (\RuntimeException $e) {
                        $this->error($connection, $e->getMessage() ?: 'forbidden', $reqId);
                    }
                    break;
                case 'user.brief':
                    $this->handleUserBrief($connection, $uid, $payload, $reqId);
                    break;
                case 'message.recall':
                    $this->handleRecall($connection, $uid, $payload, $reqId);
                    break;
                case 'friend.lookup':
                    $this->handleFriendLookup($connection, $uid, $payload, $reqId);
                    break;
                case 'friend.add':
                case 'friend.request':
                    $this->handleFriendAdd($connection, $uid, $payload, $reqId);
                    break;
                case 'friend.accept':
                    $this->handleFriendAccept($connection, $uid, $payload, $reqId);
                    break;
                case 'friend.reject':
                case 'friend.cancel':
                    $this->handleFriendReject($connection, $uid, $payload, $reqId);
                    break;
                case 'friend.requests':
                    $this->send($connection, 'friend.requests', $this->contacts->listRequests($uid), $reqId);
                    break;
                case 'friend.list':
                    $this->send($connection, 'friend.list', [
                        'list' => $this->contacts->listFriends($uid),
                    ], $reqId);
                    break;
                default:
                    $this->error($connection, 'unknown_type', $reqId);
            }
        } catch (\Throwable $e) {
            $this->error($connection, $this->friendlyError($e), $reqId);
        }
    }

    protected function friendlyError(\Throwable $e)
    {
        $msg = $e->getMessage();
        if ($msg === 'insufficient balance') {
            return '余额不足，请先闪兑凑够红利余额';
        }
        if ($msg === 'balance_not_enough_for_compensate' || strpos($msg, 'balance_not_enough_for_compensate:') === 0) {
            // 原样返回错误码，由 H5 多语言文案 chat_rp_grab_need_compensate 展示
            return $msg;
        }
        if ($msg === 'balance_below_mine_min') {
            return '余额须大于本群最低金额限制，才能领取扫雷红包';
        }
        if ($msg === 'mine_hash_pending') {
            return 'mine_hash_pending';
        }
        if ($msg === 'mine count must be 5, 7 or 9') {
            return '扫雷红包个数仅可选 5 / 7 / 9';
        }
        if ($msg === 'account frozen') {
            return '账户已冻结';
        }
        if ($msg === 'private chat only with admin' || $msg === 'private chat only with admin or friend') {
            return '只能与客服或好友私聊，请先通过好友申请';
        }
        if ($msg === 'request not found') {
            return '申请不存在';
        }
        if ($msg === 'request already handled') {
            return '该申请已处理';
        }
        if ($msg === 'not your request') {
            return '无权处理该申请';
        }
        if ($msg === 'user not discoverable') {
            return '无法添加该用户';
        }
        if ($msg === 'group create disabled') {
            return '群聊请联系客服，暂不支持自行建群';
        }
        if ($msg === 'you are muted') {
            return '你已被禁言，暂时无法发言';
        }
        if ($msg === 'group muted') {
            return '全员禁言中，仅管理员可发言';
        }
        if ($msg === 'private group: mention disabled') {
            return '隐私群禁止 @ 其他成员';
        }
        if ($msg === 'grab mode: only admin can send red packets') {
            return '红宝模式下仅管理员/机器人可发红包';
        }
        if (preg_match('/amount below.*?(\d+(?:\.\d+)?)/', $msg, $m)) {
            return '红包金额过低（最低 ' . $m[1] . ' 元）';
        }
        if (strpos($msg, 'count must be') === 0) {
            return '红包个数须为 ' . trim(substr($msg, strlen('count must be')));
        }
        if ($msg === 'mine_digit must be 0-9') {
            return '雷号须为 0～9';
        }
        if ($msg === 'too many packets' || $msg === 'amount too small' || $msg === 'amount too small after fee') {
            return '红包金额或个数不符合规则';
        }
        if ($msg === 'packet type not allowed in this group') {
            return '当前群不允许该红包类型';
        }
        if ($msg === 'invalid packet_type' || $msg === 'invalid red packet params') {
            return '红包参数无效';
        }
        if ($msg === 'user not discoverable') {
            return '该用户设置了隐私保护，无法添加';
        }
        if ($msg === 'profile locked') {
            return '隐私群已锁定资料查看';
        }
        if ($msg === 'no permission') {
            return '无操作权限';
        }
        if ($msg === 'owner only') {
            return '仅群主可操作';
        }
        if ($msg === 'cannot operate self') {
            return '不能对自己执行该操作';
        }
        if ($msg === 'target not in group') {
            return '该用户不在群内';
        }
        if ($msg === 'cannot change owner') {
            return '不能修改群主角色';
        }
        if ($msg === 'group unavailable') {
            return '群组不可用';
        }
        if ($msg === 'slider_required') {
            return '请拖动滑块完成安全验证后再抢';
        }
        if ($msg === 'already grabbed') {
            return '你已经抢过这个红包了';
        }
        if ($msg === 'packet empty') {
            return '手慢了，红包已被抢完';
        }
        if ($msg === 'packet expired') {
            return '红包已过期';
        }
        if ($msg === 'packet closed') {
            return '红包已结束';
        }
        if ($msg === 'packet not found') {
            return '红包不存在';
        }
        if ($msg === 'not in group') {
            return '你不在该群内';
        }
        if ($msg === 'empty members') {
            return '请先选择要添加的成员';
        }
        if ($msg === 'message not found') {
            return '消息不存在';
        }
        if ($msg === 'cannot recall') {
            return '该消息无法撤回';
        }
        if ($msg === 'recall expired') {
            return '超过2分钟，无法撤回';
        }
        if (isset(ChatForbidService::LABELS[$msg]) || strpos($msg, '禁止') === 0) {
            return $msg;
        }
        foreach (ChatForbidService::LABELS as $label) {
            if ($msg === $label) {
                return $label;
            }
        }
        return $msg;
    }

    protected function handleAuth(TcpConnection $connection, array $payload, $reqId)
    {
        $token = (string)($payload['token'] ?? '');
        $userId = $this->auth->userIdByToken($token);
        if ($userId <= 0) {
            $this->error($connection, 'auth_failed', $reqId);
            return;
        }
        ConnMap::bind((string)$connection->id, $userId);
        $fp = strtolower(trim((string)($payload['device_fp'] ?? '')));
        if ($fp !== '') {
            $connection->deviceFp = substr($fp, 0, 64);
        }
        $brief = $this->auth->userBrief($userId);
        $this->send($connection, 'auth.ok', [
            'user_id'     => $userId,
            'user'        => $brief,
            'is_im_admin' => AdminService::isImAdmin($userId),
            'can_create_group' => $this->memberCanCreateGroup($userId),
        ], $reqId);
    }

    protected function handlePrivateSend(TcpConnection $connection, $uid, array $payload, $reqId)
    {
        $to = (int)($payload['to_user_id'] ?? 0);
        $content = (string)($payload['content'] ?? '');
        $msgType = (int)($payload['msg_type'] ?? 1);
        $extra = isset($payload['extra']) ? $payload['extra'] : null;
        ChatForbidService::assertCanSendMessage($uid, $msgType);
        $msg = $this->messages->sendPrivate($uid, $to, $content, $msgType, $extra);
        $this->send($connection, 'private.ack', ['message' => $msg], $reqId);
        $this->pushToUser($to, 'private.message', ['message' => $msg]);
        // 自己多端同步
        $this->pushToUser($uid, 'private.message', ['message' => $msg], (string)$connection->id);
        if ((int)$msgType === 7) {
            $this->notifyAdminsOnly('private.message', $msg);
        }
    }

    protected function handleGroupSend(TcpConnection $connection, $uid, array $payload, $reqId)
    {
        $groupId = (int)($payload['group_id'] ?? 0);
        $content = (string)($payload['content'] ?? '');
        $msgType = (int)($payload['msg_type'] ?? 1);
        $extra = isset($payload['extra']) ? $payload['extra'] : null;
        ChatForbidService::assertCanSendMessage($uid, $msgType);

        // 群聊文本快捷发红包：金额/数量/雷号 → 埋雷；金额/数量 → 拼手气（不落文本命令）
        if ($msgType === 1) {
            $trim = trim($content);
            if (preg_match('/^(\d+(?:\.\d+)?)[\/\-](\d+)[\/\-](\d)$/', $trim, $m)) {
                // 金额/个数/雷号 → 埋雷红包（雷号手填；开奖匹配哈希末位）
                $this->sendRedPacketFromChatParse($connection, $uid, $groupId, [
                    'packet_type'  => 3,
                    'total_amount' => (float)$m[1],
                    'total_count'  => (int)$m[2],
                    'mine_digit'   => (int)$m[3],
                ], $reqId);
                return;
            }
            if (preg_match('/^(\d+(?:\.\d+)?)[\/\-](\d+)$/', $trim, $m)) {
                $this->sendRedPacketFromChatParse($connection, $uid, $groupId, [
                    'packet_type'  => 2,
                    'total_amount' => (float)$m[1],
                    'total_count'  => (int)$m[2],
                ], $reqId);
                return;
            }
        }

        if ($msgType === 1 && $content !== '' && preg_match('/[@＠]/u', $content)) {
            $group = $this->groups->get($groupId) ?: [];
            $role = $this->groups->memberRole($groupId, $uid);
            if (!$this->groups->buildPolicy($group, $role)['can_mention']) {
                throw new \RuntimeException('private group: mention disabled');
            }
        }
        $msg = $this->messages->sendGroup($uid, $groupId, $content, $msgType, $extra);
        $this->send($connection, 'group.ack', ['message' => $msg], $reqId);
        $this->pushToGroup($groupId, 'group.message', ['message' => $msg]);
        if ((int)$msgType === 7) {
            $this->notifyAdminsOnly('group.message', $msg);
        }
    }

    /**
     * 群聊文本解析出发红包：成功推送红包消息；失败走 error（H5 toast）
     */
    protected function sendRedPacketFromChatParse(TcpConnection $connection, $uid, $groupId, array $rp, $reqId)
    {
        ChatForbidService::assertCanSendRedPacket($uid);
        $payload = array_merge($rp, [
            'from_user_id' => (int)$uid,
            'scope_type'   => 2,
            'group_id'     => (int)$groupId,
            'blessing'     => '恭喜发财',
        ]);
        $result = $this->redPackets->send($payload);
        // 用 redpacket.sent 回填 req，data.message 供 H5 sendPayload 落本地气泡
        $this->send($connection, 'redpacket.sent', $result, $reqId);
        $msg = $result['message'] ?? null;
        if (is_array($msg)) {
            $this->pushToGroup((int)$groupId, 'group.message', ['message' => $msg]);
        }
        PushBus::drainOwnQueue(200);
    }

    protected function handleRecall(TcpConnection $connection, $uid, array $payload, $reqId)
    {
        $messageId = (int)($payload['message_id'] ?? $payload['id'] ?? 0);
        $msg = $this->messages->recall($messageId, $uid);
        $this->send($connection, 'message.recalled', ['message' => $msg], $reqId);
        // 会话内同步撤回状态
        if ((int)$msg['conversation_type'] === 2) {
            $this->pushToGroup((int)$msg['group_id'], 'message.recalled', ['message' => $msg]);
        } else {
            $this->pushToUser((int)$msg['from_user_id'], 'message.recalled', ['message' => $msg]);
            $this->pushToUser((int)$msg['to_user_id'], 'message.recalled', ['message' => $msg]);
        }
        // 额外通知只送达后台代聊
        $this->notifyAdminsOnly('message.recalled', $msg);
    }

    /**
     * 仅通知托管账号（后台代聊在线端）+ notify_queue
     */
    protected function notifyAdminsOnly($type, array $message)
    {
        AdminNotify::publish($type, ['message' => $message]);
        foreach (AdminService::adminUserIds() as $adminId) {
            // 避免与会话推送重复打扰：若管理员已在会话推送路径中，PushBus 仍会去重投递同一事件类型
            // 这里用 admin.notify 专供后台角标/提示
            $this->pushToUser($adminId, 'admin.notify', [
                'event'   => $type,
                'message' => $message,
            ]);
        }
    }

    protected function handleHistory(TcpConnection $connection, $uid, array $payload, $reqId)
    {
        $ctype = (int)($payload['conversation_type'] ?? 1);
        $cid = (string)($payload['conversation_id'] ?? '');
        if ($cid === '' && $ctype === 1) {
            $other = (int)($payload['to_user_id'] ?? 0);
            $cid = IdGenerator::privateConversationId($uid, $other);
        }
        $gid = 0;
        if ($ctype === 2) {
            $gid = (int)($payload['group_id'] ?? $cid);
            if (!$this->groups->isMember($gid, $uid)) {
                throw new \RuntimeException('not in group');
            }
            $cid = (string)$gid;
        } elseif ($ctype === 1) {
            // 私聊历史：必须是会话双方之一，禁止 IDOR 窥探他人私聊
            if (!$this->canAccessPrivateConversation((int)$uid, $cid)) {
                throw new \RuntimeException('forbidden');
            }
        }
        $list = $this->messages->history($ctype, $cid, (int)($payload['before_id'] ?? 0), (int)($payload['limit'] ?? 30));
        $list = $this->redPackets->enrichMessageExtras($list, (int)$uid);
        $data = ['list' => $list];
        // 群聊首屏附带 group.info，省去客户端二次请求
        if ($ctype === 2 && $gid > 0) {
            $data = array_merge($data, $this->buildGroupInfoPayload($gid, $uid));
        }
        $this->send($connection, 'history', $data, $reqId);
    }

    /**
     * @return array{group:mixed,my_role:int,mute_all:bool,member_count:int,member_list_hidden:bool,can_speak:bool,policy:array}
     */
    protected function buildGroupInfoPayload($groupId, $uid)
    {
        $groupId = (int)$groupId;
        $uid = (int)$uid;
        $group = $this->groups->get($groupId);
        if ($group && !empty($group['notice_i18n']) && is_string($group['notice_i18n'])) {
            $map = json_decode($group['notice_i18n'], true);
            $group['notice_i18n'] = is_array($map) ? $map : new \stdClass();
        } elseif ($group) {
            $group['notice_i18n'] = new \stdClass();
        }
        $myRole = $this->groups->memberRole($groupId, $uid);
        $policy = $this->groups->buildPolicy($group ?: [], $myRole);
        return [
            'group'              => $group,
            'my_role'            => $myRole,
            'mute_all'           => $this->groups->isMuteAll($groupId),
            'member_count'       => $this->groups->publicMemberCount($group ?: []),
            'member_list_hidden' => !empty($policy['member_list_hidden']),
            'can_speak'          => $this->canSpeakSafe($groupId, $uid),
            'policy'             => $policy,
        ];
    }

    /**
     * 私聊会话键为 uidA_uidB，当前用户必须是双方之一。
     */
    protected function canAccessPrivateConversation($uid, $conversationId)
    {
        $uid = (int)$uid;
        if ($uid <= 0 || !preg_match('/^(\d+)_(\d+)$/', trim((string)$conversationId), $m)) {
            return false;
        }
        $a = (int)$m[1];
        $b = (int)$m[2];
        if ($a <= 0 || $b <= 0 || $a === $b) {
            return false;
        }
        return $uid === $a || $uid === $b;
    }

    protected function handleGroupCreate(TcpConnection $connection, $uid, array $payload, $reqId)
    {
        if (!$this->memberCanCreateGroup($uid)) {
            throw new \RuntimeException('group create disabled');
        }
        $name = (string)($payload['name'] ?? '');
        $members = isset($payload['member_ids']) && is_array($payload['member_ids']) ? $payload['member_ids'] : [];
        $group = $this->groups->create($uid, $name, $members, [], [
            'privacy_mode' => (string)($payload['privacy_mode'] ?? 'private'),
            'chat_mode'    => (string)($payload['chat_mode'] ?? 'chat'),
        ]);
        $this->send($connection, 'group.created', ['group' => $group], $reqId);
        foreach ($this->groups->memberUserIds((int)$group['id']) as $memberId) {
            $this->pushToUser($memberId, 'group.created', ['group' => $group]);
        }
    }

    protected function handleGroupInfo(TcpConnection $connection, $uid, array $payload, $reqId)
    {
        $groupId = (int)($payload['group_id'] ?? 0);
        if (!$this->groups->isMember($groupId, $uid)) {
            throw new \RuntimeException('not in group');
        }
        $this->send($connection, 'group.info', $this->buildGroupInfoPayload($groupId, $uid), $reqId);
    }

    protected function handleGroupUpdate(TcpConnection $connection, $uid, array $payload, $reqId)
    {
        $groupId = (int)($payload['group_id'] ?? 0);
        if (!$this->groups->isModerator($groupId, $uid)) {
            throw new \RuntimeException('no permission');
        }
        $before = $this->groups->get($groupId);
        if (!$before) {
            throw new \RuntimeException('invalid group');
        }
        $data = [];
        if (array_key_exists('name', $payload)) {
            $name = mb_substr(trim((string)$payload['name']), 0, 64);
            if ($name === '') {
                throw new \InvalidArgumentException('empty name');
            }
            $data['name'] = $name;
        }
        if (array_key_exists('notice', $payload)) {
            $data['notice'] = (string)$payload['notice'];
        }
        if (array_key_exists('avatar', $payload)) {
            $data['avatar'] = (string)$payload['avatar'];
        }
        $myRole = $this->groups->memberRole($groupId, $uid);
        if ($myRole >= 3) {
            if (array_key_exists('privacy_mode', $payload)) {
                $data['privacy_mode'] = (string)$payload['privacy_mode'];
            }
            if (array_key_exists('chat_mode', $payload)) {
                $data['chat_mode'] = (string)$payload['chat_mode'];
            }
        }
        if (!$data) {
            throw new \InvalidArgumentException('nothing to update');
        }
        $data = $this->groups->syncModeFields($data, $before);
        $group = $this->groups->updateGroup($groupId, $data);
        $opName = $this->groups->displayName($uid);
        $texts = [];
        if (isset($data['name']) && (string)$data['name'] !== (string)($before['name'] ?? '')) {
            $texts[] = $opName . ' 修改群名为「' . $data['name'] . '」';
        }
        if (isset($data['notice']) && (string)$data['notice'] !== (string)($before['notice'] ?? '')) {
            $texts[] = $opName . (((string)$data['notice'] === '') ? ' 清空了群公告' : ' 更新了群公告');
        }
        if (isset($data['avatar']) && (string)$data['avatar'] !== (string)($before['avatar'] ?? '')) {
            $texts[] = $opName . ' 更新了群头像';
        }
        if (isset($data['privacy_mode']) && (string)$data['privacy_mode'] !== (string)($before['privacy_mode'] ?? '')) {
            $label = ((string)$data['privacy_mode'] === 'open') ? '开放群' : '隐私群';
            $texts[] = $opName . ' 将群设置为「' . $label . '」';
        }
        if (isset($data['chat_mode']) && (string)$data['chat_mode'] !== (string)($before['chat_mode'] ?? '')) {
            $label = ((string)$data['chat_mode'] === 'grab') ? '红宝模式' : '聊天模式';
            $texts[] = $opName . ' 将群切换为「' . $label . '」';
        }
        foreach ($texts as $text) {
            $sys = $this->messages->sendGroupSystem($groupId, $text, $uid, [
                'event' => 'group_update',
                'fields' => array_keys($data),
            ]);
            $this->pushToGroup($groupId, 'group.message', ['message' => $sys]);
        }
        $this->send($connection, 'group.update', ['group' => $group], $reqId);
        $this->pushToGroup($groupId, 'group.updated', [
            'group_id' => $groupId,
            'group'    => $group,
            'policy'   => $this->groups->buildPolicy($group ?: [], $this->groups->memberRole($groupId, $uid)),
        ]);
    }

    protected function canSpeakSafe($groupId, $uid)
    {
        try {
            $this->groups->assertCanSpeak($groupId, $uid);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function handleGroupMembers(TcpConnection $connection, $uid, array $payload, $reqId)
    {
        $groupId = (int)($payload['group_id'] ?? 0);
        $keyword = (string)($payload['keyword'] ?? '');
        $data = $this->groups->listMembersDetailed($groupId, $uid, $keyword);
        $data['can_speak'] = $this->canSpeakSafe($groupId, $uid);
        $this->send($connection, 'group.members', $data, $reqId);
    }

    protected function handleFriendLookup(TcpConnection $connection, $uid, array $payload, $reqId)
    {
        $mobile = trim((string)($payload['mobile'] ?? ''));
        $dial = preg_replace('/\D+/', '', (string)($payload['country_dial'] ?? $payload['dial'] ?? ''));
        $memberId = preg_replace('/\D+/', '', (string)($payload['user_id'] ?? $payload['member_id'] ?? ''));
        $hasMobile = ($mobile !== '');
        $hasId = (bool)preg_match('/^\d{8}$/', $memberId);

        if ($hasMobile && $hasId) {
            $this->error($connection, '请只填写手机号或会员ID其中一项', $reqId);
            return;
        }
        if (!$hasMobile && !$hasId) {
            $this->error($connection, '请输入手机号或8位会员ID', $reqId);
            return;
        }

        $user = null;
        try {
            if ($hasId) {
                $user = $this->contacts->lookupByUserId($memberId, $uid);
            } else {
                $digits = preg_replace('/\D+/', '', $mobile);
                if ($digits === '' || strlen($digits) < 6 || strlen($digits) > 15) {
                    $this->error($connection, '手机号格式不正确', $reqId);
                    return;
                }
                $user = $this->contacts->lookupByPhone($digits, $uid, $dial);
            }
        } catch (\InvalidArgumentException $e) {
            $this->error($connection, $e->getMessage() ?: '参数错误', $reqId);
            return;
        }

        if (!$user) {
            $this->send($connection, 'friend.lookup', ['found' => false], $reqId);
            return;
        }
        if ((int)$user['user_id'] === (int)$uid) {
            $this->error($connection, '不能添加自己', $reqId);
            return;
        }
        $this->send($connection, 'friend.lookup', ['found' => true, 'user' => $user], $reqId);
    }

    protected function handleGroupJoin(TcpConnection $connection, $uid, array $payload, $reqId)
    {
        $groupId = (int)($payload['group_id'] ?? 0);
        $group = $this->groups->joinOpenGroup($groupId, $uid);
        $this->send($connection, 'group.joined', [
            'group' => $group ?: new \stdClass(),
            'group_id' => $groupId,
        ], $reqId);
    }

    protected function handleUserBrief(TcpConnection $connection, $uid, array $payload, $reqId)
    {
        $peerId = (int)($payload['user_id'] ?? 0);
        $groupId = (int)($payload['group_id'] ?? 0);
        if ($peerId <= 0) {
            $this->error($connection, '无效用户', $reqId);
            return;
        }
        if ($groupId > 0) {
            if (!$this->groups->isMember($groupId, $uid) || !$this->groups->isMember($groupId, $peerId)) {
                throw new \RuntimeException('not in group');
            }
            $group = $this->groups->get($groupId) ?: [];
            $role = $this->groups->memberRole($groupId, $uid);
            if (!$this->groups->buildPolicy($group, $role)['can_view_profile']) {
                throw new \RuntimeException('profile locked');
            }
        } elseif (!$this->contacts->isDiscoverableBy($peerId, $uid) && !$this->contacts->isFriend($uid, $peerId)) {
            throw new \RuntimeException('profile locked');
        }
        $brief = $this->contacts->userBrief($peerId);
        if (!$brief) {
            $this->error($connection, '用户不存在', $reqId);
            return;
        }
        $brief['is_friend'] = $this->contacts->isFriend($uid, $peerId);
        $rel = $this->contacts->requestStatusBetween($uid, $peerId);
        $brief['friend_relation'] = $rel;
        $brief['friend_request_pending'] = ($rel === 'outgoing_pending' || $rel === 'incoming_pending');
        $canAdd = !$brief['is_friend'] && $peerId !== (int)$uid && $rel === 'none';
        if ($groupId > 0) {
            $group = $this->groups->get($groupId) ?: [];
            $role = $this->groups->memberRole($groupId, $uid);
            $policy = $this->groups->buildPolicy($group, $role);
            $canAdd = $canAdd && !empty($policy['can_add_friend']);
        } else {
            $canAdd = $canAdd && $this->contacts->isDiscoverableBy($peerId, $uid);
        }
        $brief['can_add_friend'] = $canAdd;
        $brief['can_view_profile'] = true;
        $this->send($connection, 'user.brief', $brief, $reqId);
    }

    protected function handleFriendAdd(TcpConnection $connection, $uid, array $payload, $reqId)
    {
        $mobile = trim((string)($payload['mobile'] ?? ''));
        $dial = preg_replace('/\D+/', '', (string)($payload['country_dial'] ?? $payload['dial'] ?? ''));
        $memberId = preg_replace('/\D+/', '', (string)($payload['user_id'] ?? $payload['member_id'] ?? ''));
        $message = trim((string)($payload['message'] ?? $payload['remark'] ?? ''));
        $hasMobile = ($mobile !== '');
        $hasId = (bool)preg_match('/^\d{8}$/', $memberId);

        // 仅允许手机号 / 8位会员ID，禁止裸传 peer_user_id 加好友
        if ($hasMobile && $hasId) {
            $this->error($connection, '请只填写手机号或会员ID其中一项', $reqId);
            return;
        }
        if (!$hasMobile && !$hasId) {
            $this->error($connection, '仅支持通过手机号或8位会员ID添加好友', $reqId);
            return;
        }

        $peerId = 0;
        try {
            if ($hasId) {
                $user = $this->contacts->lookupByUserId($memberId, $uid);
                if (!$user) {
                    $this->error($connection, '未找到该会员ID用户', $reqId);
                    return;
                }
                $peerId = (int)$user['user_id'];
            } else {
                $digits = preg_replace('/\D+/', '', $mobile);
                if ($digits === '' || strlen($digits) < 6 || strlen($digits) > 15) {
                    $this->error($connection, '手机号格式不正确', $reqId);
                    return;
                }
                $user = $this->contacts->lookupByPhone($digits, $uid, $dial);
                if (!$user) {
                    $this->error($connection, '未找到该手机号用户', $reqId);
                    return;
                }
                $peerId = (int)$user['user_id'];
            }
        } catch (\InvalidArgumentException $e) {
            $this->error($connection, $e->getMessage() ?: '参数错误', $reqId);
            return;
        }

        if ($peerId <= 0 || $peerId === (int)$uid) {
            $this->error($connection, '对方用户无效', $reqId);
            return;
        }
        $result = $this->contacts->requestFriend($uid, $peerId, $message);
        $event = $result['auto_accepted'] || ($result['status'] ?? '') === 'accepted' || ($result['status'] ?? '') === 'already_friends'
            ? 'friend.added'
            : 'friend.requested';
        $this->send($connection, $event, $result, $reqId);
        // 通知对方
        if (($result['status'] ?? '') === 'pending') {
            $this->pushToUser($peerId, 'friend.request', [
                'request_id'   => (int)($result['request_id'] ?? 0),
                'from_user_id' => (int)$uid,
                'from_user'    => $result['from_user'] ?? $this->contacts->userBrief($uid),
                'message'      => (string)($result['message'] ?? $message),
            ]);
        } elseif (!empty($result['auto_accepted']) || ($result['status'] ?? '') === 'accepted') {
            $this->pushToUser($peerId, 'friend.accepted', $result);
            if (!empty($result['greeting'])) {
                $this->pushToUser($peerId, 'private.message', ['message' => $result['greeting']]);
                $this->pushToUser($uid, 'private.message', ['message' => $result['greeting']]);
            }
            if (!empty($result['message']) && is_array($result['message'])) {
                $this->pushToUser($uid, 'private.message', ['message' => $result['message']]);
                $this->pushToUser($peerId, 'private.message', ['message' => $result['message']]);
            }
        }
    }

    protected function handleFriendAccept(TcpConnection $connection, $uid, array $payload, $reqId)
    {
        $requestId = (int)($payload['request_id'] ?? $payload['id'] ?? 0);
        if ($requestId <= 0) {
            $this->error($connection, '无效申请', $reqId);
            return;
        }
        $result = $this->contacts->acceptRequest($uid, $requestId, true);
        $this->send($connection, 'friend.accepted', $result, $reqId);
        $peerId = (int)($result['from_user_id'] ?? $result['peer_user_id'] ?? 0);
        if ($peerId > 0) {
            $this->pushToUser($peerId, 'friend.accepted', $result);
            if (!empty($result['greeting'])) {
                $this->pushToUser($peerId, 'private.message', ['message' => $result['greeting']]);
                $this->pushToUser($uid, 'private.message', ['message' => $result['greeting']]);
            }
        }
    }

    protected function handleFriendReject(TcpConnection $connection, $uid, array $payload, $reqId)
    {
        $requestId = (int)($payload['request_id'] ?? $payload['id'] ?? 0);
        if ($requestId <= 0) {
            $this->error($connection, '无效申请', $reqId);
            return;
        }
        $result = $this->contacts->rejectRequest($uid, $requestId);
        $this->send($connection, 'friend.rejected', $result, $reqId);
        $peerId = (int)($result['peer_user_id'] ?? 0);
        // 通知申请人被拒绝（取消不通知对方）
        if ($peerId > 0 && ($result['status'] ?? '') === 'rejected') {
            $this->pushToUser($peerId, 'friend.rejected', $result);
        }
    }

    protected function memberCanCreateGroup($uid)
    {
        if (AdminService::isImAdmin($uid)) {
            return true;
        }
        $social = $this->cfg['social'] ?? [];
        return !isset($social['member_can_create_group']) || !empty($social['member_can_create_group']);
    }

    protected function handleGroupKick(TcpConnection $connection, $uid, array $payload, $reqId)
    {
        $groupId = (int)($payload['group_id'] ?? 0);
        $targetId = (int)($payload['user_id'] ?? $payload['target_user_id'] ?? 0);
        $this->groups->kick($groupId, $uid, $targetId);
        $opName = $this->groups->displayName($uid);
        $targetName = $this->groups->displayName($targetId);
        $sys = $this->messages->sendGroupSystem($groupId, $opName . ' 将 ' . $targetName . ' 移出了群组', $uid, [
            'event' => 'kick',
            'target_user_id' => $targetId,
        ]);
        $this->send($connection, 'group.kick', ['ok' => true, 'user_id' => $targetId], $reqId);
        $this->pushToGroup($groupId, 'group.message', ['message' => $sys]);
        $this->pushToUser($targetId, 'group.kicked', ['group_id' => $groupId]);
        // 踢人后成员列表已变，仍推给群内剩余成员
        $this->pushToGroup($groupId, 'group.members_changed', ['group_id' => $groupId, 'reason' => 'kick']);
    }

    protected function handleGroupMute(TcpConnection $connection, $uid, array $payload, $reqId)
    {
        $groupId = (int)($payload['group_id'] ?? 0);
        $targetId = (int)($payload['user_id'] ?? $payload['target_user_id'] ?? 0);
        $seconds = (int)($payload['seconds'] ?? 0);
        $result = $this->groups->muteMember($groupId, $uid, $targetId, $seconds);
        $opName = $this->groups->displayName($uid);
        $targetName = $this->groups->displayName($targetId);
        if ($seconds <= 0) {
            $text = $opName . ' 取消了 ' . $targetName . ' 的禁言';
        } else {
            $text = $opName . ' 禁言了 ' . $targetName . ' ' . $this->formatMuteDuration($seconds);
        }
        $sys = $this->messages->sendGroupSystem($groupId, $text, $uid, [
            'event' => 'mute',
            'target_user_id' => $targetId,
            'mute_until' => $result['mute_until'],
        ]);
        $this->send($connection, 'group.mute', $result + ['user_id' => $targetId], $reqId);
        $this->pushToGroup($groupId, 'group.message', ['message' => $sys]);
        $this->pushToGroup($groupId, 'group.members_changed', ['group_id' => $groupId, 'reason' => 'mute']);
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

    protected function handleGroupSetAdmin(TcpConnection $connection, $uid, array $payload, $reqId)
    {
        $groupId = (int)($payload['group_id'] ?? 0);
        $targetId = (int)($payload['user_id'] ?? $payload['target_user_id'] ?? 0);
        $isAdmin = !empty($payload['is_admin']);
        $result = $this->groups->setMemberAdmin($groupId, $uid, $targetId, $isAdmin);
        $opName = $this->groups->displayName($uid);
        $targetName = $this->groups->displayName($targetId);
        $text = $isAdmin
            ? $opName . ' 将 ' . $targetName . ' 设为管理员'
            : $opName . ' 取消了 ' . $targetName . ' 的管理员';
        $sys = $this->messages->sendGroupSystem($groupId, $text, $uid, [
            'event' => 'set_admin',
            'target_user_id' => $targetId,
            'role' => $result['role'],
        ]);
        $this->send($connection, 'group.set_admin', $result + ['user_id' => $targetId], $reqId);
        $this->pushToGroup($groupId, 'group.message', ['message' => $sys]);
        $this->pushToGroup($groupId, 'group.members_changed', ['group_id' => $groupId, 'reason' => 'set_admin']);
    }

    protected function handleGroupMuteAll(TcpConnection $connection, $uid, array $payload, $reqId)
    {
        $groupId = (int)($payload['group_id'] ?? 0);
        $enabled = !empty($payload['enabled']);
        $group = $this->groups->setMuteAll($groupId, $uid, $enabled);
        $opName = $this->groups->displayName($uid);
        $role = $this->groups->memberRole($groupId, $uid);
        $who = $role === 3 ? '群主' : '管理员';
        $text = $who . ' ' . $opName . ($enabled ? ' 开启了 全员禁言' : ' 关闭了 全员禁言');
        $sys = $this->messages->sendGroupSystem($groupId, $text, $uid, [
            'event' => 'mute_all',
            'enabled' => $enabled ? 1 : 0,
        ]);
        $this->send($connection, 'group.mute_all', [
            'group' => $group,
            'mute_all' => $enabled,
            'can_speak' => $this->canSpeakSafe($groupId, $uid),
        ], $reqId);
        $this->pushToGroup($groupId, 'group.message', ['message' => $sys]);
        $this->pushToGroup($groupId, 'group.mute_all_changed', [
            'group_id' => $groupId,
            'mute_all' => $enabled,
        ]);
    }

    protected function handleGroupCandidates(TcpConnection $connection, $uid, array $payload, $reqId)
    {
        $groupId = (int)($payload['group_id'] ?? 0);
        $keyword = (string)($payload['keyword'] ?? '');
        $data = $this->groups->inviteCandidates($groupId, $uid, $keyword, (int)($payload['limit'] ?? 50));
        $this->send($connection, 'group.candidates', $data, $reqId);
    }

    protected function handleGroupAddMembers(TcpConnection $connection, $uid, array $payload, $reqId)
    {
        $groupId = (int)($payload['group_id'] ?? 0);
        $memberIds = isset($payload['member_ids']) && is_array($payload['member_ids'])
            ? $payload['member_ids']
            : (isset($payload['user_ids']) && is_array($payload['user_ids']) ? $payload['user_ids'] : []);
        $result = $this->groups->addMembersByOperator($groupId, $uid, $memberIds);
        $opName = $this->groups->displayName($uid);
        $names = [];
        foreach ($result['added'] as $aid) {
            $names[] = $this->groups->displayName($aid);
        }
        $text = $opName . ' 邀请 ' . implode('、', array_slice($names, 0, 5))
            . (count($names) > 5 ? ' 等' . count($names) . '人' : '')
            . ' 加入了群组';
        $sys = $this->messages->sendGroupSystem($groupId, $text, $uid, [
            'event' => 'add_members',
            'member_ids' => $result['added'],
        ]);
        $this->send($connection, 'group.add_members', [
            'added' => $result['added'],
            'members' => $result['members'],
        ], $reqId);
        $this->pushToGroup($groupId, 'group.message', ['message' => $sys]);
        $this->pushToGroup($groupId, 'group.members_changed', ['group_id' => $groupId, 'reason' => 'add']);
        foreach ($result['added'] as $aid) {
            $this->pushToUser($aid, 'group.invited', [
                'group_id' => $groupId,
                'group' => $this->groups->get($groupId),
            ]);
        }
    }

    protected function handleInternalNotify(TcpConnection $connection, array $payload)
    {
        $expect = (string)($this->cfg['admin_bridge']['key'] ?? 'change-me-im-admin');
        $key = (string)($payload['admin_key'] ?? '');
        if ($expect === '' || !hash_equals($expect, $key)) {
            $this->error($connection, 'forbidden');
            return;
        }
        $type = (string)($payload['type'] ?? '');
        $msg = $payload['message'] ?? null;
        if ($type === '' || !is_array($msg)) {
            $this->error($connection, 'bad_packet');
            return;
        }
        NotifyDispatcher::dispatch(
            $type,
            $msg,
            !empty($payload['admin_only']),
            $this->groups
        );
        $this->send($connection, 'internal.notify.ok', ['ok' => true]);
    }

    protected function handleConversationRead(TcpConnection $connection, $uid, array $payload, $reqId)
    {
        $convType = (int)($payload['conversation_type'] ?? 0);
        $convId = (string)($payload['conversation_id'] ?? '');
        $lastId = (int)($payload['last_read_msg_id'] ?? $payload['message_id'] ?? 0);
        if ($convType !== 1 && $convType !== 2) {
            $this->error($connection, 'invalid conversation', $reqId);
            return;
        }
        if ($convId === '') {
            $this->error($connection, 'conversation_id required', $reqId);
            return;
        }
        $this->messages->markConversationRead($uid, $convType, $convId, $lastId);
        $this->send($connection, 'conversation.read.ok', ['ok' => true], $reqId);
    }

    protected function handleConversationList(TcpConnection $connection, $uid, array $payload, $reqId)
    {
        $uid = (int)$uid;
        $limit = (int)($payload['limit'] ?? 50);
        // 短缓存：吸收 auth.ok / 进 Tab / 未读校准连打
        $cacheKey = RedisClient::key('convlist:' . $uid . ':' . $limit);
        try {
            $cached = RedisClient::conn()->get($cacheKey);
            if ($cached !== false && $cached !== null && $cached !== '') {
                $decoded = json_decode((string)$cached, true);
                if (is_array($decoded)) {
                    $this->send($connection, 'conversation.list', ['list' => $decoded], $reqId);
                    return;
                }
            }
        } catch (\Throwable $e) {
        }

        $list = $this->messages->listConversations($uid, $limit);
        $peerIds = [];
        foreach ($list as $item) {
            if ((int)$item['conversation_type'] === 1 && (int)$item['peer_user_id'] > 0) {
                $peerIds[] = (int)$item['peer_user_id'];
            }
        }
        $users = $this->auth->usersBriefMap($peerIds);
        $adminMap = AdminService::adminIdMap();
        foreach ($list as &$item) {
            if ((int)$item['conversation_type'] === 1) {
                $peer = $users[(int)$item['peer_user_id']] ?? null;
                if ($peer) {
                    $item['peer'] = $peer;
                    $nick = trim((string)($peer['nickname'] ?: $peer['username'] ?: ''));
                    if ($nick === '' && !empty($peer['mobile'])) {
                        $mob = (string)$peer['mobile'];
                        $nick = strlen($mob) >= 7 ? (substr($mob, 0, 3) . '****' . substr($mob, -4)) : $mob;
                    }
                    if ($nick === '' && !empty($item['title'])) {
                        $nick = (string)$item['title'];
                    }
                    $item['title'] = $nick !== '' ? $nick : ('ID' . (int)$item['peer_user_id']);
                    $item['avatar'] = (string)($peer['avatar'] ?? '');
                } else {
                    $item['title'] = $item['title'] !== '' ? (string)$item['title'] : ('ID' . (int)$item['peer_user_id']);
                }
                $item['is_im_admin'] = isset($adminMap[(int)$item['peer_user_id']]);
                if ($item['is_im_admin'] && empty($item['title'])) {
                    $item['title'] = '客服';
                }
            }
        }
        unset($item);
        try {
            RedisClient::conn()->setex($cacheKey, 3, json_encode($list, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
        }
        $this->send($connection, 'conversation.list', ['list' => $list], $reqId);
    }

    protected function handleRedSend(TcpConnection $connection, $uid, array $payload, $reqId)
    {
        ChatForbidService::assertCanSendRedPacket($uid);
        $payload['from_user_id'] = $uid;
        $result = $this->redPackets->send($payload);
        $this->send($connection, 'redpacket.sent', $result, $reqId);
        $msg = $result['message'];
        if ((int)$msg['conversation_type'] === 2) {
            $this->pushToGroup((int)$msg['group_id'], 'group.message', ['message' => $msg]);
        } else {
            $this->pushToUser((int)$msg['to_user_id'], 'private.message', ['message' => $msg]);
            $this->pushToUser($uid, 'private.message', ['message' => $msg], (string)$connection->id);
        }
        // 立刻消费本机跨进程队列，降低多 Worker 漏推/延迟
        PushBus::drainOwnQueue(200);
    }

    protected function handleRedGrab(TcpConnection $connection, $uid, array $payload, $reqId)
    {
        ChatForbidService::assertCanGrabRedPacket($uid);
        $packetId = (int)($payload['packet_id'] ?? 0);
        $ip = '';
        try {
            $ip = (string)$connection->getRemoteIp();
        } catch (\Throwable $e) {
        }
        $fp = '';
        if (!empty($connection->deviceFp)) {
            $fp = (string)$connection->deviceFp;
        }
        if ($fp === '' && !empty($payload['device_fp'])) {
            $fp = strtolower(trim((string)$payload['device_fp']));
        }
        $groupId = 0;
        try {
            $meta = \Im\Support\RedisClient::conn()->hMGet(
                \Im\Support\RedisClient::key('rp:' . $packetId . ':meta'),
                ['scope_type', 'group_id']
            );
            if (is_array($meta) && (int)($meta['scope_type'] ?? 0) === 2) {
                $groupId = (int)($meta['group_id'] ?? 0);
            }
        } catch (\Throwable $e) {
        }
        $slider = [
            'slider_token'    => (string)($payload['slider_token'] ?? ''),
            'slider_x'        => (int)($payload['slider_x'] ?? 0),
            'slider_duration' => (int)($payload['slider_duration'] ?? 0),
            'slider_max'      => (int)($payload['slider_max'] ?? 0),
        ];
        try {
            GrabGuard::assertGrabAllowed($uid, $ip, $fp, $groupId, $slider);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'slider_required') {
                $this->send($connection, 'redpacket.challenge', [
                    'code'      => 'slider_required',
                    'message'   => '请拖动滑块完成安全验证后再抢',
                    'packet_id' => $packetId,
                ], $reqId);
                return;
            }
            throw $e;
        }

        $result = $this->redPackets->grab($packetId, $uid);
        $this->send($connection, 'redpacket.grabbed', $result, $reqId);
        // 通知会话内成员刷新：使用 grab 返回的最小 packet 信息，避免热路径再查一次详情
        $packet = $result['packet'] ?? null;
        if ($packet) {
            $event = ['packet_id' => $packetId, 'grab' => $result, 'by_user_id' => $uid];
            if ((int)$packet['scope_type'] === 2) {
                $this->pushToGroup((int)$packet['group_id'], 'redpacket.update', $event);
            } else {
                $this->pushToUser((int)$packet['from_user_id'], 'redpacket.update', $event);
                $this->pushToUser((int)$packet['to_user_id'], 'redpacket.update', $event);
            }
        }
    }

    protected function pushToUser($userId, $type, array $data, $exceptConnId = '')
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return;
        }
        PushBus::toUsers([$userId], $type, $data, $exceptConnId);
    }

    protected function pushToGroup($groupId, $type, array $data, $exceptConnId = '')
    {
        $uids = $this->groups->memberUserIds($groupId);
        // 只推在线，避免万人群把离线 uid 塞进跨进程队列
        $uids = ConnMap::filterOnlineUserIds($uids ?: []);
        if ($uids) {
            PushBus::toUsers($uids, $type, $data, $exceptConnId);
        }
    }

    /**
     * PushBus 本进程投递回调
     */
    public function deliverEnvelope(array $envelope)
    {
        $type = (string)($envelope['type'] ?? '');
        $data = $envelope['data'] ?? [];
        if ($type === '' || !is_array($data)) {
            return;
        }
        $except = (string)($envelope['except'] ?? '');
        $uids = $envelope['uids'] ?? [];
        if (!is_array($uids)) {
            return;
        }
        foreach ($uids as $uid) {
            $uid = (int)$uid;
            if ($uid <= 0) {
                continue;
            }
            foreach (ConnMap::connIdsOfUser($uid) as $cid) {
                if ($except !== '' && (string)$cid === $except) {
                    continue;
                }
                if (!isset($this->worker->connections[$cid])) {
                    continue;
                }
                $this->send($this->worker->connections[$cid], $type, $data);
            }
        }
    }

    protected function send(TcpConnection $connection, $type, $data, $reqId = '')
    {
        $packet = ['type' => $type, 'data' => $data, 'ts' => time()];
        if ($reqId !== '') {
            $packet['req_id'] = $reqId;
        }
        $connection->send(json_encode($packet, JSON_UNESCAPED_UNICODE));
    }

    protected function error(TcpConnection $connection, $message, $reqId = '')
    {
        $this->send($connection, 'error', ['message' => (string)$message], $reqId);
    }
}
