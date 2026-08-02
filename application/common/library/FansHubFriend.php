<?php

namespace app\common\library;

use think\Db;

/**
 * 后台/HTTP 侧好友申请处理（与 IM ContactService 共用表）
 */
class FansHubFriend
{
    const STATUS_PENDING = 0;
    const STATUS_ACCEPTED = 1;
    const STATUS_REJECTED = 2;
    const STATUS_CANCELLED = 3;

    public static function acceptByAdmin($requestId)
    {
        $requestId = (int)$requestId;
        $row = Db::name('chat_friend_requests')->where('id', $requestId)->find();
        if (!$row) {
            throw new \RuntimeException('申请不存在');
        }
        if ((int)$row['status'] !== self::STATUS_PENDING) {
            throw new \RuntimeException('该申请已处理');
        }
        $fromId = (int)$row['from_user_id'];
        $toId = (int)$row['to_user_id'];
        $now = time();
        Db::startTrans();
        try {
            $n = Db::name('chat_friend_requests')->where(['id' => $requestId, 'status' => self::STATUS_PENDING])->update([
                'status'         => self::STATUS_ACCEPTED,
                'handle_user_id' => 0,
                'updatetime'     => $now,
            ]);
            if (!$n) {
                throw new \RuntimeException('该申请已处理');
            }
            self::ensureContact($fromId, $toId, $now);
            self::ensureContact($toId, $fromId, $now);
            self::insertPrivateText($toId, $fromId, '我们已经是好友了~', $now);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
        return true;
    }

    public static function rejectByAdmin($requestId)
    {
        $requestId = (int)$requestId;
        $row = Db::name('chat_friend_requests')->where('id', $requestId)->find();
        if (!$row) {
            throw new \RuntimeException('申请不存在');
        }
        if ((int)$row['status'] !== self::STATUS_PENDING) {
            throw new \RuntimeException('该申请已处理');
        }
        $n = Db::name('chat_friend_requests')->where(['id' => $requestId, 'status' => self::STATUS_PENDING])->update([
            'status'         => self::STATUS_REJECTED,
            'handle_user_id' => 0,
            'updatetime'     => time(),
        ]);
        if (!$n) {
            throw new \RuntimeException('该申请已处理');
        }
        return true;
    }

    /** 双向好友（幂等） */
    public static function ensureMutual($userId, $peerId, $now = null)
    {
        $now = $now !== null ? (int)$now : time();
        self::ensureContact((int)$userId, (int)$peerId, $now);
        self::ensureContact((int)$peerId, (int)$userId, $now);
    }

    public static function ensureContact($userId, $peerId, $now = null)
    {
        $userId = (int)$userId;
        $peerId = (int)$peerId;
        if ($userId <= 0 || $peerId <= 0 || $userId === $peerId) {
            return;
        }
        $now = $now !== null ? (int)$now : time();
        $existing = Db::name('chat_contacts')->where(['user_id' => $userId, 'peer_user_id' => $peerId])->find();
        if ($existing) {
            if ((int)$existing['status'] !== 1) {
                Db::name('chat_contacts')->where('id', (int)$existing['id'])->update(['status' => 1]);
            }
            return;
        }
        Db::name('chat_contacts')->insert([
            'user_id'      => $userId,
            'peer_user_id' => $peerId,
            'status'       => 1,
            'createtime'   => $now,
        ]);
    }

    protected static function insertPrivateText($fromUserId, $toUserId, $content, $now)
    {
        $a = min((int)$fromUserId, (int)$toUserId);
        $b = max((int)$fromUserId, (int)$toUserId);
        $conv = $a . '_' . $b;
        $msgId = 'm' . $now . mt_rand(100000, 999999) . substr(md5(uniqid('', true)), 0, 8);
        Db::name('chat_messages')->insert([
            'msg_id'            => $msgId,
            'conversation_type' => 1,
            'conversation_id'   => $conv,
            'group_id'          => 0,
            'from_user_id'      => (int)$fromUserId,
            'to_user_id'        => (int)$toUserId,
            'msg_type'          => 1,
            'content'           => mb_substr((string)$content, 0, 2000),
            'extra'             => null,
            'status'            => 1,
            'createtime'        => (int)$now,
        ]);
    }
}
