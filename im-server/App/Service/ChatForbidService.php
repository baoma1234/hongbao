<?php

namespace Im\Service;

use Im\Support\CatchLog;

use Im\Support\Db;
use Im\Support\RedisClient;

/**
 * 全局聊天禁言（后台用户账户配置）
 * flags: text / image / sticker / video / rp_send / rp_grab
 */
class ChatForbidService
{
    const FLAGS = ['text', 'image', 'sticker', 'video', 'file', 'rp_send', 'rp_grab'];

    /** @var array<int,array{flags:array,at:float}> */
    protected static $mem = [];

    /** msg_type => flag */
    const MSG_TYPE_MAP = [
        1 => 'text',
        4 => 'image',
        5 => 'video',
        6 => 'sticker',
        7 => 'file',
    ];

    const LABELS = [
        'text'     => '禁止发文字',
        'image'    => '禁止发图片',
        'sticker'  => '禁止发表情',
        'video'    => '禁止发视频',
        'file'     => '禁止发文件',
        'rp_send'  => '禁止发红包',
        'rp_grab'  => '禁止领红包',
    ];

    /**
     * @return array<string,bool>
     */
    public static function getFlags($userId)
    {
        $userId = (int)$userId;
        $empty = self::emptyFlags();
        if ($userId <= 0) {
            return $empty;
        }
        $now = microtime(true);
        if (isset(self::$mem[$userId]) && ($now - (float)self::$mem[$userId]['at']) < 5.0) {
            return self::$mem[$userId]['flags'];
        }
        try {
            $r = RedisClient::conn();
            $raw = $r->get(RedisClient::key('chat_forbid:' . $userId));
            if ($raw !== false && $raw !== null && $raw !== '') {
                $flags = self::normalize($raw);
                self::$mem[$userId] = ['flags' => $flags, 'at' => $now];
                return $flags;
            }
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.ChatForbidService');
        }
        try {
            $row = Db::fetch(
                'SELECT chat_forbid FROM ' . Db::table('fans_account') . ' WHERE user_id=? OR id=? LIMIT 1',
                [$userId, $userId]
            );
            $flags = self::normalize($row['chat_forbid'] ?? '');
            self::cacheFlags($userId, $flags);
            return $flags;
        } catch (\Throwable $e) {
            return $empty;
        }
    }

    public static function cacheFlags($userId, array $flags)
    {
        $flags = self::normalize($flags);
        self::$mem[(int)$userId] = ['flags' => $flags, 'at' => microtime(true)];
        try {
            $r = RedisClient::conn();
            $key = RedisClient::key('chat_forbid:' . (int)$userId);
            if (self::anyForbidden($flags)) {
                $r->setex($key, 86400 * 7, json_encode($flags, JSON_UNESCAPED_UNICODE));
            } else {
                // 缓存空结果，避免打库；短 TTL
                $r->setex($key, 60, '{}');
            }
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.ChatForbidService');
        }
    }

    public static function bustCache($userId)
    {
        unset(self::$mem[(int)$userId]);
        try {
            RedisClient::conn()->del(RedisClient::key('chat_forbid:' . (int)$userId));
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.ChatForbidService');
        }
    }

    /**
     * @throws \RuntimeException
     */
    public static function assertCanSendMessage($userId, $msgType)
    {
        $flag = self::MSG_TYPE_MAP[(int)$msgType] ?? 'text';
        $flags = self::getFlags($userId);
        if (!empty($flags[$flag])) {
            throw new \RuntimeException(self::LABELS[$flag] ?? 'forbidden');
        }
    }

    public static function assertCanSendRedPacket($userId)
    {
        $flags = self::getFlags($userId);
        if (!empty($flags['rp_send'])) {
            throw new \RuntimeException(self::LABELS['rp_send']);
        }
    }

    public static function assertCanGrabRedPacket($userId)
    {
        $flags = self::getFlags($userId);
        // 禁止领红包，或禁言（禁止发文字）时不可领
        if (!empty($flags['rp_grab']) || !empty($flags['text'])) {
            throw new \RuntimeException(
                !empty($flags['rp_grab']) ? self::LABELS['rp_grab'] : '禁言中不可领取红包'
            );
        }
    }

    /** @return array<string,bool> */
    public static function emptyFlags()
    {
        $out = [];
        foreach (self::FLAGS as $f) {
            $out[$f] = false;
        }
        return $out;
    }

    /**
     * @param mixed $raw
     * @return array<string,bool>
     */
    public static function normalize($raw)
    {
        $out = self::emptyFlags();
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            } else {
                $parts = preg_split('/[,\s]+/', $raw) ?: [];
                $raw = [];
                foreach ($parts as $p) {
                    $p = trim((string)$p);
                    if ($p !== '') {
                        $raw[$p] = 1;
                    }
                }
            }
        }
        if (!is_array($raw)) {
            return $out;
        }
        foreach (self::FLAGS as $f) {
            $out[$f] = !empty($raw[$f]) && $raw[$f] !== '0' && $raw[$f] !== false;
        }
        return $out;
    }

    public static function anyForbidden(array $flags)
    {
        foreach (self::FLAGS as $f) {
            if (!empty($flags[$f])) {
                return true;
            }
        }
        return false;
    }

    public static function encode(array $flags)
    {
        $flags = self::normalize($flags);
        if (!self::anyForbidden($flags)) {
            return '';
        }
        $slim = [];
        foreach (self::FLAGS as $f) {
            if (!empty($flags[$f])) {
                $slim[$f] = 1;
            }
        }
        return json_encode($slim, JSON_UNESCAPED_UNICODE);
    }
}
