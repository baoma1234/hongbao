<?php

namespace Im\Http;

use Im\Service\AdminService;
use Im\Service\AuthService;
use Im\Service\ContactService;
use Im\Service\GroupService;
use Im\Service\MessageService;
use Im\Service\NiuniuService;
use Im\Service\RedPacketService;
use Im\Support\IdGenerator;
use Im\Support\RedisClient;

/**
 * 用户侧只读接口：会话列表 / 历史（HTTP，减轻 WS Worker 压力）
 */
class UserReadApi
{
    /** @var array */
    protected $cfg;
    /** @var AuthService */
    protected $auth;
    /** @var MessageService */
    protected $messages;
    /** @var GroupService */
    protected $groups;
    /** @var RedPacketService */
    protected $redPackets;
    /** @var NiuniuService */
    protected $niuniu;
    /** @var ContactService */
    protected $contacts;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
        $this->auth = new AuthService($cfg);
        $this->messages = new MessageService();
        $this->groups = new GroupService();
        $this->redPackets = new RedPacketService($cfg, $this->messages, $this->groups);
        $this->niuniu = new NiuniuService($cfg, $this->messages, $this->groups);
        $this->contacts = new ContactService();
    }

    public function userIdByToken($token)
    {
        return (int)$this->auth->userIdByToken($token);
    }

    /**
     * @return array{list:array}
     */
    public function conversations($userId, $limit = 50)
    {
        $userId = (int)$userId;
        $limit = max(1, min(100, (int)$limit));
        $cacheKey = RedisClient::key('convlist:' . $userId . ':' . $limit);
        try {
            $cached = RedisClient::conn()->get($cacheKey);
            if ($cached !== false && $cached !== null && $cached !== '') {
                $decoded = json_decode((string)$cached, true);
                if (is_array($decoded)) {
                    return ['list' => $decoded];
                }
            }
        } catch (\Throwable $e) {
        }

        $list = $this->messages->listConversations($userId, $limit);
        $peerIds = [];
        foreach ($list as $item) {
            if ((int)$item['conversation_type'] === 1 && (int)$item['peer_user_id'] > 0) {
                $peerIds[] = (int)$item['peer_user_id'];
            }
        }
        $users = $this->auth->usersBriefMap($peerIds);
        $adminMap = AdminService::adminIdMap();
        $remarks = $this->contacts->remarksMap($userId, $peerIds);
        foreach ($list as &$item) {
            if ((int)$item['conversation_type'] !== 1) {
                continue;
            }
            $peer = $users[(int)$item['peer_user_id']] ?? null;
            $peerId = (int)$item['peer_user_id'];
            $remark = isset($remarks[$peerId]) ? (string)$remarks[$peerId] : '';
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
                if ($nick === '') {
                    $nick = 'ID' . $peerId;
                }
                $item['peer_nickname'] = $nick;
                $item['remark'] = $remark;
                $item['title'] = $remark !== '' ? $remark : $nick;
                $item['avatar'] = (string)($peer['avatar'] ?? '');
            } else {
                $fallback = $item['title'] !== '' ? (string)$item['title'] : ('ID' . $peerId);
                $item['peer_nickname'] = $fallback;
                $item['remark'] = $remark;
                $item['title'] = $remark !== '' ? $remark : $fallback;
            }
            $item['is_im_admin'] = isset($adminMap[$peerId]);
            if ($item['is_im_admin'] && empty($item['peer_nickname'])) {
                $item['peer_nickname'] = '客服';
                if ($remark === '') {
                    $item['title'] = '客服';
                }
            }
        }
        unset($item);

        try {
            RedisClient::conn()->setex($cacheKey, 20, \Im\Support\Json::encode($list));
        } catch (\Throwable $e) {
        }
        return ['list' => $list];
    }

    /**
     * @return array{list:array,group?:mixed,policy?:array,...}
     */
    public function history($userId, array $payload)
    {
        $userId = (int)$userId;
        $ctype = (int)($payload['conversation_type'] ?? 1);
        $cid = (string)($payload['conversation_id'] ?? '');
        if ($cid === '' && $ctype === 1) {
            $other = (int)($payload['to_user_id'] ?? 0);
            $cid = IdGenerator::privateConversationId($userId, $other);
        }
        $gid = 0;
        if ($ctype === 2) {
            $gid = (int)($payload['group_id'] ?? $cid);
            if (!$this->groups->isMember($gid, $userId)) {
                throw new \RuntimeException('not in group');
            }
            $cid = (string)$gid;
        } elseif ($ctype === 1) {
            if (!$this->canAccessPrivate($userId, $cid)) {
                throw new \RuntimeException('forbidden');
            }
        } else {
            throw new \RuntimeException('invalid conversation');
        }

        $list = $this->messages->history(
            $ctype,
            $cid,
            (int)($payload['before_id'] ?? 0),
            (int)($payload['limit'] ?? 30),
            $userId
        );
        $list = $this->redPackets->enrichMessageExtras($list, $userId);
        $list = $this->niuniu->enrichMessageExtras($list, $userId);
        $list = $this->messages->enrichMessagesWithSenders($list);
        $data = ['list' => $list];
        if ($ctype === 2 && $gid > 0) {
            $data = array_merge($data, $this->groupInfoPayload($gid, $userId));
        }
        return $data;
    }

    protected function canAccessPrivate($uid, $conversationId)
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

    protected function groupInfoPayload($groupId, $uid)
    {
        return $this->groups->viewerInfoPayload($groupId, $uid);
    }
}
