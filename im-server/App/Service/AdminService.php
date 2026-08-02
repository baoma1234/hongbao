<?php

namespace Im\Service;

use Im\Support\Db;
use Im\Support\IdGenerator;
use Im\Support\RedisClient;

/**
 * IM 可私聊管理员 = fa_chat_agent_accounts 启用账号
 */
class AdminService
{
    /** 默认客服固定会员 ID（与 PHP FansHubDefaultCs 一致） */
    const DEFAULT_CS_USER_ID = 88888888;

    /** @var array<int,true>|null */
    protected static $adminIdMap = null;
    /** @var array<int, array{user_id:int,label:string}>|null */
    protected static $adminRowsCache = null;
    /** @var int */
    protected static $adminRowsAt = 0;

    public static function defaultCsUserId()
    {
        return self::DEFAULT_CS_USER_ID;
    }

    public static function isDefaultCs($userId)
    {
        return (int)$userId === self::DEFAULT_CS_USER_ID;
    }

    public static function isImAdmin($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return false;
        }
        return isset(self::adminIdMap()[$userId]);
    }

    /**
     * @return array<int,true>
     */
    public static function adminIdMap()
    {
        if (self::$adminIdMap !== null) {
            return self::$adminIdMap;
        }
        self::$adminIdMap = [];
        foreach (array_keys(self::adminRows()) as $id) {
            self::$adminIdMap[(int)$id] = true;
        }
        return self::$adminIdMap;
    }

    /** 后台改客服账号后可清缓存 */
    public static function clearAdminCache()
    {
        self::$adminIdMap = null;
        self::$adminRowsCache = null;
        self::$adminRowsAt = 0;
        try {
            RedisClient::conn()->del(RedisClient::key('admin:rows'));
        } catch (\Throwable $e) {
        }
    }

    /**
     * @return int[]
     */
    public static function adminUserIds()
    {
        return array_map('intval', array_keys(self::adminRows()));
    }

    /**
     * @return array<int, array{user_id:int,label:string}>
     */
    public static function adminRows()
    {
        if (self::$adminRowsCache !== null && (time() - self::$adminRowsAt) < 60) {
            return self::$adminRowsCache;
        }
        try {
            $raw = RedisClient::conn()->get(RedisClient::key('admin:rows'));
            if ($raw) {
                $j = json_decode((string)$raw, true);
                if (is_array($j)) {
                    $out = [];
                    foreach ($j as $uid => $meta) {
                        $uid = (int)$uid;
                        if ($uid <= 0 || !is_array($meta)) {
                            continue;
                        }
                        $out[$uid] = [
                            'user_id' => $uid,
                            'label'   => (string)($meta['label'] ?? ''),
                        ];
                    }
                    self::$adminRowsCache = $out;
                    self::$adminRowsAt = time();
                    self::$adminIdMap = null; // 下次从 rows 重建
                    return $out;
                }
            }
        } catch (\Throwable $e) {
        }

        $rows = Db::fetchAll(
            'SELECT user_id, label FROM ' . Db::table('chat_agent_accounts')
            . ' WHERE status=1 ORDER BY id ASC'
        );
        $out = [];
        foreach ($rows as $row) {
            $uid = (int)$row['user_id'];
            if ($uid <= 0) {
                continue;
            }
            $out[$uid] = [
                'user_id' => $uid,
                'label'   => (string)($row['label'] ?? ''),
            ];
        }
        self::$adminRowsCache = $out;
        self::$adminRowsAt = time();
        try {
            RedisClient::conn()->setex(RedisClient::key('admin:rows'), 60, json_encode($out, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
        }
        return $out;
    }

    /**
     * 普通用户只能私聊管理员；管理员可私聊任意用户
     */
    public static function assertCanPrivateChat($fromUserId, $toUserId)
    {
        $fromUserId = (int)$fromUserId;
        $toUserId = (int)$toUserId;
        if (self::isImAdmin($fromUserId) || self::isImAdmin($toUserId)) {
            return;
        }
        $contacts = new ContactService();
        if ($contacts->isFriend($fromUserId, $toUserId)) {
            return;
        }
        throw new \RuntimeException('private chat only with admin or friend');
    }

    /**
     * 客服号被加好友时的自动回复语（账号级优先，否则默认）
     */
    public static function csFriendReply($adminUserId)
    {
        $adminUserId = (int)$adminUserId;
        if ($adminUserId <= 0) {
            return '';
        }
        $row = Db::fetch(
            'SELECT friend_reply FROM ' . Db::table('chat_agent_accounts')
            . ' WHERE user_id=? AND status=1 LIMIT 1',
            [$adminUserId]
        );
        $text = trim((string)($row['friend_reply'] ?? ''));
        if ($text !== '') {
            return mb_substr($text, 0, 500);
        }
        // 全局默认（可被后台「客服加友回复」写入 config 表/文件；此处兜底）
        $cfgFile = dirname(__DIR__, 3) . '/application/extra/fanshub.php';
        if (is_file($cfgFile)) {
            $cfg = include $cfgFile;
            if (is_array($cfg)) {
                $def = trim((string)($cfg['im_cs_friend_reply'] ?? ''));
                if ($def !== '') {
                    return mb_substr($def, 0, 500);
                }
                $welcome = trim((string)($cfg['h5_copy']['chat_admin_welcome'] ?? ''));
                if ($welcome !== '') {
                    return mb_substr($welcome, 0, 500);
                }
            }
        }
        return '您好，我是平台客服，有问题随时私聊我。';
    }

    /**
     * 给新用户写入每位管理员的欢迎私聊（会话列表可见）
     */
    public static function seedWelcomeMessages($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return 0;
        }
        $admins = self::adminUserIds();
        $msgTable = Db::table('chat_messages');
        $done = 0;
        $now = time();
        foreach ($admins as $adminId) {
            if ($adminId === $userId) {
                continue;
            }
            $conv = IdGenerator::privateConversationId($adminId, $userId);
            $exists = Db::fetch(
                "SELECT id FROM {$msgTable} WHERE conversation_type=1 AND conversation_id=? AND status=1 LIMIT 1",
                [$conv]
            );
            if ($exists) {
                continue;
            }
            $msgId = IdGenerator::msgId();
            $welcome = self::csFriendReply($adminId);
            if ($welcome === '') {
                $welcome = '您好，我是平台客服，有问题随时私聊我。';
            }
            Db::exec(
                "INSERT INTO {$msgTable}
                (msg_id,conversation_type,conversation_id,group_id,from_user_id,to_user_id,msg_type,content,extra,status,createtime)
                VALUES (?,?,?,0,?,?,1,?,NULL,1,?)",
                [
                    $msgId,
                    1,
                    $conv,
                    $adminId,
                    $userId,
                    $welcome,
                    $now,
                ]
            );
            $done++;
        }
        return $done;
    }
}
