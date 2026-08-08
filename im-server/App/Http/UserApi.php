<?php

namespace Im\Http;

use Im\Service\AdminService;
use Im\Service\ChatForbidService;
use Im\Service\ContactService;
use Im\Service\NiuniuService;
use Im\Service\TransferService;
use Im\Support\GrabGuard;
use Im\Support\NotifyPublisher;
use Im\Support\PushBus;
use Im\Support\RedPacketUpdateBus;
use Im\Support\RedisClient;

/**
 * 用户侧 HTTP API（token 鉴权）：红包/好友/群管理等非聊天写读操作
 * 实时推送走 Redis notify_queue / PushBus，减轻 WS Worker 压力
 */
class UserApi extends UserReadApi
{
    /** @var ContactService */
    protected $contacts;
    /** @var TransferService|null */
    protected $transfers;
    /** @var NiuniuService|null */
    protected $niuniuSvc;

    public function __construct(array $cfg)
    {
        parent::__construct($cfg);
        $this->contacts = new ContactService();
        try {
            $this->transfers = new TransferService($cfg, $this->messages, $this->groups);
        } catch (\Throwable $e) {
            $this->transfers = null;
        }
        try {
            $this->niuniuSvc = new NiuniuService($cfg, $this->messages, $this->groups);
        } catch (\Throwable $e) {
            $this->niuniuSvc = null;
        }
    }

    protected function niuniu()
    {
        if (!$this->niuniuSvc) {
            $this->niuniuSvc = new NiuniuService($this->cfg, $this->messages, $this->groups);
        }
        return $this->niuniuSvc;
    }

    /**
     * @param array{ip?:string} $meta
     * @return array{data:array,ws_type?:string}
     */
    public function handle($path, $userId, array $body, array $meta = [])
    {
        $userId = (int)$userId;
        switch ($path) {
            case '/im/conversations':
                return ['data' => $this->conversations($userId, (int)($body['limit'] ?? 50))];
            case '/im/history':
                return ['data' => $this->history($userId, $body)];

            case '/im/redpacket/send':
                return ['data' => $this->redpacketSend($userId, $body), 'ws_type' => 'redpacket.sent'];
            case '/im/redpacket/grab':
                return $this->redpacketGrab($userId, $body, $meta);
            case '/im/redpacket/detail':
                return ['data' => $this->redPackets->detail((int)($body['packet_id'] ?? 0), $userId) ?: new \stdClass()];

            case '/im/niuniu/start':
                return ['data' => $this->niuniu()->start($userId, (int)($body['group_id'] ?? 0)), 'ws_type' => 'niuniu.started'];
            case '/im/niuniu/buy':
                return ['data' => $this->niuniu()->buy($userId, (int)($body['round_id'] ?? 0), (int)($body['count'] ?? 1)), 'ws_type' => 'niuniu.bought'];
            case '/im/niuniu/claim':
                return ['data' => $this->niuniu()->claim($userId, (int)($body['round_id'] ?? 0)), 'ws_type' => 'niuniu.claimed'];
            case '/im/niuniu/detail':
                return ['data' => $this->niuniu()->detail((int)($body['round_id'] ?? 0), $userId) ?: new \stdClass()];

            case '/im/transfer/send':
                return ['data' => $this->transferSend($userId, $body), 'ws_type' => 'transfer.sent'];

            case '/im/friend/list':
                return ['data' => ['list' => $this->contacts->listFriends($userId)]];
            case '/im/friend/lookup':
                return ['data' => $this->friendLookup($userId, $body)];
            case '/im/friend/request':
            case '/im/friend/add':
                return $this->friendRequest($userId, $body);
            case '/im/friend/requests':
                return ['data' => $this->contacts->listRequests($userId)];
            case '/im/friend/accept':
                return ['data' => $this->friendAccept($userId, $body), 'ws_type' => 'friend.accepted'];
            case '/im/friend/reject':
            case '/im/friend/cancel':
                return ['data' => $this->friendReject($userId, $body), 'ws_type' => 'friend.rejected'];
            case '/im/friend/set_remark':
                return ['data' => $this->friendSetRemark($userId, $body)];

            case '/im/group/list':
                return ['data' => ['list' => $this->groups->myGroups($userId)]];
            case '/im/group/join':
                return ['data' => $this->groupJoin($userId, $body), 'ws_type' => 'group.joined'];
            case '/im/group/info':
                if (!$this->groups->isMember((int)($body['group_id'] ?? 0), $userId)) {
                    throw new \RuntimeException('not in group');
                }
                return ['data' => $this->groupInfoPayload((int)($body['group_id'] ?? 0), $userId)];
            case '/im/group/leave':
                return ['data' => $this->groupLeave($userId, $body), 'ws_type' => 'group.leave.ok'];
            case '/im/group/create':
                return ['data' => $this->groupCreate($userId, $body), 'ws_type' => 'group.created'];
            case '/im/group/update':
                return ['data' => $this->groupUpdate($userId, $body), 'ws_type' => 'group.update'];
            case '/im/group/members':
                return ['data' => $this->groupMembers($userId, $body)];
            case '/im/group/kick':
                return ['data' => $this->groupKick($userId, $body)];
            case '/im/group/mute':
                return ['data' => $this->groupMute($userId, $body)];
            case '/im/group/set_admin':
                return ['data' => $this->groupSetAdmin($userId, $body)];
            case '/im/group/mute_all':
                return ['data' => $this->groupMuteAll($userId, $body), 'ws_type' => 'group.mute_all'];
            case '/im/group/set_forbid':
                return ['data' => $this->groupSetForbid($userId, $body), 'ws_type' => 'group.set_forbid'];
            case '/im/group/candidates':
                return ['data' => $this->groups->inviteCandidates(
                    (int)($body['group_id'] ?? 0),
                    $userId,
                    (string)($body['keyword'] ?? ''),
                    (int)($body['limit'] ?? 50)
                )];
            case '/im/group/add_members':
                return ['data' => $this->groupAddMembers($userId, $body)];

            default:
                throw new \RuntimeException('not found');
        }
    }

    protected function redpacketSend($uid, array $body)
    {
        ChatForbidService::assertCanSendRedPacket($uid);
        $body['from_user_id'] = $uid;
        // 禁止 HTTP 客户端伪造 robot_send
        unset($body['robot_send']);
        $result = $this->redPackets->send($body);
        $msg = $result['message'] ?? null;
        if (is_array($msg)) {
            $type = ((int)($msg['conversation_type'] ?? 0) === 2) ? 'group.message' : 'private.message';
            NotifyPublisher::publish($type, $msg, false, $this->cfg);
        }
        return $result;
    }

    /**
     * @return array{data:array,ws_type:string}
     */
    protected function redpacketGrab($uid, array $body, array $meta)
    {
        ChatForbidService::assertCanGrabRedPacket($uid);
        $packetId = (int)($body['packet_id'] ?? 0);
        $ip = (string)($meta['ip'] ?? '');
        $fp = strtolower(trim((string)($body['device_fp'] ?? '')));
        $groupId = 0;
        try {
            $m = RedisClient::conn()->hMGet(RedisClient::key('rp:' . $packetId . ':meta'), ['scope_type', 'group_id']);
            if (is_array($m) && (int)($m['scope_type'] ?? 0) === 2) {
                $groupId = (int)($m['group_id'] ?? 0);
            }
        } catch (\Throwable $e) {
        }
        $slider = [
            'slider_token'    => (string)($body['slider_token'] ?? ''),
            'slider_x'        => (int)($body['slider_x'] ?? 0),
            'slider_duration' => (int)($body['slider_duration'] ?? 0),
            'slider_max'      => (int)($body['slider_max'] ?? 0),
        ];
        try {
            GrabGuard::assertGrabAllowed($uid, $ip, $fp, $groupId, $slider);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'slider_required') {
                return [
                    'ws_type' => 'redpacket.challenge',
                    'data'    => [
                        'code'      => 'slider_required',
                        'message'   => '请拖动滑块完成安全验证后再抢',
                        'packet_id' => $packetId,
                    ],
                ];
            }
            throw $e;
        }

        $result = $this->redPackets->grab($packetId, $uid);
        $packet = $result['packet'] ?? null;
        if (is_array($packet)) {
            $event = ['packet_id' => $packetId, 'grab' => $result, 'by_user_id' => $uid];
            if ((int)($packet['scope_type'] ?? 0) === 2) {
                RedPacketUpdateBus::publish($event, ['group_id' => (int)$packet['group_id']]);
            } else {
                RedPacketUpdateBus::publish($event, [
                    'user_ids' => [
                        (int)($packet['from_user_id'] ?? 0),
                        (int)($packet['to_user_id'] ?? 0),
                    ],
                ]);
            }
        }
        return ['data' => $result, 'ws_type' => 'redpacket.grabbed'];
    }

    protected function transferSend($uid, array $body)
    {
        if (!$this->transfers) {
            throw new \RuntimeException('transfer unavailable');
        }
        $body['from_user_id'] = $uid;
        $result = $this->transfers->send($body);
        $msg = $result['message'] ?? null;
        if (is_array($msg)) {
            NotifyPublisher::publish('private.message', $msg, false, $this->cfg);
        }
        return $result;
    }

    protected function friendLookup($uid, array $body)
    {
        $mobile = trim((string)($body['mobile'] ?? ''));
        $dial = preg_replace('/\D+/', '', (string)($body['country_dial'] ?? $body['dial'] ?? ''));
        $memberId = preg_replace('/\D+/', '', (string)($body['user_id'] ?? $body['member_id'] ?? ''));
        $hasMobile = ($mobile !== '');
        $hasId = (bool)preg_match('/^\d{8}$/', $memberId);
        if ($hasMobile && $hasId) {
            throw new \RuntimeException('请只填写手机号或会员ID其中一项');
        }
        if (!$hasMobile && !$hasId) {
            throw new \RuntimeException('请输入手机号或8位会员ID');
        }
        $user = null;
        if ($hasId) {
            $user = $this->contacts->lookupByUserId($memberId, $uid);
        } else {
            $digits = preg_replace('/\D+/', '', $mobile);
            if ($digits === '' || strlen($digits) < 6 || strlen($digits) > 15) {
                throw new \RuntimeException('手机号格式不正确');
            }
            $user = $this->contacts->lookupByPhone($digits, $uid, $dial);
        }
        if (!$user) {
            return ['found' => false];
        }
        if ((int)$user['user_id'] === (int)$uid) {
            throw new \RuntimeException('不能添加自己');
        }
        return ['found' => true, 'user' => $user];
    }

    protected function resolveFriendPeerId($uid, array $body)
    {
        $lookup = $this->friendLookup($uid, $body);
        if (empty($lookup['found'])) {
            throw new \RuntimeException('未找到该用户');
        }
        $peerId = (int)($lookup['user']['user_id'] ?? 0);
        if ($peerId <= 0 || $peerId === (int)$uid) {
            throw new \RuntimeException('对方用户无效');
        }
        return $peerId;
    }

    protected function friendRequest($uid, array $body)
    {
        $peerId = $this->resolveFriendPeerId($uid, $body);
        $message = trim((string)($body['message'] ?? $body['remark'] ?? ''));
        $result = $this->contacts->requestFriend($uid, $peerId, $message);
        $event = !empty($result['auto_accepted']) || ($result['status'] ?? '') === 'accepted' || ($result['status'] ?? '') === 'already_friends'
            ? 'friend.added'
            : 'friend.requested';
        if (($result['status'] ?? '') === 'pending') {
            PushBus::toUsers([$peerId], 'friend.request', [
                'request_id'   => (int)($result['request_id'] ?? 0),
                'from_user_id' => $uid,
                'from_user'    => $result['from_user'] ?? $this->contacts->userBrief($uid),
                'message'      => (string)($result['message'] ?? $message),
            ]);
        } elseif (!empty($result['auto_accepted']) || ($result['status'] ?? '') === 'accepted') {
            PushBus::toUsers([$peerId], 'friend.accepted', $result);
            if (!empty($result['greeting']) && is_array($result['greeting'])) {
                NotifyPublisher::publish('private.message', $result['greeting'], false, $this->cfg);
            }
            if (!empty($result['message']) && is_array($result['message'])) {
                NotifyPublisher::publish('private.message', $result['message'], false, $this->cfg);
            }
        }
        return ['data' => $result, 'ws_type' => $event];
    }

    protected function friendAccept($uid, array $body)
    {
        $requestId = (int)($body['request_id'] ?? $body['id'] ?? 0);
        if ($requestId <= 0) {
            throw new \RuntimeException('无效申请');
        }
        $result = $this->contacts->acceptRequest($uid, $requestId, true);
        $peerId = (int)($result['from_user_id'] ?? $result['peer_user_id'] ?? 0);
        if ($peerId > 0) {
            PushBus::toUsers([$peerId], 'friend.accepted', $result);
            if (!empty($result['greeting']) && is_array($result['greeting'])) {
                NotifyPublisher::publish('private.message', $result['greeting'], false, $this->cfg);
            }
        }
        return $result;
    }

    protected function friendReject($uid, array $body)
    {
        $requestId = (int)($body['request_id'] ?? $body['id'] ?? 0);
        if ($requestId <= 0) {
            throw new \RuntimeException('无效申请');
        }
        $result = $this->contacts->rejectRequest($uid, $requestId);
        $peerId = (int)($result['peer_user_id'] ?? 0);
        if ($peerId > 0 && ($result['status'] ?? '') === 'rejected') {
            PushBus::toUsers([$peerId], 'friend.rejected', $result);
        }
        return $result;
    }

    protected function friendSetRemark($uid, array $body)
    {
        $peerId = (int)($body['peer_user_id'] ?? $body['user_id'] ?? $body['to_user_id'] ?? 0);
        $remark = (string)($body['remark'] ?? '');
        return $this->contacts->setRemark($uid, $peerId, $remark);
    }

    protected function groupJoin($uid, array $body)
    {
        $groupId = (int)($body['group_id'] ?? 0);
        $group = $this->groups->joinOpenGroup($groupId, $uid);
        return [
            'group'    => $group ?: new \stdClass(),
            'group_id' => $groupId,
        ];
    }

    protected function groupLeave($uid, array $body)
    {
        $groupId = (int)($body['group_id'] ?? 0);
        if ($groupId <= 0) {
            throw new \InvalidArgumentException('invalid group');
        }
        if ($this->groups->isOwner($groupId, $uid)) {
            throw new \RuntimeException('群主不能退出群组，请先转让群主');
        }
        if (!$this->groups->isMember($groupId, $uid)) {
            throw new \RuntimeException('你不在该群组中');
        }
        $name = $this->groups->displayName($uid);
        $sys = $this->messages->sendGroupSystem($groupId, $name . ' 退出了群组', $uid, [
            'event' => 'leave',
            'target_user_id' => $uid,
        ]);
        $clearedMsgId = (int)($sys['id'] ?? 0);
        $clearResult = $this->messages->clearGroupConversation($uid, $groupId, $clearedMsgId);
        $this->groups->leave($groupId, $uid);
        NotifyPublisher::publish('group.message', $sys, false, $this->cfg);
        PushBus::toGroup($groupId, 'group.members_changed', ['group_id' => $groupId, 'reason' => 'leave']);
        return [
            'ok'             => true,
            'group_id'       => $groupId,
            'cleared_msg_id' => (int)($clearResult['cleared_msg_id'] ?? $clearedMsgId),
        ];
    }

    protected function memberCanCreateGroup($uid)
    {
        if (AdminService::isImAdmin($uid)) {
            return true;
        }
        $social = $this->cfg['social'] ?? [];
        return !isset($social['member_can_create_group']) || !empty($social['member_can_create_group']);
    }

    protected function groupCreate($uid, array $body)
    {
        if (!$this->memberCanCreateGroup($uid)) {
            throw new \RuntimeException('group create disabled');
        }
        $name = (string)($body['name'] ?? '');
        $memberIds = isset($body['member_ids']) && is_array($body['member_ids']) ? $body['member_ids'] : [];
        $group = $this->groups->create($uid, $name, $memberIds, [], [
            'privacy_mode'      => (string)($body['privacy_mode'] ?? 'private'),
            'chat_mode'         => (string)($body['chat_mode'] ?? 'chat'),
            'bind_owner_rebate' => !empty($body['bind_owner_rebate']),
        ]);
        $gid = (int)($group['id'] ?? 0);
        if ($gid > 0) {
            PushBus::toGroup($gid, 'group.created', ['group' => $group]);
        }
        return ['group' => $group];
    }

    protected function groupUpdate($uid, array $body)
    {
        $groupId = (int)($body['group_id'] ?? 0);
        if (!$this->groups->isModerator($groupId, $uid)) {
            throw new \RuntimeException('no permission');
        }
        $before = $this->groups->get($groupId);
        if (!$before) {
            throw new \RuntimeException('invalid group');
        }
        $data = [];
        if (array_key_exists('name', $body)) {
            $name = mb_substr(trim((string)$body['name']), 0, 64);
            if ($name === '') {
                throw new \InvalidArgumentException('empty name');
            }
            $data['name'] = $name;
        }
        if (array_key_exists('notice', $body)) {
            $data['notice'] = (string)$body['notice'];
        }
        if (array_key_exists('avatar', $body)) {
            $data['avatar'] = (string)$body['avatar'];
        }
        $myRole = $this->groups->memberRole($groupId, $uid);
        if ($myRole >= 3) {
            if (array_key_exists('privacy_mode', $body)) {
                $data['privacy_mode'] = (string)$body['privacy_mode'];
            }
            if (array_key_exists('chat_mode', $body)) {
                $data['chat_mode'] = (string)$body['chat_mode'];
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
            NotifyPublisher::publish('group.message', $sys, false, $this->cfg);
        }
        if ($group) {
            PushBus::toGroup($groupId, 'group.updated', [
                'group_id' => $groupId,
                'group'    => $group,
                'policy'   => $this->groups->buildPolicy($group, $this->groups->memberRole($groupId, $uid)),
            ]);
        }
        return ['group' => $group];
    }

    protected function groupMembers($uid, array $body)
    {
        $groupId = (int)($body['group_id'] ?? 0);
        if (!$this->groups->isMember($groupId, $uid)) {
            throw new \RuntimeException('not in group');
        }
        return $this->groups->listMembersDetailed($groupId, $uid, (string)($body['keyword'] ?? ''));
    }

    protected function groupKick($uid, array $body)
    {
        $groupId = (int)($body['group_id'] ?? 0);
        $targetId = (int)($body['user_id'] ?? $body['target_user_id'] ?? 0);
        $this->groups->kick($groupId, $uid, $targetId);
        $opName = $this->groups->displayName($uid);
        $targetName = $this->groups->displayName($targetId);
        $sys = $this->messages->sendGroupSystem($groupId, $opName . ' 将 ' . $targetName . ' 移出了群组', $uid, [
            'event' => 'kick',
            'target_user_id' => $targetId,
        ]);
        NotifyPublisher::publish('group.message', $sys, false, $this->cfg);
        PushBus::toUsers([$targetId], 'group.kicked', ['group_id' => $groupId]);
        PushBus::toGroup($groupId, 'group.members_changed', ['group_id' => $groupId, 'reason' => 'kick']);
        return ['ok' => true, 'user_id' => $targetId];
    }

    protected function groupMute($uid, array $body)
    {
        $groupId = (int)($body['group_id'] ?? 0);
        $targetId = (int)($body['user_id'] ?? $body['target_user_id'] ?? 0);
        $seconds = (int)($body['seconds'] ?? 0);
        $result = $this->groups->muteMember($groupId, $uid, $targetId, $seconds);
        PushBus::toGroup($groupId, 'group.members_changed', ['group_id' => $groupId, 'reason' => 'mute']);
        return $result + ['user_id' => $targetId];
    }

    protected function groupSetAdmin($uid, array $body)
    {
        $groupId = (int)($body['group_id'] ?? 0);
        $targetId = (int)($body['user_id'] ?? $body['target_user_id'] ?? 0);
        $isAdmin = !empty($body['is_admin']);
        $result = $this->groups->setMemberAdmin($groupId, $uid, $targetId, $isAdmin);
        PushBus::toGroup($groupId, 'group.members_changed', ['group_id' => $groupId, 'reason' => 'set_admin']);
        return $result + ['user_id' => $targetId];
    }

    protected function groupMuteAll($uid, array $body)
    {
        $groupId = (int)($body['group_id'] ?? 0);
        $enabled = !empty($body['enabled']) || !empty($body['mute_all']);
        $group = $this->groups->setMuteAll($groupId, $uid, $enabled);
        $forbids = $this->groups->parseForbidModes($group ?: []);
        PushBus::toGroup($groupId, 'group.mute_all_changed', [
            'group_id' => $groupId,
            'mute_all' => $enabled,
            'forbid_modes' => $forbids,
            'group'    => $group,
        ]);
        return [
            'group' => $group,
            'mute_all' => $enabled,
            'forbid_modes' => $forbids,
            'policy' => $this->groups->buildPolicy($group ?: [], $this->groups->memberRole($groupId, $uid)),
        ];
    }

    protected function groupSetForbid($uid, array $body)
    {
        $groupId = (int)($body['group_id'] ?? 0);
        $flags = [];
        if (isset($body['forbid_modes']) && is_array($body['forbid_modes'])) {
            $flags = $body['forbid_modes'];
        } else {
            foreach (\Im\Service\GroupService::forbidModeKeys() as $k) {
                if (array_key_exists($k, $body)) {
                    $flags[$k] = $body[$k];
                }
            }
        }
        $opts = [];
        if (array_key_exists('forbid_speak_hint', $body)) {
            $opts['forbid_speak_hint'] = $body['forbid_speak_hint'];
        }
        $group = $this->groups->setForbidModes($groupId, $uid, $flags, $opts);
        $forbids = $this->groups->parseForbidModes($group ?: []);
        PushBus::toGroup($groupId, 'group.forbid_changed', [
            'group_id' => $groupId,
            'forbid_modes' => $forbids,
            'mute_all' => $this->groups->isMuteAll($groupId),
            'group' => $group,
        ]);
        return [
            'group' => $group,
            'forbid_modes' => $forbids,
            'mute_all' => $this->groups->isMuteAll($groupId),
            'policy' => $this->groups->buildPolicy($group ?: [], $this->groups->memberRole($groupId, $uid)),
        ];
    }

    protected function groupAddMembers($uid, array $body)
    {
        $groupId = (int)($body['group_id'] ?? 0);
        $ids = isset($body['member_ids']) && is_array($body['member_ids']) ? $body['member_ids'] : [];
        $result = $this->groups->addMembersByOperator($groupId, $uid, $ids);
        PushBus::toGroup($groupId, 'group.members_changed', ['group_id' => $groupId, 'reason' => 'add']);
        return $result;
    }
}
