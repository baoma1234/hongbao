<?php

namespace Im\Service;

use Im\Support\CatchLog;
use Im\Support\Db;
use Im\Support\RedisClient;

/**
 * 未充值账号资金权限：
 * - 可社交
 * - 未充值：不能发私聊/非推荐群红包，不能发/收转账
 * - 未充值：可在推荐群(is_recommend=1)发/抢红包
 * - 未充值：可领取 recharge_free_claim_group_ids 指定群内任意红包；可收 recharge_free_claim_sender_ids 的私聊转账
 * - 已充值：私聊红包/转账不能发给未充值对方
 * - fund_bypass_user_ids（后台配置）：可任意发红包/转账，无视双方充值限制
 */
class RechargePrivilegeService
{
    const MSG_NEED_RECHARGE_SEND_RP = '未充值账号仅可在推荐群发红包，请先充值';
    const MSG_NEED_RECHARGE_SEND_RP_TO = '对方未充值，无法发红包';
    const MSG_NEED_RECHARGE_TRANSFER = '未充值账号不能转账，请先充值';
    const MSG_NEED_RECHARGE_RECEIVE_TRANSFER = '对方未充值，无法收款';
    const MSG_NEED_RECHARGE_GRAB = '未充值账号仅可领取推荐群红包，请先充值';

    /** @var array<int,array{ok:bool,at:float}> */
    protected static $mem = [];

    /** @var int[]|null */
    protected static $fundBypassIds = null;
    /** @var int */
    protected static $fundBypassAt = 0;

    /** @var int[]|null */
    protected static $freeClaimSenderIds = null;
    /** @var int[]|null */
    protected static $freeClaimGroupIds = null;
    /** @var int */
    protected static $freeClaimAt = 0;

    /**
     * 后台资金特权 UID（可任意发红包/转账，无视双方充值）
     * @return int[]
     */
    public static function fundBypassUserIds()
    {
        if (self::$fundBypassIds !== null && (time() - self::$fundBypassAt) < 30) {
            return self::$fundBypassIds;
        }
        $ids = [];
        $cfgFile = dirname(__DIR__, 3) . '/application/extra/fanshub.php';
        if (is_file($cfgFile)) {
            try {
                $cfg = include $cfgFile;
                if (is_array($cfg) && isset($cfg['fund_bypass_user_ids']) && is_array($cfg['fund_bypass_user_ids'])) {
                    foreach ($cfg['fund_bypass_user_ids'] as $id) {
                        $id = (int)$id;
                        if ($id > 0) {
                            $ids[] = $id;
                        }
                    }
                }
            } catch (\Throwable $e) {
                CatchLog::quiet($e, 'Service.RechargePrivilegeService');
            }
        }
        self::$fundBypassIds = array_values(array_unique($ids));
        self::$fundBypassAt = time();
        return self::$fundBypassIds;
    }

    public static function isFundBypassUser($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return false;
        }
        return in_array($userId, self::fundBypassUserIds(), true);
    }

    /**
     * 未充值可免费领取的群，以及可收其私聊转账的 UID
     * @return array{senders:int[],groups:int[]}
     */
    public static function freeClaimConfig()
    {
        if (self::$freeClaimSenderIds !== null && (time() - self::$freeClaimAt) < 30) {
            return [
                'senders' => self::$freeClaimSenderIds,
                'groups'  => self::$freeClaimGroupIds ?: [],
            ];
        }
        $senders = [];
        $groups = [];
        $cfgFile = dirname(__DIR__, 3) . '/application/extra/fanshub.php';
        if (is_file($cfgFile)) {
            try {
                $cfg = include $cfgFile;
                if (is_array($cfg)) {
                    if (!empty($cfg['recharge_free_claim_sender_ids']) && is_array($cfg['recharge_free_claim_sender_ids'])) {
                        foreach ($cfg['recharge_free_claim_sender_ids'] as $id) {
                            $id = (int)$id;
                            if ($id > 0) {
                                $senders[] = $id;
                            }
                        }
                    }
                    if (!empty($cfg['recharge_free_claim_group_ids']) && is_array($cfg['recharge_free_claim_group_ids'])) {
                        foreach ($cfg['recharge_free_claim_group_ids'] as $id) {
                            $id = (int)$id;
                            if ($id > 0) {
                                $groups[] = $id;
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                CatchLog::quiet($e, 'Service.RechargePrivilegeService');
            }
        }
        self::$freeClaimSenderIds = array_values(array_unique($senders));
        self::$freeClaimGroupIds = array_values(array_unique($groups));
        self::$freeClaimAt = time();
        return [
            'senders' => self::$freeClaimSenderIds,
            'groups'  => self::$freeClaimGroupIds,
        ];
    }

    public static function isFreeClaimSender($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return false;
        }
        $cfg = self::freeClaimConfig();
        return in_array($userId, $cfg['senders'], true);
    }

    /**
     * 指定群内任意红包：未充值也可领（不限发包人）
     * @param array $packet
     */
    public static function isFreeClaimRedPacket(array $packet)
    {
        $groupId = (int)($packet['group_id'] ?? 0);
        $scope = (int)($packet['scope_type'] ?? 0);
        if ($groupId <= 0) {
            return false;
        }
        if ($scope !== 0 && $scope !== 2) {
            return false;
        }
        $cfg = self::freeClaimConfig();
        if (!$cfg['groups']) {
            return false;
        }
        return in_array($groupId, $cfg['groups'], true);
    }

    public static function hasRecharged($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return false;
        }
        if (self::isFundBypassUser($userId)) {
            return true;
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
     * 机器人 / 可信代发 / 后台资金特权 UID：不受未充值与双方充值限制
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
        if (self::isFundBypassUser($userId)) {
            return true;
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
     * @param array $opts robot_* / scope_type / group_id / to_user_id
     */
    public static function assertCanSendRedPacket($userId, array $opts = [], GroupService $groups = null)
    {
        if (self::isPrivilegedActor($userId, $opts)) {
            return;
        }
        $scope = (int)($opts['scope_type'] ?? 0);
        $groupId = (int)($opts['group_id'] ?? 0);
        $toUserId = (int)($opts['to_user_id'] ?? 0);

        // 未充值：仅可在推荐群发红包
        if (!self::hasRecharged($userId)) {
            if ($scope === 2 && $groupId > 0 && self::isRecommendGroup($groupId, $groups)) {
                return;
            }
            throw new \RuntimeException(self::MSG_NEED_RECHARGE_SEND_RP);
        }
        // 已充值：私聊红包不能发给未充值对方
        if ($scope === 1 && $toUserId > 0) {
            if (self::isPrivilegedActor($toUserId)) {
                return;
            }
            if (!self::hasRecharged($toUserId)) {
                throw new \RuntimeException(self::MSG_NEED_RECHARGE_SEND_RP_TO);
            }
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
     * 收款方也须已充值，否则禁止转账入账。
     * @param int $userId 收款方
     * @param int $fromUserId 付款方（资金特权付款方可转给未充值对方）
     */
    public static function assertCanReceiveTransfer($userId, $fromUserId = 0)
    {
        if (self::isPrivilegedActor($userId)) {
            return;
        }
        if ($fromUserId > 0 && self::isPrivilegedActor((int)$fromUserId)) {
            return;
        }
        // 指定客服 UID 转出：未充值对方也可收款
        if ($fromUserId > 0 && self::isFreeClaimSender((int)$fromUserId)) {
            return;
        }
        if (!self::hasRecharged($userId)) {
            throw new \RuntimeException(self::MSG_NEED_RECHARGE_RECEIVE_TRANSFER);
        }
    }

    /**
     * @param array $packet chat_red_packets row or redis meta (scope_type, group_id, from_user_id)
     */
    public static function assertCanGrabRedPacket($userId, array $packet, GroupService $groups = null)
    {
        if (self::isPrivilegedActor($userId)) {
            return;
        }
        if (self::hasRecharged($userId)) {
            return;
        }
        // 未充值：指定福利群内任意红包可领
        if (self::isFreeClaimRedPacket($packet)) {
            return;
        }
        // 未充值：仅可抢推荐群红包
        $scope = (int)($packet['scope_type'] ?? 0);
        $groupId = (int)($packet['group_id'] ?? 0);
        if ($scope === 2 && $groupId > 0 && self::isRecommendGroup($groupId, $groups)) {
            return;
        }
        throw new \RuntimeException(self::MSG_NEED_RECHARGE_GRAB);
    }

    /** 是否官方推荐群（is_recommend=1） */
    protected static function isRecommendGroup($groupId, GroupService $groups = null)
    {
        $groupId = (int)$groupId;
        if ($groupId <= 0) {
            return false;
        }
        try {
            if ($groups) {
                $g = $groups->get($groupId);
                if (is_array($g) && OfficialStatsService::isOfficialRecommend($g)) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.RechargePrivilegeService');
        }
        try {
            $row = Db::fetch(
                'SELECT is_recommend FROM ' . Db::table('chat_groups') . ' WHERE id=? LIMIT 1',
                [$groupId]
            );
            return $row && (int)($row['is_recommend'] ?? 0) === 1;
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.RechargePrivilegeService');
            return false;
        }
    }
}
