<?php
/**
 * 福利群（默认群80）红包每日领取配额
 *
 * - 独立表 fa_fans_welfare_rp_daily，不统计旧领取明细
 * - 每日免费 N 次（默认10），00:00 按自然日切换
 * - 娱乐群发/抢每满 M 次（默认5）+1 领取机会
 * - 后台可改每人次数与全局免费总次数
 */
namespace Im\Service;

use Im\Support\CatchLog;
use Im\Support\Db;

class WelfareRpQuotaService
{
    const MSG_LIMIT = '今日福利红包领取上限';

    /** @var array|null */
    protected static $cfgCache = null;
    /** @var int */
    protected static $cfgAt = 0;

    protected static function loadCfg()
    {
        if (self::$cfgCache !== null && (time() - self::$cfgAt) < 30) {
            return self::$cfgCache;
        }
        $out = [
            'enabled'            => true,
            'group_ids'          => [80],
            'daily_free'         => 10,
            'entertain_per_bonus'=> 5,
        ];
        $cfgFile = dirname(__DIR__, 3) . '/application/extra/fanshub.php';
        if (is_file($cfgFile)) {
            try {
                $cfg = include $cfgFile;
                if (is_array($cfg)) {
                    if (array_key_exists('welfare_rp_quota_enabled', $cfg)) {
                        $out['enabled'] = !empty($cfg['welfare_rp_quota_enabled']);
                    }
                    $gids = $cfg['welfare_rp_group_ids'] ?? null;
                    if (!is_array($gids) || !$gids) {
                        $gids = $cfg['recharge_free_claim_group_ids'] ?? [80];
                    }
                    $ids = [];
                    foreach ((array)$gids as $id) {
                        $id = (int)$id;
                        if ($id > 0) {
                            $ids[] = $id;
                        }
                    }
                    if ($ids) {
                        $out['group_ids'] = array_values(array_unique($ids));
                    }
                    $free = (int)($cfg['welfare_rp_daily_free'] ?? 10);
                    $out['daily_free'] = max(0, $free);
                    $per = (int)($cfg['welfare_rp_entertain_per_bonus'] ?? 5);
                    $out['entertain_per_bonus'] = max(1, $per);
                }
            } catch (\Throwable $e) {
                CatchLog::quiet($e, 'Service.WelfareRpQuotaService');
            }
        }
        self::$cfgCache = $out;
        self::$cfgAt = time();
        return $out;
    }

    public static function isEnabled()
    {
        return !empty(self::loadCfg()['enabled']);
    }

    public static function welfareGroupIds()
    {
        return self::loadCfg()['group_ids'];
    }

    public static function dailyFreeLimit()
    {
        return (int)self::loadCfg()['daily_free'];
    }

    public static function entertainPerBonus()
    {
        return (int)self::loadCfg()['entertain_per_bonus'];
    }

    public static function todayYmd()
    {
        return date('Ymd');
    }

    public static function isWelfarePacket(array $packet)
    {
        $gid = (int)($packet['group_id'] ?? 0);
        if ($gid <= 0) {
            return false;
        }
        $scope = (int)($packet['scope_type'] ?? 0);
        if ($scope !== 0 && $scope !== 2) {
            return false;
        }
        return in_array($gid, self::welfareGroupIds(), true);
    }

    public static function isWelfareGroupId($groupId)
    {
        $groupId = (int)$groupId;
        return $groupId > 0 && in_array($groupId, self::welfareGroupIds(), true);
    }

    /**
     * @param array $opts robot_* / trusted_robot
     */
    public static function assertCanClaim($userId, array $packet, array $opts = [])
    {
        if (!self::isEnabled()) {
            return;
        }
        $userId = (int)$userId;
        if ($userId <= 0 || !self::isWelfarePacket($packet)) {
            return;
        }
        if (RechargePrivilegeService::isPrivilegedActor($userId, $opts)) {
            return;
        }
        $snap = self::ensureRow($userId);
        if ((int)$snap['claim_count'] >= (int)$snap['effective_limit']) {
            throw new \RuntimeException(self::MSG_LIMIT);
        }
    }

    /**
     * 福利群抢成功后：独立表 claim_count +1
     */
    public static function onWelfareClaimed($userId, array $packet, array $opts = [])
    {
        if (!self::isEnabled()) {
            return;
        }
        $userId = (int)$userId;
        if ($userId <= 0 || !self::isWelfarePacket($packet)) {
            return;
        }
        if (RechargePrivilegeService::isPrivilegedActor($userId, $opts)) {
            return;
        }
        try {
            $table = Db::table('fans_welfare_rp_daily');
            $ymd = self::todayYmd();
            $now = time();
            self::ensureRow($userId);
            Db::exec(
                "UPDATE {$table} SET claim_count=claim_count+1, updatetime=? WHERE user_id=? AND quota_date=?",
                [$now, $userId, $ymd]
            );
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.WelfareRpQuotaService.onWelfareClaimed');
        }
    }

    /**
     * 娱乐群发/抢：entertain_count +1（满 N 次自动体现在 effective_limit）
     */
    public static function onEntertainmentAction($userId, $groupId, array $opts = [])
    {
        if (!self::isEnabled()) {
            return;
        }
        $userId = (int)$userId;
        $groupId = (int)$groupId;
        if ($userId <= 0 || $groupId <= 0) {
            return;
        }
        if (self::isWelfareGroupId($groupId)) {
            return;
        }
        if (RechargePrivilegeService::isPrivilegedActor($userId, $opts)) {
            return;
        }
        try {
            $table = Db::table('fans_welfare_rp_daily');
            $ymd = self::todayYmd();
            $now = time();
            self::ensureRow($userId);
            Db::exec(
                "UPDATE {$table} SET entertain_count=entertain_count+1, updatetime=? WHERE user_id=? AND quota_date=?",
                [$now, $userId, $ymd]
            );
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.WelfareRpQuotaService.onEntertainmentAction');
        }
    }

    /**
     * @return array{user_id:int,quota_date:string,claim_count:int,entertain_count:int,admin_extra:int,free_limit:int,bonus_chance:int,effective_limit:int,remain:int}
     */
    public static function ensureRow($userId)
    {
        $userId = (int)$userId;
        $ymd = self::todayYmd();
        $table = Db::table('fans_welfare_rp_daily');
        $row = Db::fetch(
            "SELECT * FROM {$table} WHERE user_id=? AND quota_date=? LIMIT 1",
            [$userId, $ymd]
        );
        if (!$row) {
            $now = time();
            try {
                Db::exec(
                    "INSERT INTO {$table} (user_id,quota_date,claim_count,entertain_count,admin_extra,free_limit,createtime,updatetime)"
                    . " VALUES (?,?,0,0,0,0,?,?)",
                    [$userId, $ymd, $now, $now]
                );
            } catch (\Throwable $e) {
                // 并发唯一键：再读
            }
            $row = Db::fetch(
                "SELECT * FROM {$table} WHERE user_id=? AND quota_date=? LIMIT 1",
                [$userId, $ymd]
            );
        }
        return self::decorateRow($row ?: [
            'user_id'          => $userId,
            'quota_date'       => $ymd,
            'claim_count'      => 0,
            'entertain_count'  => 0,
            'admin_extra'      => 0,
            'free_limit'       => 0,
        ]);
    }

    public static function decorateRow(array $row)
    {
        $per = self::entertainPerBonus();
        $entertain = max(0, (int)($row['entertain_count'] ?? 0));
        $bonus = $per > 0 ? intdiv($entertain, $per) : 0;
        $freePersonal = (int)($row['free_limit'] ?? 0);
        $free = $freePersonal > 0 ? $freePersonal : self::dailyFreeLimit();
        $extra = (int)($row['admin_extra'] ?? 0);
        $effective = max(0, $free + $bonus + $extra);
        $claimed = max(0, (int)($row['claim_count'] ?? 0));
        $row['bonus_chance'] = $bonus;
        $row['effective_limit'] = $effective;
        $row['remain'] = max(0, $effective - $claimed);
        $row['global_free'] = self::dailyFreeLimit();
        $row['entertain_per_bonus'] = $per;
        return $row;
    }
}
