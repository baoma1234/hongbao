<?php

namespace app\common\library;

use think\Db;

/**
 * 红宝统计：真人/机器人红包流水、充值加款、尾数牛牛玩法
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
        $niuniu = self::niuniuStats($startTs, $endTs);

        return [
            'range' => [
                'start_ts' => $startTs,
                'end_ts'   => $endTs,
            ],
            'types' => $types,
            'grab'  => $grab,
            'send'  => $send,
            'daily' => $daily,
            'niuniu'=> $niuniu,
            'logic' => [
                'flow_include' => '真人抢机器人、机器人抢真人、真人抢真人 → 计入总流水',
                'flow_exclude' => '机器人抢机器人 → 不计入总流水',
                'niuniu'       => '尾数牛牛按对局 createtime 统计；购入/奖金按份数关联账号 is_bot 拆分',
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

    /**
     * 尾数牛牛玩法统计
     */
    protected static function niuniuStats($startTs, $endTs)
    {
        $prefix = (string)config('database.prefix');
        if ($prefix === '') {
            $prefix = 'fa_';
        }
        $roundsTable = $prefix . 'chat_niuniu_rounds';
        $sharesTable = $prefix . 'chat_niuniu_shares';
        $accountTable = $prefix . 'fans_account';
        $ledgerTable = $prefix . 'fans_ledger';

        $statusLabels = [
            1 => '购入中',
            2 => '领取中',
            3 => '已开奖',
            4 => '已作废',
            5 => '流局退回',
        ];
        $modeLabels = [
            1 => '尾数牛牛(多包)',
            2 => '尾数牛牛(单结果)',
        ];

        $empty = [
            'available'   => false,
            'summary'     => [
                'rounds'       => 0,
                'settled'      => 0,
                'refund'       => 0,
                'void'         => 0,
                'pool_amount'  => 0.0,
                'fee_amount'   => 0.0,
                'distributable'=> 0.0,
                'share_count'  => 0,
                'win_amount'   => 0.0,
            ],
            'by_status'   => [],
            'by_mode'     => [],
            'buy'         => [
                'human' => ['count' => 0, 'amount' => 0.0, 'users' => 0],
                'bot'   => ['count' => 0, 'amount' => 0.0, 'users' => 0],
                'all'   => ['count' => 0, 'amount' => 0.0, 'users' => 0],
            ],
            'win'         => [
                'human' => ['count' => 0, 'amount' => 0.0],
                'bot'   => ['count' => 0, 'amount' => 0.0],
                'all'   => ['count' => 0, 'amount' => 0.0],
            ],
            'ledger'      => [],
            'daily'       => ['rows' => [], 'sum' => []],
        ];

        try {
            Db::name('chat_niuniu_rounds')->limit(1)->count();
        } catch (\Throwable $e) {
            return $empty;
        }

        $timeWhere = '';
        $bind = [];
        if ($startTs > 0) {
            $timeWhere .= ' AND r.createtime >= ?';
            $bind[] = $startTs;
            if ($endTs > 0) {
                $timeWhere .= ' AND r.createtime <= ?';
                $bind[] = $endTs;
            }
        } elseif ($endTs > 0) {
            $timeWhere .= ' AND r.createtime <= ?';
            $bind[] = $endTs;
        }

        // 按状态
        $byStatus = [];
        foreach ($statusLabels as $sid => $lab) {
            $byStatus[$sid] = [
                'status' => $sid,
                'label'  => $lab,
                'count'  => 0,
                'pool'   => 0.0,
                'fee'    => 0.0,
                'shares' => 0,
            ];
        }
        $statusSql = 'SELECT r.status AS st, COUNT(*) AS cnt,'
            . ' ROUND(IFNULL(SUM(r.pool_amount),0),2) AS pool,'
            . ' ROUND(IFNULL(SUM(r.fee_amount),0),2) AS fee,'
            . ' ROUND(IFNULL(SUM(r.distributable),0),2) AS dist,'
            . ' IFNULL(SUM(r.share_count),0) AS shares'
            . ' FROM ' . $roundsTable . ' r'
            . ' WHERE 1=1' . $timeWhere
            . ' GROUP BY r.status';
        $summary = $empty['summary'];
        foreach (Db::query($statusSql, $bind) as $row) {
            $st = (int)$row['st'];
            $cnt = (int)$row['cnt'];
            $pool = round((float)$row['pool'], 2);
            $fee = round((float)$row['fee'], 2);
            $shares = (int)$row['shares'];
            if (!isset($byStatus[$st])) {
                $byStatus[$st] = [
                    'status' => $st,
                    'label'  => isset($statusLabels[$st]) ? $statusLabels[$st] : ('状态' . $st),
                    'count'  => 0,
                    'pool'   => 0.0,
                    'fee'    => 0.0,
                    'shares' => 0,
                ];
            }
            $byStatus[$st]['count'] = $cnt;
            $byStatus[$st]['pool'] = $pool;
            $byStatus[$st]['fee'] = $fee;
            $byStatus[$st]['shares'] = $shares;
            $summary['rounds'] += $cnt;
            $summary['pool_amount'] = round($summary['pool_amount'] + $pool, 2);
            $summary['fee_amount'] = round($summary['fee_amount'] + $fee, 2);
            $summary['distributable'] = round($summary['distributable'] + (float)$row['dist'], 2);
            $summary['share_count'] += $shares;
            if ($st === 3) {
                $summary['settled'] = $cnt;
            } elseif ($st === 5) {
                $summary['refund'] = $cnt;
            } elseif ($st === 4) {
                $summary['void'] = $cnt;
            }
        }

        // 按玩法
        $byMode = [];
        foreach ($modeLabels as $mid => $lab) {
            $byMode[$mid] = [
                'mode'  => $mid,
                'label' => $lab,
                'count' => 0,
                'pool'  => 0.0,
                'fee'   => 0.0,
                'shares'=> 0,
            ];
        }
        $modeSql = 'SELECT IFNULL(r.game_mode,1) AS gm, COUNT(*) AS cnt,'
            . ' ROUND(IFNULL(SUM(r.pool_amount),0),2) AS pool,'
            . ' ROUND(IFNULL(SUM(r.fee_amount),0),2) AS fee,'
            . ' IFNULL(SUM(r.share_count),0) AS shares'
            . ' FROM ' . $roundsTable . ' r'
            . ' WHERE 1=1' . $timeWhere
            . ' GROUP BY gm';
        try {
            foreach (Db::query($modeSql, $bind) as $row) {
                $gm = (int)$row['gm'] === 2 ? 2 : 1;
                $byMode[$gm]['count'] += (int)$row['cnt'];
                $byMode[$gm]['pool'] = round($byMode[$gm]['pool'] + (float)$row['pool'], 2);
                $byMode[$gm]['fee'] = round($byMode[$gm]['fee'] + (float)$row['fee'], 2);
                $byMode[$gm]['shares'] += (int)$row['shares'];
            }
        } catch (\Throwable $e) {
            // game_mode 列不存在时忽略
        }

        // 购入 / 奖金（真人 vs 机器人）
        $buy = $empty['buy'];
        $win = $empty['win'];
        $shareSql = 'SELECT IFNULL(a.is_bot,0) AS bot,'
            . ' COUNT(*) AS cnt,'
            . ' COUNT(DISTINCT s.user_id) AS users,'
            . ' ROUND(IFNULL(SUM(s.amount),0),2) AS buy_amt,'
            . ' ROUND(IFNULL(SUM(CASE WHEN s.win_amount>0 THEN s.win_amount ELSE 0 END),0),2) AS win_amt,'
            . ' SUM(CASE WHEN s.win_amount>0 THEN 1 ELSE 0 END) AS win_cnt'
            . ' FROM ' . $sharesTable . ' s'
            . ' INNER JOIN ' . $roundsTable . ' r ON r.id = s.round_id'
            . ' LEFT JOIN ' . $accountTable . ' a ON a.user_id = s.user_id'
            . ' WHERE 1=1' . $timeWhere
            . ' GROUP BY bot';
        try {
            foreach (Db::query($shareSql, $bind) as $row) {
                $key = ((int)$row['bot'] === 1) ? 'bot' : 'human';
                $buy[$key]['count'] = (int)$row['cnt'];
                $buy[$key]['amount'] = round((float)$row['buy_amt'], 2);
                $buy[$key]['users'] = (int)$row['users'];
                $win[$key]['count'] = (int)$row['win_cnt'];
                $win[$key]['amount'] = round((float)$row['win_amt'], 2);
            }
        } catch (\Throwable $e) {
            // shares 表异常时跳过
        }
        $buy['all'] = [
            'count'  => $buy['human']['count'] + $buy['bot']['count'],
            'amount' => round($buy['human']['amount'] + $buy['bot']['amount'], 2),
            'users'  => $buy['human']['users'] + $buy['bot']['users'],
        ];
        $win['all'] = [
            'count'  => $win['human']['count'] + $win['bot']['count'],
            'amount' => round($win['human']['amount'] + $win['bot']['amount'], 2),
        ];
        $summary['win_amount'] = $win['all']['amount'];

        // 账本类型汇总
        $ledger = [];
        $ledgerLabels = [
            'niuniu_buy'     => '购入扣款',
            'niuniu_win'     => '奖金入账',
            'niuniu_fee_in'  => '平台手续费',
            'niuniu_refund'  => '流局退回',
            'niuniu_packet'  => '比对红包(展示)',
        ];
        $ledgerTime = '';
        $ledgerBind = [];
        if ($startTs > 0) {
            $ledgerTime .= ' AND createtime >= ?';
            $ledgerBind[] = $startTs;
            if ($endTs > 0) {
                $ledgerTime .= ' AND createtime <= ?';
                $ledgerBind[] = $endTs;
            }
        } elseif ($endTs > 0) {
            $ledgerTime .= ' AND createtime <= ?';
            $ledgerBind[] = $endTs;
        }
        $ledgerSql = 'SELECT type, COUNT(*) AS cnt,'
            . ' ROUND(IFNULL(SUM(hongbao_change),0),2) AS amt'
            . ' FROM ' . $ledgerTable
            . ' WHERE type LIKE \'niuniu%\'' . $ledgerTime
            . ' GROUP BY type';
        try {
            foreach (Db::query($ledgerSql, $ledgerBind) as $row) {
                $t = (string)$row['type'];
                $ledger[] = [
                    'type'  => $t,
                    'label' => isset($ledgerLabels[$t]) ? $ledgerLabels[$t] : $t,
                    'count' => (int)$row['cnt'],
                    'amount'=> round((float)$row['amt'], 2),
                ];
            }
        } catch (\Throwable $e) {
        }

        // 每日
        $dayStart = $startTs;
        $dayEnd = $endTs;
        if ($dayStart <= 0 && $dayEnd <= 0) {
            $dayEnd = strtotime(date('Y-m-d 23:59:59'));
            $dayStart = strtotime(date('Y-m-d 00:00:00', strtotime('-13 days')));
        } elseif ($dayStart <= 0) {
            $dayStart = strtotime(date('Y-m-d 00:00:00', $dayEnd - 13 * 86400));
        } elseif ($dayEnd <= 0) {
            $dayEnd = time();
        }
        $days = [];
        $cursor = strtotime(date('Y-m-d 00:00:00', $dayStart));
        $last = strtotime(date('Y-m-d 00:00:00', $dayEnd));
        while ($cursor <= $last) {
            $key = date('Y-m-d', $cursor);
            $days[$key] = [
                'day'          => $key,
                'rounds'       => 0,
                'settled'      => 0,
                'pool'         => 0.0,
                'fee'          => 0.0,
                'human_buy'    => 0.0,
                'bot_buy'      => 0.0,
                'human_win'    => 0.0,
                'bot_win'      => 0.0,
            ];
            $cursor += 86400;
        }

        $dailyRoundSql = 'SELECT FROM_UNIXTIME(r.createtime, \'%Y-%m-%d\') AS d,'
            . ' COUNT(*) AS cnt,'
            . ' SUM(CASE WHEN r.status=3 THEN 1 ELSE 0 END) AS settled,'
            . ' ROUND(IFNULL(SUM(r.pool_amount),0),2) AS pool,'
            . ' ROUND(IFNULL(SUM(r.fee_amount),0),2) AS fee'
            . ' FROM ' . $roundsTable . ' r'
            . ' WHERE r.createtime >= ? AND r.createtime <= ?'
            . ' GROUP BY d';
        foreach (Db::query($dailyRoundSql, [$dayStart, $dayEnd]) as $row) {
            $d = $row['d'];
            if (!isset($days[$d])) {
                continue;
            }
            $days[$d]['rounds'] = (int)$row['cnt'];
            $days[$d]['settled'] = (int)$row['settled'];
            $days[$d]['pool'] = round((float)$row['pool'], 2);
            $days[$d]['fee'] = round((float)$row['fee'], 2);
        }

        $dailyShareSql = 'SELECT FROM_UNIXTIME(r.createtime, \'%Y-%m-%d\') AS d,'
            . ' IFNULL(a.is_bot,0) AS bot,'
            . ' ROUND(IFNULL(SUM(s.amount),0),2) AS buy_amt,'
            . ' ROUND(IFNULL(SUM(CASE WHEN s.win_amount>0 THEN s.win_amount ELSE 0 END),0),2) AS win_amt'
            . ' FROM ' . $sharesTable . ' s'
            . ' INNER JOIN ' . $roundsTable . ' r ON r.id = s.round_id'
            . ' LEFT JOIN ' . $accountTable . ' a ON a.user_id = s.user_id'
            . ' WHERE r.createtime >= ? AND r.createtime <= ?'
            . ' GROUP BY d, bot';
        try {
            foreach (Db::query($dailyShareSql, [$dayStart, $dayEnd]) as $row) {
                $d = $row['d'];
                if (!isset($days[$d])) {
                    continue;
                }
                if ((int)$row['bot'] === 1) {
                    $days[$d]['bot_buy'] = round((float)$row['buy_amt'], 2);
                    $days[$d]['bot_win'] = round((float)$row['win_amt'], 2);
                } else {
                    $days[$d]['human_buy'] = round((float)$row['buy_amt'], 2);
                    $days[$d]['human_win'] = round((float)$row['win_amt'], 2);
                }
            }
        } catch (\Throwable $e) {
        }

        krsort($days);
        $daySum = [
            'rounds' => 0, 'settled' => 0, 'pool' => 0.0, 'fee' => 0.0,
            'human_buy' => 0.0, 'bot_buy' => 0.0, 'human_win' => 0.0, 'bot_win' => 0.0,
        ];
        foreach ($days as $row) {
            $daySum['rounds'] += $row['rounds'];
            $daySum['settled'] += $row['settled'];
            $daySum['pool'] = round($daySum['pool'] + $row['pool'], 2);
            $daySum['fee'] = round($daySum['fee'] + $row['fee'], 2);
            $daySum['human_buy'] = round($daySum['human_buy'] + $row['human_buy'], 2);
            $daySum['bot_buy'] = round($daySum['bot_buy'] + $row['bot_buy'], 2);
            $daySum['human_win'] = round($daySum['human_win'] + $row['human_win'], 2);
            $daySum['bot_win'] = round($daySum['bot_win'] + $row['bot_win'], 2);
        }

        return [
            'available' => true,
            'summary'   => $summary,
            'by_status' => array_values($byStatus),
            'by_mode'   => array_values($byMode),
            'buy'       => $buy,
            'win'       => $win,
            'ledger'    => $ledger,
            'daily'     => [
                'rows' => array_values($days),
                'sum'  => $daySum,
            ],
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
