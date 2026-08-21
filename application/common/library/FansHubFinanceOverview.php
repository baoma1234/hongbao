<?php

namespace app\common\library;

use think\Db;

/**
 * 财务总览：日/周/月充提订单、成功率、人数
 */
class FansHubFinanceOverview
{
    /**
     * @return array
     */
    public static function build($now = null)
    {
        $now = $now !== null ? (int)$now : time();
        $tzDay = date('Y-m-d', $now);

        $ranges = [
            'today' => [
                'label' => '今日',
                'start' => strtotime($tzDay . ' 00:00:00'),
                'end'   => strtotime($tzDay . ' 23:59:59'),
            ],
            'week' => [
                'label' => '本周',
                // 周一 00:00 起
                'start' => strtotime('monday this week 00:00:00', $now),
                'end'   => strtotime($tzDay . ' 23:59:59'),
            ],
            'month' => [
                'label' => '本月',
                'start' => strtotime(date('Y-m-01 00:00:00', $now)),
                'end'   => strtotime($tzDay . ' 23:59:59'),
            ],
            'yesterday' => [
                'label' => '昨日',
                'start' => strtotime($tzDay . ' 00:00:00') - 86400,
                'end'   => strtotime($tzDay . ' 00:00:00') - 1,
            ],
        ];

        $periods = [];
        foreach ($ranges as $key => $range) {
            $recharge = self::aggregate('fans_recharge_order', 'recharge', $range['start'], $range['end']);
            $withdraw = self::aggregate('fans_withdraw_order', 'withdraw', $range['start'], $range['end']);
            $periods[$key] = [
                'key'      => $key,
                'label'    => $range['label'],
                'box'      => ['today' => 'primary', 'week' => 'success', 'month' => 'warning', 'yesterday' => 'default'][$key] ?? 'default',
                'start'    => $range['start'],
                'end'      => $range['end'],
                'start_txt'=> date('Y-m-d H:i', $range['start']),
                'end_txt'  => date('Y-m-d H:i', $range['end']),
                'recharge' => $recharge,
                'withdraw' => $withdraw,
                'net_paid' => round($recharge['paid_amount'] - $withdraw['paid_amount'], 2),
            ];
        }

        $dailyStart = strtotime($tzDay . ' 00:00:00') - 29 * 86400;
        $dailyEnd = strtotime($tzDay . ' 23:59:59');
        $daily = self::dailySeries($dailyStart, $dailyEnd);

        return [
            'generated_at' => date('Y-m-d H:i:s', $now),
            'periods'      => $periods,
            'daily'        => $daily,
        ];
    }

    /**
     * @param string $table 不含前缀
     * @param string $kind recharge|withdraw
     * @return array
     */
    public static function aggregate($table, $kind, $startTs, $endTs)
    {
        $startTs = (int)$startTs;
        $endTs = (int)$endTs;
        $paidStatus = 'paid';
        if ($kind === 'withdraw') {
            $failStatuses = ['rejected', 'cancelled'];
            $pendingStatuses = ['pending', 'processing'];
        } else {
            $failStatuses = ['failed', 'cancelled'];
            $pendingStatuses = ['pending'];
        }

        $rows = Db::name($table)
            ->where('createtime', 'between', [$startTs, $endTs])
            ->field([
                'COUNT(*) AS order_count',
                'COUNT(DISTINCT user_id) AS user_count',
                'IFNULL(SUM(amount),0) AS amount_sum',
                "SUM(CASE WHEN status='{$paidStatus}' THEN 1 ELSE 0 END) AS paid_count",
                "IFNULL(SUM(CASE WHEN status='{$paidStatus}' THEN amount ELSE 0 END),0) AS paid_amount",
                "COUNT(DISTINCT CASE WHEN status='{$paidStatus}' THEN user_id END) AS paid_user_count",
                "SUM(CASE WHEN status IN ('" . implode("','", $failStatuses) . "') THEN 1 ELSE 0 END) AS fail_count",
                "SUM(CASE WHEN status IN ('" . implode("','", $pendingStatuses) . "') THEN 1 ELSE 0 END) AS pending_count",
            ])
            ->find();

        $orderCount = (int)($rows['order_count'] ?? 0);
        $paidCount = (int)($rows['paid_count'] ?? 0);
        $failCount = (int)($rows['fail_count'] ?? 0);
        $pendingCount = (int)($rows['pending_count'] ?? 0);
        $decided = $paidCount + $failCount;

        return [
            'order_count'     => $orderCount,
            'user_count'      => (int)($rows['user_count'] ?? 0),
            'amount_sum'      => round((float)($rows['amount_sum'] ?? 0), 2),
            'paid_count'      => $paidCount,
            'paid_amount'     => round((float)($rows['paid_amount'] ?? 0), 2),
            'paid_user_count' => (int)($rows['paid_user_count'] ?? 0),
            'fail_count'      => $failCount,
            'pending_count'   => $pendingCount,
            // 成功率：成功 / 全部下单（转化）
            'success_rate'    => $orderCount > 0 ? round($paidCount * 100 / $orderCount, 2) : 0.0,
            // 完结成功率：成功 / (成功+失败)，不含进行中
            'finish_rate'     => $decided > 0 ? round($paidCount * 100 / $decided, 2) : 0.0,
        ];
    }

    /**
     * 近 N 日明细（按 createtime 日历日）
     * @return array
     */
    public static function dailySeries($startTs, $endTs)
    {
        $startTs = (int)$startTs;
        $endTs = (int)$endTs;
        $recharge = self::groupByDay('fans_recharge_order', 'recharge', $startTs, $endTs);
        $withdraw = self::groupByDay('fans_withdraw_order', 'withdraw', $startTs, $endTs);

        $days = [];
        for ($t = $startTs; $t <= $endTs; $t += 86400) {
            $day = date('Y-m-d', $t);
            $r = $recharge[$day] ?? self::emptyDayStats();
            $w = $withdraw[$day] ?? self::emptyDayStats();
            $days[] = [
                'day'      => $day,
                'recharge' => $r,
                'withdraw' => $w,
                'net_paid' => round($r['paid_amount'] - $w['paid_amount'], 2),
            ];
        }
        return array_reverse($days);
    }

    protected static function emptyDayStats()
    {
        return [
            'order_count'     => 0,
            'user_count'      => 0,
            'paid_count'      => 0,
            'paid_amount'     => 0.0,
            'paid_user_count' => 0,
            'fail_count'      => 0,
            'pending_count'   => 0,
            'success_rate'    => 0.0,
            'finish_rate'     => 0.0,
        ];
    }

    protected static function groupByDay($table, $kind, $startTs, $endTs)
    {
        if ($kind === 'withdraw') {
            $failStatuses = ['rejected', 'cancelled'];
            $pendingStatuses = ['pending', 'processing'];
        } else {
            $failStatuses = ['failed', 'cancelled'];
            $pendingStatuses = ['pending'];
        }
        $failIn = "'" . implode("','", $failStatuses) . "'";
        $pendingIn = "'" . implode("','", $pendingStatuses) . "'";

        $sql = "SELECT FROM_UNIXTIME(createtime, '%Y-%m-%d') AS day_key,
                       COUNT(*) AS order_count,
                       COUNT(DISTINCT user_id) AS user_count,
                       SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END) AS paid_count,
                       IFNULL(SUM(CASE WHEN status='paid' THEN amount ELSE 0 END),0) AS paid_amount,
                       COUNT(DISTINCT CASE WHEN status='paid' THEN user_id END) AS paid_user_count,
                       SUM(CASE WHEN status IN ({$failIn}) THEN 1 ELSE 0 END) AS fail_count,
                       SUM(CASE WHEN status IN ({$pendingIn}) THEN 1 ELSE 0 END) AS pending_count
                FROM " . Db::name($table)->getTable() . "
                WHERE createtime BETWEEN ? AND ?
                GROUP BY day_key";
        $list = Db::query($sql, [$startTs, $endTs]);
        $map = [];
        foreach ($list as $row) {
            $day = (string)$row['day_key'];
            $orderCount = (int)$row['order_count'];
            $paidCount = (int)$row['paid_count'];
            $failCount = (int)$row['fail_count'];
            $decided = $paidCount + $failCount;
            $map[$day] = [
                'order_count'     => $orderCount,
                'user_count'      => (int)$row['user_count'],
                'paid_count'      => $paidCount,
                'paid_amount'     => round((float)$row['paid_amount'], 2),
                'paid_user_count' => (int)$row['paid_user_count'],
                'fail_count'      => $failCount,
                'pending_count'   => (int)$row['pending_count'],
                'success_rate'    => $orderCount > 0 ? round($paidCount * 100 / $orderCount, 2) : 0.0,
                'finish_rate'     => $decided > 0 ? round($paidCount * 100 / $decided, 2) : 0.0,
            ];
        }
        return $map;
    }
}
