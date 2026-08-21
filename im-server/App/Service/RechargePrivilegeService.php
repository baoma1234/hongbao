<?php

namespace Im\Service;

use Im\Support\CatchLog;
use Im\Support\Db;
use Im\Support\RedisClient;

/**
 * 未充值账号资金权限：
 * - 可社交、可收款（转账入账）
 * - 官方群可发/抢红包；私聊 / 非官方群红包仅可见不可领、不可发
 * - 不可转账
 * 官方群 = chat_groups.is_recommend=1
 */
class RechargePrivilegeService
{
    const MSG_NEED_RECHARGE_SEND_RP = '未充值账号仅可在官方群发红包，请先充值';
    const MSG_NEED_RECHARGE_TRANSFER = '未充值账号不能转账，请先充值';
    const MSG_NEED_RECHARGE_GRAB = '未充值账号仅可在官方群领取红包';

    /** @var array<int,array{ok:bool,at:float}> */
    protected static $mem = [];

    public static function hasRecharged($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return false;
        }
        $now = microtime(true);
        if (isset(self::$mem[$userId]) && ($now - (float)self::$mem[$userId]['at']) < 5.0) {
            return (bool)self::$mem[$userId]['ok'];
        }
        try {
            $r = RedisClient::conn();
            $raw = $r->get(RedisClient::key('has_recharged:' . $userId));
            if ($raw === '1' || $raw === '0') {
                $ok = $raw === '1';
                self::$mem[$userId] = ['ok' => $ok, 'at' => $now];
                return $ok;
            }
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.RechargePrivilegeService');
        }

        $ok = false;
        try {
            $row = Db::fetch(
                'SELECT has_recharged, is_bot FROM ' . Db::table('fans_account')
                . ' WHERE user_id=? OR id=? LIMIT 1',
                [$userId, $userId]
            );
            if ($row) {
                if ((int)($row['is_bot'] ?? 0) === 1) {
                    $ok = true;
                } elseif ((int)($row['has_recharged'] ?? 0) === 1) {
                    $ok = true;
                }
            }
            if (!$ok) {
                $paid = Db::fetch(
                    'SELECT id FROM ' . Db::table('fans_recharge_order')
                    . " WHERE user_id=? AND status='paid' LIMIT 1",
                    [$userId]
                );
                if ($paid) {
                    $ok = true;
                    self::markRecharged($userId);
                }
            }
        } catch (\Throwable $e) {
            // 无 has_recharged 列时回退订单表
            try {
                $paid = Db::fetch(
                    'SELECT id FROM ' . Db::table('fans_recharge_order')
                    . " WHERE user_id=? AND status='paid' LIMIT 1",
                    [$userId]
                );
                $ok = (bool)$paid;
            } catch (\Throwable $e2) {
                CatchLog::quiet($e2, 'Service.RechargePrivilegeService');
                $ok = false;
            }
        }

        self::cacheFlag($userId, $ok);
        return $ok;
    }

    public static function markRecharged($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return;
        }
        try {
            Db::execute(
                'UPDATE ' . Db::table('fans_account')
                . ' SET has_recharged=1 WHERE (user_id=? OR id=?) AND IFNULL(has_recharged,0)=0',
                [$userId, $userId]
            );
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.RechargePrivilegeService');
        }
        self::cacheFlag($userId, true);
    }

    public static function bustCache($userId)
    {
        unset(self::$mem[(int)$userId]);
        try {
            RedisClient::conn()->del(RedisClient::key('has_recharged:' . (int)$userId));
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.RechargePrivilegeService');
        }
    }

    public static function cacheFlag($userId, $ok)
    {
        $userId = (int)$userId;
        $ok = (bool)$ok;
        self::$mem[$userId] = ['ok' => $ok, 'at' => microtime(true)];
        try {
            RedisClient::conn()->setex(
                RedisClient::key('has_recharged:' . $userId),
                $ok ? 86400 * 7 : 60,
                $ok ? '1' : '0'
            );
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.RechargePrivilegeService');
        }
    }

    /**
     * 机器人 / 可信代发不受未充值限制
     */
    public static function isPrivilegedActor($userId, array $opts = [])
    {
        if (!empty($opts['robot_send']) || !empty($opts['trusted_robot']) || !empty($opts['robot_relay'])) {
            return true;
        }
        $userId = (int)$userId;
        if ($userId <= 0) {
            return false;
        }
        try {
            $row = Db::fetch(
                'SELECT is_bot FROM ' . Db::table('fans_account') . ' WHERE user_id=? OR id=? LIMIT 1',
                [$userId, $userId]
            );
            return $row && (int)($row['is_bot'] ?? 0) === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param array $opts robot_* / scope_type / group_id
     */
    public static function assertCanSendRedPacket($userId, array $opts = [], GroupService $groups = null)
    {
        if (self::isPrivilegedActor($userId, $opts)) {
            return;
        }
        if (self::hasRecharged($userId)) {
            return;
        }
        // 未充值：仅官方群可发
        $scope = (int)($opts['scope_type'] ?? 0);
        if ($scope === 1) {
            throw new \RuntimeException(self::MSG_NEED_RECHARGE_SEND_RP);
        }
        $groupId = (int)($opts['group_id'] ?? 0);
        if ($groupId <= 0) {
            throw new \RuntimeException(self::MSG_NEED_RECHARGE_SEND_RP);
        }
        $group = null;
        if ($groups) {
            $group = $groups->get($groupId);
        } else {
            try {
                $group = Db::fetch(
                    'SELECT id, is_recommend FROM ' . Db::table('chat_groups') . ' WHERE id=? LIMIT 1',
                    [$groupId]
                );
            } catch (\Throwable $e) {
                $group = null;
            }
        }
        if (!$group || !OfficialStatsService::isOfficialRecommend($group)) {
            throw new \RuntimeException(self::MSG_NEED_RECHARGE_SEND_RP);
        }
    }

    public static function assertCanSendTransfer($userId)
    {
        if (self::isPrivilegedActor($userId)) {
            return;
        }
        if (!self::hasRecharged($userId)) {
            throw new \RuntimeException(self::MSG_NEED_RECHARGE_TRANSFER);
        }
    }

    /**
     * @param array $packet chat_red_packets row or redis meta (scope_type, group_id)
     */
    public static function assertCanGrabRedPacket($userId, array $packet, GroupService $groups = null)
    {
        if (self::isPrivilegedActor($userId)) {
            return;
        }
        if (self::hasRecharged($userId)) {
            return;
        }
        $scope = (int)($packet['scope_type'] ?? 0);
        if ($scope === 1) {
            throw new \RuntimeException(self::MSG_NEED_RECHARGE_GRAB);
        }
        $groupId = (int)($packet['group_id'] ?? 0);
        if ($groupId <= 0) {
            throw new \RuntimeException(self::MSG_NEED_RECHARGE_GRAB);
        }
        $group = null;
        if ($groups) {
            $group = $groups->get($groupId);
        } else {
            try {
                $group = Db::fetch(
                    'SELECT id, is_recommend FROM ' . Db::table('chat_groups') . ' WHERE id=? LIMIT 1',
                    [$groupId]
                );
            } catch (\Throwable $e) {
                $group = null;
            }
        }
        if (!$group || !OfficialStatsService::isOfficialRecommend($group)) {
            throw new \RuntimeException(self::MSG_NEED_RECHARGE_GRAB);
        }
    }
}
