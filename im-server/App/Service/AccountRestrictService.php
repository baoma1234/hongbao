<?php

namespace Im\Service;

use Im\Support\CatchLog;
use Im\Support\Db;
use Im\Support\RedisClient;

/**
 * 账户业务限制：禁止返佣 / 禁止领红包雨
 */
class AccountRestrictService
{
    /** @var array<int,array{flags:array,at:float}> */
    protected static $mem = [];

    /**
     * @return array{deny_rebate:bool,deny_rp_rain:bool}
     */
    public static function getFlags($userId)
    {
        $userId = (int)$userId;
        $empty = ['deny_rebate' => false, 'deny_rp_rain' => false];
        if ($userId <= 0) {
            return $empty;
        }
        $now = microtime(true);
        if (isset(self::$mem[$userId]) && ($now - (float)self::$mem[$userId]['at']) < 5.0) {
            return self::$mem[$userId]['flags'];
        }
        try {
            $r = RedisClient::conn();
            $raw = $r->get(RedisClient::key('acct_restrict:' . $userId));
            if ($raw !== false && $raw !== null && $raw !== '') {
                $decoded = json_decode((string)$raw, true);
                if (is_array($decoded)) {
                    $flags = [
                        'deny_rebate'  => !empty($decoded['deny_rebate']),
                        'deny_rp_rain' => !empty($decoded['deny_rp_rain']),
                    ];
                    self::$mem[$userId] = ['flags' => $flags, 'at' => $now];
                    return $flags;
                }
            }
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.AccountRestrictService');
        }
        try {
            $row = Db::fetch(
                'SELECT deny_rebate, deny_rp_rain FROM ' . Db::table('fans_account')
                . ' WHERE user_id=? OR id=? LIMIT 1',
                [$userId, $userId]
            );
            $flags = [
                'deny_rebate'  => !empty($row['deny_rebate']),
                'deny_rp_rain' => !empty($row['deny_rp_rain']),
            ];
            self::cacheFlags($userId, $flags);
            return $flags;
        } catch (\Throwable $e) {
            return $empty;
        }
    }

    public static function cacheFlags($userId, array $flags)
    {
        $flags = [
            'deny_rebate'  => !empty($flags['deny_rebate']),
            'deny_rp_rain' => !empty($flags['deny_rp_rain']),
        ];
        self::$mem[(int)$userId] = ['flags' => $flags, 'at' => microtime(true)];
        try {
            $r = RedisClient::conn();
            $key = RedisClient::key('acct_restrict:' . (int)$userId);
            if ($flags['deny_rebate'] || $flags['deny_rp_rain']) {
                $r->setex($key, 86400 * 7, json_encode([
                    'deny_rebate'  => $flags['deny_rebate'] ? 1 : 0,
                    'deny_rp_rain' => $flags['deny_rp_rain'] ? 1 : 0,
                ], JSON_UNESCAPED_UNICODE));
            } else {
                $r->setex($key, 60, '{}');
            }
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.AccountRestrictService');
        }
    }

    public static function bustCache($userId)
    {
        unset(self::$mem[(int)$userId]);
        try {
            RedisClient::conn()->del(RedisClient::key('acct_restrict:' . (int)$userId));
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.AccountRestrictService');
        }
    }

    public static function isDenyRebate($userId)
    {
        return !empty(self::getFlags($userId)['deny_rebate']);
    }

    public static function isDenyRpRain($userId)
    {
        return !empty(self::getFlags($userId)['deny_rp_rain']);
    }

    /**
     * @throws \RuntimeException
     */
    public static function assertCanClaimRpRain($userId)
    {
        if (self::isDenyRpRain($userId)) {
            throw new \RuntimeException('账号已被禁止领取红包雨');
        }
    }

    /**
     * 是否红包雨包：Redis meta / 雨任务追踪 / 消息 extra
     */
    public static function isRainPacket($packetId, $groupId = 0)
    {
        $packetId = (int)$packetId;
        $groupId = (int)$groupId;
        if ($packetId <= 0) {
            return false;
        }
        try {
            $v = RedisClient::conn()->hGet(RedisClient::key('rp:' . $packetId . ':meta'), 'is_rain');
            if ($v !== false && $v !== null && (string)$v !== '' && (string)$v !== '0') {
                return true;
            }
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.AccountRestrictService');
        }
        try {
            $sql = 'SELECT round_packet_ids, last_packet_id FROM ' . Db::table('chat_rp_rain_task')
                . " WHERE status='normal'"
                . " AND (IFNULL(round_packet_ids,'')<>'' OR IFNULL(last_packet_id,0)>0)";
            $args = [];
            if ($groupId > 0) {
                $sql .= ' AND group_id=?';
                $args[] = $groupId;
            }
            $rows = Db::fetchAll($sql, $args) ?: [];
            foreach ($rows as $row) {
                if ((int)($row['last_packet_id'] ?? 0) === $packetId) {
                    return true;
                }
                $raw = trim((string)($row['round_packet_ids'] ?? ''));
                if ($raw === '') {
                    continue;
                }
                $parts = preg_split('/[,\s]+/', $raw) ?: [];
                foreach ($parts as $p) {
                    if ((int)$p === $packetId) {
                        return true;
                    }
                }
            }
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.AccountRestrictService');
        }
        try {
            $like = '%"packet_id":' . $packetId . '%';
            $msg = Db::fetch(
                'SELECT extra FROM ' . Db::table('chat_messages')
                . ' WHERE msg_type=2 AND extra LIKE ? ORDER BY id DESC LIMIT 1',
                [$like]
            );
            if ($msg) {
                $extra = $msg['extra'] ?? '';
                if (is_string($extra) && $extra !== '') {
                    $extra = json_decode($extra, true) ?: [];
                }
                if (is_array($extra)
                    && (int)($extra['packet_id'] ?? 0) === $packetId
                    && (!empty($extra['rain']) || !empty($extra['rain_task_id']))) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.AccountRestrictService');
        }
        return false;
    }
}
