<?php

namespace app\common\library;

use think\Db;

/**
 * 红宝统计：真人/机器人红包流水与充值加款
 */
class FansHubHongbaoStat
{
    /**
     * @param int $startTs inclusive
     * @param int $endTs   inclusive
     */
    public static function build($startTs, $endTs)
    {
        $startTs = max(0, (int)$startTs);
        $endTs = max(0, (int)$endTs);
        if ($startTs > 0 && $endTs > 0 && $startTs > $endTs) {
            $t = $startTs;
            $startTs = $endTs;
            $endTs = $t;
        }

        $types = FansHubRedPacket::typeList();
        $grab = self::grabStats($startTs, $endTs, $types);
        $send = self::sendStats($startTs, $endTs, $types);
        $daily = self::dailyStats($startTs, $endTs);

        return [
            'range' => [
                'start_ts' => $startTs,
                'end_ts'   => $endTs,
            ],
            'types' => $types,
            'grab'  => $grab,
            'send'  => $send,
            'daily' => $daily,
            'logic' => [
                'flow_include' => '真人抢机器人、机器人抢真人、真人抢真人 → 计入总流水',
                'flow_exclude' => '机器人抢机器人 → 不计入总流水',
            ],
        ];
    }

    protected static function grabStats($startTs, $endTs, array $types)
    {
        $prefix = (string)config('database.prefix');
        if ($prefix === '') {
            $prefix = 'fa_';
        }

        $sql = 'SELECT p.packet_type AS packet_type,'
            . ' IFNULL(ga.is_bot,0) AS gbot,'
            . ' IFNULL(sa.is_bot,0) AS sbot,'
            . ' COUNT(*) AS cnt,'
            . ' ROUND(IFNULL(SUM(r.amount),0),2) AS amt'
            . ' FROM ' . $prefix . 'chat_red_packet_records r'
            . ' INNER JOIN ' . $prefix . 'chat_red_packets p ON p.id = r.packet_id'
            . ' LEFT JOIN ' . $prefix . 'fans_account ga ON ga.user_id = r.user_id'
            . ' LEFT JOIN ' . $prefix . 'fans_account sa ON sa.user_id = p.from_user_id';
        $bind = [];
        if ($startTs > 0) {
            $sql .= ' WHERE r.createtime >= ?';
            $bind[] = $startTs;
            if ($endTs > 0) {
                $sql .= ' AND r.createtime <= ?';
                $bind[] = $endTs;
            }
        } elseif ($endTs > 0) {
            $sql .= ' WHERE r.createtime <= ?';
            $bind[] = $endTs;
        }
        $sql .= ' GROUP BY p.packet_type, gbot, sbot';

        $rows = Db::query($sql, $bind);

        $cats = [
            'human_grab_bot'  => ['label' => '真人抢机器人', 'in_flow' => true],
            'bot_grab_human'  => ['label' => '机器人抢真人', 'in_flow' => true],
            'human_grab_human'=> ['label' => '真人抢真人', 'in_flow' => true],
            'bot_grab_bot'    => ['label' => '机器人抢机器人(不计)', 'in_flow' => false],
        ];

        $byType = [];
        foreach ($types as $tid => $tname) {
            $byType[(int)$tid] = [
                'type'  => (int)$tid,
                'name'  => $tname,
                'cats'  => self::emptyCats($cats),
                'flow_amount' => 0.0,
                'flow_count'  => 0,
            ];
        }

        $totalCats = self::emptyCats($cats);
        $flowAmount = 0.0;
        $flowCount = 0;

        foreach ($rows as $row) {
            $tid = (int)$row['packet_type'];
            $key = self::crossKey((int)$row['gbot'], (int)$row['sbot']);
            $cnt = (int)$row['cnt'];
            $amt = round((float)$row['amt'], 2);
            if (!isset($byType[$tid])) {
                $byType[$tid] = [
                    'type' => $tid,
                    'name' => isset($types[$tid]) ? $types[$tid] : ('类型' . $tid),
                    'cats' => self::emptyCats($cats),
                    'flow_amount' => 0.0,
                    'flow_count'  => 0,
                ];
            }
            $byType[$tid]['cats'][$key]['count'] += $cnt;
            $byType[$tid]['cats'][$key]['amount'] = round($byType[$tid]['cats'][$key]['amount'] + $amt, 2);
            $totalCats[$key]['count'] += $cnt;
            $totalCats[$key]['amount'] = round($totalCats[$key]['amount'] + $amt, 2);
            if (!empty($cats[$key]['in_flow'])) {
                $byType[$tid]['flow_count'] += $cnt;
                $byType[$tid]['flow_amount'] = round($byType[$tid]['flow_amount'] + $amt, 2);
                $flowCount += $cnt;
                $flowAmount = round($flowAmount + $amt, 2);
            }
        }

        ksort($byType);

        return [
            'cats'        => $cats,
            'by_type'     => array_values($byType),
            'total_cats'  => $totalCats,
            'flow_amount' => $flowAmount,
            'flow_count'  => $flowCount,
        ];
    }

    protected static function sendStats($startTs, $endTs, array $types)
    {
        $prefix = (string)config('database.prefix');
        if ($prefix === '') {
            $prefix = 'fa_';
        }

        $sql = 'SELECT p.packet_type AS packet_type,'
            . ' IFNULL(sa.is_bot,0) AS sbot,'
            . ' COUNT(*) AS cnt,'
            . ' ROUND(IFNULL(SUM(p.total_amount),0),2) AS amt'
            . ' FROM ' . $prefix . 'chat_red_packets p'
            . ' LEFT JOIN ' . $prefix . 'fans_account sa ON sa.user_id = p.from_user_id';
        $bind = [];
        if ($startTs > 0) {
            $sql .= ' WHERE p.createtime >= ?';
            $bind[] = $startTs;
            if ($endTs > 0) {
                $sql .= ' AND p.createtime <= ?';
                $bind[] = $endTs;
            }
        } elseif ($endTs > 0) {
            $sql .= ' WHERE p.createtime <= ?';
            $bind[] = $endTs;
        }
        $sql .= ' GROUP BY p.packet_type, sbot';

        $rows = Db::query($sql, $bind);

        $byType = [];
        foreach ($types as $tid => $tname) {
            $byType[(int)$tid] = [
                'type' => (int)$tid,
                'name' => $tname,
                'bot_count'   => 0,
                'bot_amount'  => 0.0,
                'human_count' => 0,
                'human_amount'=> 0.0,
                'total_count' => 0,
                'total_amount'=> 0.0,
            ];
        }
        $botTotal = ['count' => 0, 'amount' => 0.0];
        $humanTotal = ['count' => 0, 'amount' => 0.0];

        foreach ($rows as $row) {
            $tid = (int)$row['packet_type'];
            $cnt = (int)$row['cnt'];
            $amt = round((float)$row['amt'], 2);
            if (!isset($byType[$tid])) {
                $byType[$tid] = [
                    'type' => $tid,
                    'name' => isset($types[$tid]) ? $types[$tid] : ('类型' . $tid),
                    'bot_count' => 0, 'bot_amount' => 0.0,
                    'human_count' => 0, 'human_amount' => 0.0,
                ];
            }
                if ((int)$row['sbot'] === 1) {
                $byType[$tid]['bot_count'] += $cnt;
                $byType[$tid]['bot_amount'] = round($byType[$tid]['bot_amount'] + $amt, 2);
                $botTotal['count'] += $cnt;
                $botTotal['amount'] = round($botTotal['amount'] + $amt, 2);
            } else {
                $byType[$tid]['human_count'] += $cnt;
                $byType[$tid]['human_amount'] = round($byType[$tid]['human_amount'] + $amt, 2);
                $humanTotal['count'] += $cnt;
                $humanTotal['amount'] = round($humanTotal['amount'] + $amt, 2);
            }
        }
        foreach ($byType as &$bt) {
            $bt['total_count'] = (int)$bt['bot_count'] + (int)$bt['human_count'];
            $bt['total_amount'] = round((float)$bt['bot_amount'] + (float)$bt['human_amount'], 2);
        }
        unset($bt);
        ksort($byType);

        return [
            'by_type'     => array_values($byType),
            'bot_total'   => $botTotal,
            'human_total' => $humanTotal,
            'all_count'   => $botTotal['count'] + $humanTotal['count'],
            'all_amount'  => round($botTotal['amount'] + $humanTotal['amount'], 2),
        ];
    }

    protected static function dailyStats($startTs, $endTs)
    {
        // 默认最近 14 天
        if ($startTs <= 0 && $endTs <= 0) {
            $endTs = strtotime(date('Y-m-d 23:59:59'));
            $startTs = strtotime(date('Y-m-d 00:00:00', strtotime('-13 days')));
        } elseif ($startTs <= 0) {
            $startTs = strtotime(date('Y-m-d 00:00:00', $endTs - 13 * 86400));
        } elseif ($endTs <= 0) {
            $endTs = time();
        }

        $days = [];
        $cursor = strtotime(date('Y-m-d 00:00:00', $startTs));
        $last = strtotime(date('Y-m-d 00:00:00', $endTs));
        while ($cursor <= $last) {
            $key = date('Y-m-d', $cursor);
            $days[$key] = [
                'day'            => $key,
                'recharge'       => 0.0,
                'recharge_count' => 0,
                'bot_credit'     => 0.0,
                'bot_credit_count' => 0,
                'human_credit'   => 0.0,
                'human_credit_count' => 0,
                'flow_amount'    => 0.0,
                'flow_count'     => 0,
            ];
            $cursor += 86400;
        }

        $prefix = (string)config('database.prefix');
        if ($prefix === '') {
            $prefix = 'fa_';
        }

        // 充值
        $rechargeSql = 'SELECT FROM_UNIXTIME(createtime, \'%Y-%m-%d\') AS d,'
            . ' COUNT(*) AS cnt, ROUND(IFNULL(SUM(amount),0),2) AS amt'
            . ' FROM ' . $prefix . 'fans_recharge_order'
            . ' WHERE status = \'paid\' AND createtime >= ? AND createtime <= ?'
            . ' GROUP BY d';
        foreach (Db::query($rechargeSql, [$startTs, $endTs]) as $row) {
            $d = $row['d'];
            if (!isset($days[$d])) {
                continue;
            }
            $days[$d]['recharge'] = round((float)$row['amt'], 2);
            $days[$d]['recharge_count'] = (int)$row['cnt'];
        }

        // 人工加款：admin_adjust 且 hongbao_change>0，按收款方 is_bot 拆分
        $creditSql = 'SELECT FROM_UNIXTIME(l.createtime, \'%Y-%m-%d\') AS d,'
            . ' IFNULL(a.is_bot,0) AS bot,'
            . ' COUNT(*) AS cnt,'
            . ' ROUND(IFNULL(SUM(l.hongbao_change),0),2) AS amt'
            . ' FROM ' . $prefix . 'fans_ledger l'
            . ' LEFT JOIN ' . $prefix . 'fans_account a ON a.user_id = l.user_id'
            . ' WHERE l.type = \'admin_adjust\' AND l.hongbao_change > 0'
            . ' AND l.createtime >= ? AND l.createtime <= ?'
            . ' GROUP BY d, bot';
        foreach (Db::query($creditSql, [$startTs, $endTs]) as $row) {
            $d = $row['d'];
            if (!isset($days[$d])) {
                continue;
            }
            if ((int)$row['bot'] === 1) {
                $days[$d]['bot_credit'] = round((float)$row['amt'], 2);
                $days[$d]['bot_credit_count'] = (int)$row['cnt'];
            } else {
                $days[$d]['human_credit'] = round((float)$row['amt'], 2);
                $days[$d]['human_credit_count'] = (int)$row['cnt'];
            }
        }

        // 每日计入流水（真人↔机器人交叉 + 真人抢真人）
        $flowSql = 'SELECT FROM_UNIXTIME(r.createtime, \'%Y-%m-%d\') AS d,'
            . ' COUNT(*) AS cnt,'
            . ' ROUND(IFNULL(SUM(r.amount),0),2) AS amt'
            . ' FROM ' . $prefix . 'chat_red_packet_records r'
            . ' INNER JOIN ' . $prefix . 'chat_red_packets p ON p.id = r.packet_id'
            . ' LEFT JOIN ' . $prefix . 'fans_account ga ON ga.user_id = r.user_id'
            . ' LEFT JOIN ' . $prefix . 'fans_account sa ON sa.user_id = p.from_user_id'
            . ' WHERE r.createtime >= ? AND r.createtime <= ?'
            . ' AND NOT (IFNULL(ga.is_bot,0)=1 AND IFNULL(sa.is_bot,0)=1)'
            . ' GROUP BY d';
        foreach (Db::query($flowSql, [$startTs, $endTs]) as $row) {
            $d = $row['d'];
            if (!isset($days[$d])) {
                continue;
            }
            $days[$d]['flow_amount'] = round((float)$row['amt'], 2);
            $days[$d]['flow_count'] = (int)$row['cnt'];
        }

        krsort($days);

        $sum = [
            'recharge' => 0.0,
            'bot_credit' => 0.0,
            'human_credit' => 0.0,
            'flow_amount' => 0.0,
        ];
        foreach ($days as $row) {
            $sum['recharge'] = round($sum['recharge'] + $row['recharge'], 2);
            $sum['bot_credit'] = round($sum['bot_credit'] + $row['bot_credit'], 2);
            $sum['human_credit'] = round($sum['human_credit'] + $row['human_credit'], 2);
            $sum['flow_amount'] = round($sum['flow_amount'] + $row['flow_amount'], 2);
        }

        return [
            'rows' => array_values($days),
            'sum'  => $sum,
        ];
    }

    protected static function crossKey($gbot, $sbot)
    {
        if ($gbot === 0 && $sbot === 1) {
            return 'human_grab_bot';
        }
        if ($gbot === 1 && $sbot === 0) {
            return 'bot_grab_human';
        }
        if ($gbot === 0 && $sbot === 0) {
            return 'human_grab_human';
        }
        return 'bot_grab_bot';
    }

    protected static function emptyCats(array $cats)
    {
        $out = [];
        foreach ($cats as $k => $meta) {
            $out[$k] = [
                'label'    => $meta['label'],
                'in_flow'  => !empty($meta['in_flow']),
                'count'    => 0,
                'amount'   => 0.0,
            ];
        }
        return $out;
    }
}
