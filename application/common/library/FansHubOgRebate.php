<?php

namespace app\common\library;

use think\Db;

/**
 * OG 视讯投注返水：有效投注（非平局退本）日累计 × 比例，大厅领取昨日返水。
 */
class FansHubOgRebate
{
    const LEDGER_TYPE = 'og_rebate';

    /** @var bool|null */
    protected static $tableReady = null;

    public static function ensureTable()
    {
        if (self::$tableReady === true) {
            return true;
        }
        if (self::$tableReady === false) {
            return false;
        }
        try {
            $prefix = (string)(config('database.prefix') ?: 'fa_');
            $table = $prefix . 'fans_og_rebate_claim';
            $ok = !empty(Db::query("SHOW TABLES LIKE '{$table}'"));
            if (!$ok) {
                Db::execute(
                    "CREATE TABLE `{$table}` (
                      `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                      `user_id` int(10) unsigned NOT NULL DEFAULT 0,
                      `biz_date` char(10) NOT NULL DEFAULT '' COMMENT '返水归属日 Y-m-d（昨日）',
                      `bet_amount` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT '有效投注累计',
                      `rate` decimal(8,4) NOT NULL DEFAULT 0.0000 COMMENT '返水比例小数',
                      `rebate_amount` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT '领取金额',
                      `ledger_id` int(10) unsigned NOT NULL DEFAULT 0,
                      `createtime` int(10) unsigned NOT NULL DEFAULT 0,
                      PRIMARY KEY (`id`),
                      UNIQUE KEY `uk_user_date` (`user_id`,`biz_date`),
                      KEY `idx_date` (`biz_date`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='OG视讯昨日返水领取'"
                );
            }
            self::$tableReady = true;
            return true;
        } catch (\Throwable $e) {
            self::$tableReady = false;
            return false;
        }
    }

    /**
     * @return array{enabled:bool,rate:float,rate_percent:float}
     */
    public static function rateConfig()
    {
        $cfg = FansHubService::config();
        $enabled = !isset($cfg['og_rebate_enabled']) || !empty($cfg['og_rebate_enabled']);
        $rate = (float)($cfg['og_rebate_rate'] ?? 0.01);
        if ($rate < 0) {
            $rate = 0;
        }
        if ($rate > 1) {
            // 兼容误填百分数（如 1 表示 1%）
            if ($rate <= 100) {
                $rate = round($rate / 100, 4);
            } else {
                $rate = 1;
            }
        }
        return [
            'enabled'      => $enabled && $rate > 0,
            'rate'         => round($rate, 4),
            'rate_percent' => round($rate * 100, 2),
        ];
    }

    /**
     * 业务日时间窗（站点时区）
     * @return array{0:int,1:int} [startTs, endTsExclusive)
     */
    public static function dayWindow($ymd)
    {
        $ymd = trim((string)$ymd);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
            $ymd = date('Y-m-d', strtotime('-1 day'));
        }
        $tz = new \DateTimeZone(date_default_timezone_get() ?: 'Asia/Shanghai');
        $start = new \DateTime($ymd . ' 00:00:00', $tz);
        $end = clone $start;
        $end->modify('+1 day');
        return [(int)$start->getTimestamp(), (int)$end->getTimestamp()];
    }

    public static function yesterdayYmd()
    {
        return date('Y-m-d', strtotime('-1 day'));
    }

    /**
     * 有效投注累计：成功结算且有效投注额>0（平局退本 effective≈0 自然排除）
     */
    public static function sumEligibleBets($userId, $ymd)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return 0.0;
        }
        list($start, $end) = self::dayWindow($ymd);
        try {
            $row = Db::query(
                'SELECT SUM(effective_amount) AS s FROM ' . (config('database.prefix') ?: 'fa_') . 'fans_og_bet'
                . ' WHERE user_id=?'
                . ' AND effective_amount > 0.005'
                . ' AND IFNULL(rollback_at,0)=0'
                . ' AND IFNULL(cancel_at,0)=0'
                . ' AND ('
                . '   (credit_at >= ? AND credit_at < ?)'
                . '   OR (IFNULL(credit_at,0)=0 AND debit_at >= ? AND debit_at < ?)'
                . ' )',
                [$userId, $start, $end, $start, $end]
            );
            return round(max(0, (float)($row[0]['s'] ?? 0)), 2);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    /** 历史总有效投注（不限日期） */
    public static function sumEligibleBetsTotal($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return 0.0;
        }
        try {
            $row = Db::query(
                'SELECT SUM(effective_amount) AS s FROM ' . (config('database.prefix') ?: 'fa_') . 'fans_og_bet'
                . ' WHERE user_id=?'
                . ' AND effective_amount > 0.005'
                . ' AND IFNULL(rollback_at,0)=0'
                . ' AND IFNULL(cancel_at,0)=0',
                [$userId]
            );
            return round(max(0, (float)($row[0]['s'] ?? 0)), 2);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    /**
     * @return array{biz_date:string,bet_amount:float,rate:float,rate_percent:float,rebate_amount:float,claimed:bool,claimable:bool,enabled:bool}
     */
    public static function infoForUser($userId)
    {
        $userId = (int)$userId;
        $rc = self::rateConfig();
        $bizDate = self::yesterdayYmd();
        $bet = self::sumEligibleBets($userId, $bizDate);
        $rebate = $rc['enabled'] ? round($bet * $rc['rate'], 2) : 0.0;
        if ($rebate < 0.01) {
            $rebate = 0.0;
        }
        $claimed = false;
        if ($userId > 0 && self::ensureTable()) {
            try {
                $claimed = (bool)Db::name('fans_og_rebate_claim')
                    ->where('user_id', $userId)
                    ->where('biz_date', $bizDate)
                    ->value('id');
            } catch (\Throwable $e) {
                $claimed = false;
            }
        }
        return [
            'enabled'       => $rc['enabled'],
            'biz_date'      => $bizDate,
            'bet_amount'    => $bet,
            'rate'          => $rc['rate'],
            'rate_percent'  => $rc['rate_percent'],
            'rebate_amount' => $rebate,
            'claimed'       => $claimed,
            'claimable'     => $rc['enabled'] && !$claimed && $rebate >= 0.01,
        ];
    }

    /**
     * 领取昨日返水：入账红宝，并等额增加待打流水
     * @return array
     */
    public static function claimYesterday($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            throw new \RuntimeException('请先登录');
        }
        if (!self::ensureTable()) {
            throw new \RuntimeException('返水功能暂不可用');
        }
        $rc = self::rateConfig();
        if (!$rc['enabled']) {
            throw new \RuntimeException('返水未开启');
        }
        $bizDate = self::yesterdayYmd();
        $bet = self::sumEligibleBets($userId, $bizDate);
        $amount = round($bet * $rc['rate'], 2);
        if ($amount < 0.01) {
            throw new \RuntimeException('昨日暂无返水可领');
        }

        Db::startTrans();
        try {
            $exist = Db::name('fans_og_rebate_claim')
                ->where('user_id', $userId)
                ->where('biz_date', $bizDate)
                ->lock(true)
                ->find();
            if ($exist) {
                throw new \RuntimeException('昨日返水已领取');
            }
            $now = time();
            // 入账并计入待打流水（与领取金额一致）
            $chg = FansHubHongbaoLedger::credit(
                $userId,
                $amount,
                self::LEDGER_TYPE,
                'OG视讯返水 ' . $bizDate,
                [
                    'channel'         => 'og_rebate',
                    'biz_no'          => 'OGRB' . str_replace('-', '', $bizDate) . $userId,
                    'ref_type'        => 'og_rebate',
                    'ref_id'          => 0,
                    'count_turnover'  => 1,
                ]
            );
            $ledgerId = 0;
            try {
                $ledgerId = (int)Db::name('fans_ledger')
                    ->where('user_id', $userId)
                    ->where('type', self::LEDGER_TYPE)
                    ->where('biz_no', 'OGRB' . str_replace('-', '', $bizDate) . $userId)
                    ->order('id', 'desc')
                    ->value('id');
            } catch (\Throwable $eLed) {
            }
            Db::name('fans_og_rebate_claim')->insert([
                'user_id'       => $userId,
                'biz_date'      => $bizDate,
                'bet_amount'    => $bet,
                'rate'          => $rc['rate'],
                'rebate_amount' => $amount,
                'ledger_id'     => $ledgerId,
                'createtime'    => $now,
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            $msg = $e->getMessage() ?: '领取失败';
            if (stripos($msg, 'Duplicate') !== false || stripos($msg, 'uk_user_date') !== false) {
                throw new \RuntimeException('昨日返水已领取');
            }
            throw $e instanceof \RuntimeException ? $e : new \RuntimeException($msg);
        }
        FansHubImCache::bustWallet($userId);
        return [
            'biz_date'      => $bizDate,
            'bet_amount'    => $bet,
            'rate'          => $rc['rate'],
            'rate_percent'  => $rc['rate_percent'],
            'rebate_amount' => $amount,
            'hongbao_after' => isset($chg['after']) ? round((float)$chg['after'], 2) : null,
            'claimed'       => true,
            'claimable'     => false,
        ];
    }
}
