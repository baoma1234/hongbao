<?php

namespace app\common\library;

use app\common\model\User;
use fast\Random;
use think\Db;

/**
 * 默认客服：ID 88888888 / 红宝客服
 * - 确保账号与 IM 托管存在
 * - 新用户（及老用户补齐）自动互为好友、会话置顶、不可删除
 */
class FansHubDefaultCs
{
    const USER_ID = 88888888;
    const MOBILE = '18811111111';
    const NICKNAME = '红宝客服';
    const USERNAME = '18811111111';

    public static function userId()
    {
        $id = (int)FansHubService::config('default_cs_user_id', self::USER_ID);
        return $id > 0 ? $id : self::USER_ID;
    }

    public static function mobile()
    {
        $m = trim((string)FansHubService::config('default_cs_mobile', self::MOBILE));
        return $m !== '' ? FansHubMobile::canonical($m) : self::MOBILE;
    }

    public static function nickname()
    {
        $n = trim((string)FansHubService::config('default_cs_nickname', self::NICKNAME));
        return $n !== '' ? $n : self::NICKNAME;
    }

    public static function isDefaultCs($userId)
    {
        return (int)$userId === self::userId();
    }

    /**
     * 确保客服会员 + 托管账号 + 钱包账户存在
     */
    public static function ensureAccount()
    {
        $id = self::userId();
        $mobile = self::mobile();
        $nick = self::nickname();
        $now = time();

        $user = User::get($id);
        if (!$user) {
            $byMobile = User::getByMobile($mobile);
            if ($byMobile && (int)$byMobile->id !== $id) {
                // 手机号已被占用且不是本 ID：仍创建固定 ID 账号，手机号留空避免冲突
                $mobileForCreate = '';
            } else {
                $mobileForCreate = $mobile;
            }
            $salt = Random::alnum();
            $password = Random::alnum(16);
            $auth = \app\common\library\Auth::instance();
            try {
                User::create([
                    'id'         => $id,
                    'username'   => self::USERNAME,
                    'nickname'   => $nick,
                    'password'   => $auth->getEncryptPassword($password, $salt),
                    'salt'       => $salt,
                    'email'      => '',
                    'mobile'     => $mobileForCreate,
                    'avatar'     => '',
                    'level'      => 1,
                    'score'      => 0,
                    'jointime'   => $now,
                    'joinip'     => '127.0.0.1',
                    'logintime'  => $now,
                    'loginip'    => '127.0.0.1',
                    'prevtime'   => $now,
                    'status'     => 'normal',
                    'createtime' => $now,
                    'updatetime' => $now,
                ], true);
            } catch (\Throwable $e) {
                // 并发下可能已创建
                $user = User::get($id);
                if (!$user) {
                    throw $e;
                }
            }
            $user = User::get($id);
        }

        if ($user) {
            $needSave = false;
            if ((string)$user->nickname !== $nick) {
                $user->nickname = $nick;
                $needSave = true;
            }
            if ((string)$user->status !== 'normal') {
                $user->status = 'normal';
                $needSave = true;
            }
            $curMobile = trim((string)$user->mobile);
            if ($curMobile === '' || $curMobile === $mobile) {
                $conflict = User::getByMobile($mobile);
                if (!$conflict || (int)$conflict->id === $id) {
                    if ($curMobile !== $mobile) {
                        $user->mobile = $mobile;
                        $needSave = true;
                    }
                }
            }
            if ($needSave) {
                try {
                    $user->save();
                } catch (\Throwable $e) {
                }
            }
        }

        try {
            FansHubService::getOrCreateAccount($id);
        } catch (\Throwable $e) {
        }

        $agent = Db::name('chat_agent_accounts')->where('user_id', $id)->find();
        $reply = (string)FansHubService::config('im_cs_friend_reply', '您好，我是平台客服，有问题随时私聊我。');
        if (!$agent) {
            try {
                Db::name('chat_agent_accounts')->insert([
                    'user_id'      => $id,
                    'admin_id'     => 0,
                    'label'        => $nick,
                    'scope'        => 'all',
                    'friend_reply' => mb_substr($reply, 0, 500),
                    'status'       => 1,
                    'createtime'   => $now,
                    'updatetime'   => $now,
                ]);
            } catch (\Throwable $e) {
            }
        } else {
            $upd = ['updatetime' => $now];
            if ((int)($agent['status'] ?? 0) !== 1) {
                $upd['status'] = 1;
            }
            if (trim((string)($agent['label'] ?? '')) === '' || (string)$agent['label'] !== $nick) {
                $upd['label'] = $nick;
            }
            try {
                Db::name('chat_agent_accounts')->where('id', (int)$agent['id'])->update($upd);
            } catch (\Throwable $e2) {
            }
        }

        return $id;
    }

    /**
     * 为用户补齐默认客服好友（双向）+ 欢迎私信 + 会话置顶
     */
    public static function ensureFriendForUser($userId)
    {
        $userId = (int)$userId;
        $csId = self::userId();
        if ($userId <= 0 || $userId === $csId) {
            return false;
        }
        try {
            self::ensureAccount();
        } catch (\Throwable $e) {
            return false;
        }
        $now = time();
        FansHubFriend::ensureMutual($userId, $csId, $now);

        // 欢迎语（若尚无任何私聊消息）
        try {
            FansHubService::seedImAdminConversations($userId);
        } catch (\Throwable $e2) {
        }

        self::pinPrivateConversation($userId, $csId);
        self::unhidePrivateConversation($userId, $csId);
        return true;
    }

    /** 若用户曾删除客服会话，恢复显示 */
    public static function unhidePrivateConversation($userId, $peerId)
    {
        $userId = (int)$userId;
        $peerId = (int)$peerId;
        if ($userId <= 0 || $peerId <= 0) {
            return;
        }
        $a = min($userId, $peerId);
        $b = max($userId, $peerId);
        $cid = $a . '_' . $b;
        try {
            Db::name('chat_conversation_deleted')
                ->where([
                    'user_id'           => $userId,
                    'conversation_type' => 1,
                    'conversation_id'   => $cid,
                    'restored_at'       => 0,
                ])
                ->update(['restored_at' => time()]);
        } catch (\Throwable $e) {
        }
        try {
            $r = FansHubOfficialStats::redisPublic();
            if ($r) {
                $r->sRem('im:hidden:' . $userId, $cid);
            }
        } catch (\Throwable $e2) {
        }
    }

    /** Redis 置顶私聊会话（与 IM MessageService 一致） */
    public static function pinPrivateConversation($userId, $peerId)
    {
        $userId = (int)$userId;
        $peerId = (int)$peerId;
        if ($userId <= 0 || $peerId <= 0) {
            return;
        }
        $a = min($userId, $peerId);
        $b = max($userId, $peerId);
        $member = '1:' . $a . '_' . $b;
        try {
            $r = FansHubOfficialStats::redisPublic();
            if (!$r) {
                return;
            }
            $key = 'im:pins:' . $userId;
            // 用很大的 score，保证客服置顶排最前，且不易被挤掉
            $r->zAdd($key, time() + 86400 * 3650, $member);
            $r->zRemRangeByRank($key, 0, -21);
            $r->expire($key, 86400 * 3650);
        } catch (\Throwable $e) {
        }
    }
}
