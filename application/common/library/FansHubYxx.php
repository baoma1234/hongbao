<?php

namespace app\common\library;

use think\Cache;

/**
 * 鱼虾蟹大厅：隐藏预览局（不扣款、底栏默认不展示）。
 * 结算口径按白皮书单骰；台面按效果图展示三骰。
 */
class FansHubYxx
{
    const FACE_IDS = ['gourd', 'crab', 'shrimp', 'fish', 'rooster', 'tiger'];

    const FACE_LABEL = [
        'gourd'   => '葫芦',
        'crab'    => '螃蟹',
        'shrimp'  => '虾',
        'fish'    => '鱼',
        'rooster' => '公鸡',
        'tiger'   => '老虎',
    ];

    const BET_SEC = 12;
    const LOCK_SEC = 3;
    const REVEAL_SEC = 5;
    const CYCLE_SEC = 20;
    const EPOCH = 1755446400; // 2025-08-17 16:00:00 UTC+8 对齐整点

    public static function configMap()
    {
        $cfg = FansHubService::config();
        $min = max(1, (int)($cfg['yxx_stake_min'] ?? 50));
        $max = max($min, (int)($cfg['yxx_stake_max'] ?? 200));
        return [
            'enabled'     => !empty($cfg['yxx_enabled']),
            'tab_visible' => !empty($cfg['yxx_tab_visible']),
            'stake_min'   => $min,
            'stake_max'   => $max,
            'cycle_max'   => max(2, (int)($cfg['yxx_cycle_max'] ?? 50)),
            'boom_from'   => max(1, (int)($cfg['yxx_boom_from'] ?? 30)),
        ];
    }

    public static function hallPayload($uid = 0)
    {
        $cfg = self::configMap();
        $clock = self::clock();
        $roundIndex = (int)$clock['round_index'];
        $phase = (string)$clock['phase'];
        $dice = self::diceForRound($roundIndex);
        $revealed = ($phase === 'reveal');
        $bets = self::mergedBets($roundIndex);
        $realSum = 0;
        $realUsers = [];
        foreach (self::loadBets($roundIndex) as $row) {
            $realSum += (int)($row['stake'] ?? 0);
            $realUsers[(int)($row['uid'] ?? 0)] = 1;
        }
        $pool = 18000 + ($roundIndex % 8000) + $realSum;
        $my = null;
        if ($uid > 0) {
            foreach (self::loadBets($roundIndex) as $row) {
                if ((int)($row['uid'] ?? 0) === (int)$uid) {
                    $my = $row;
                    break;
                }
            }
        }

        $history = [];
        $lines = [];
        for ($i = 1; $i <= 8; $i++) {
            $idx = $roundIndex - $i;
            if ($idx < 0) {
                break;
            }
            $d = self::diceForRound($idx);
            $labels = array_map(function ($id) {
                return self::FACE_LABEL[$id] ?? $id;
            }, $d);
            $history[] = [
                'round_index' => $idx,
                'dice'        => $d,
                'labels'      => $labels,
            ];
            if (count($lines) < 2) {
                $lines[] = '上局：' . implode(' ', $labels);
            }
        }

        return [
            'enabled'      => $cfg['enabled'] ? 1 : 0,
            'tab_visible'  => 0,
            'preview'      => 1,
            'debit'        => 0,
            'stake_min'    => $cfg['stake_min'],
            'stake_max'    => $cfg['stake_max'],
            'cycle_max'    => $cfg['cycle_max'],
            'boom_from'    => $cfg['boom_from'],
            'faces'        => self::FACE_IDS,
            'settle_mode'  => 'single_die_preview',
            'round'        => [
                'round_index'  => $roundIndex,
                'phase'        => $phase,
                'remain_sec'   => (int)$clock['remain_sec'],
                'pool'         => $pool,
                'boom_pool'    => 0,
                'player_count' => 18 + ($roundIndex % 17) + count($realUsers),
                'in_boom_zone' => 0,
                'status'       => $cfg['enabled'] ? 'preview' : 'off',
            ],
            'dice'         => $revealed ? $dice : ['', '', ''],
            'dice_labels'  => $revealed ? array_map(function ($id) {
                return self::FACE_LABEL[$id] ?? $id;
            }, $dice) : ['', '', ''],
            'settle_face'  => $revealed ? $dice[0] : '',
            'history_line' => implode('  |  ', $lines) ?: '等待首局开奖',
            'history'      => $history,
            'live_bets'    => $bets,
            'my_bet'       => $my,
            'marquee'      => '内测预览不扣款。大厅结算按单骰；台面三骰仅展示。底栏入口关闭，待通知后再开。',
        ];
    }

    public static function placePreviewBet($uid, $face, $stake, $nick = '')
    {
        $uid = (int)$uid;
        if ($uid <= 0) {
            throw new \RuntimeException('请先登录');
        }
        $cfg = self::configMap();
        if (empty($cfg['enabled'])) {
            throw new \RuntimeException('鱼虾蟹暂未开放');
        }
        $clock = self::clock();
        if ($clock['phase'] !== 'betting') {
            throw new \RuntimeException($clock['phase'] === 'locking' ? '已封盘' : '正在开奖，请等下一局');
        }
        $face = strtolower(trim((string)$face));
        if (!isset(self::FACE_LABEL[$face])) {
            throw new \RuntimeException('请选择一个图案');
        }
        $stake = (int)$stake;
        if ($stake < $cfg['stake_min'] || $stake > $cfg['stake_max']) {
            throw new \RuntimeException('下注 ' . $cfg['stake_min'] . '-' . $cfg['stake_max'] . ' 积分');
        }
        $nick = trim((string)$nick);
        if ($nick === '') {
            $nick = '用户' . $uid;
        }
        $roundIndex = (int)$clock['round_index'];
        $rows = self::loadBets($roundIndex);
        $found = false;
        foreach ($rows as &$row) {
            if ((int)($row['uid'] ?? 0) === $uid) {
                $row['face'] = $face;
                $row['stake'] = $stake;
                $row['nick'] = mb_substr($nick, 0, 12, 'UTF-8');
                $row['ts'] = time();
                $found = true;
                break;
            }
        }
        unset($row);
        if (!$found) {
            $rows[] = [
                'uid'   => $uid,
                'nick'  => mb_substr($nick, 0, 12, 'UTF-8'),
                'face'  => $face,
                'stake' => $stake,
                'ts'    => time(),
            ];
        }
        if (count($rows) > 80) {
            $rows = array_slice($rows, -80);
        }
        Cache::set(self::betKey($roundIndex), $rows, 120);
        return self::hallPayload($uid);
    }

    public static function clock()
    {
        $now = time();
        $elapsed = max(0, $now - self::EPOCH);
        $roundIndex = (int)floor($elapsed / self::CYCLE_SEC);
        $offset = $elapsed % self::CYCLE_SEC;
        if ($offset < self::BET_SEC) {
            return [
                'round_index' => $roundIndex,
                'phase'       => 'betting',
                'remain_sec'  => self::BET_SEC - $offset,
                'offset'      => $offset,
            ];
        }
        if ($offset < self::BET_SEC + self::LOCK_SEC) {
            return [
                'round_index' => $roundIndex,
                'phase'       => 'locking',
                'remain_sec'  => self::BET_SEC + self::LOCK_SEC - $offset,
                'offset'      => $offset,
            ];
        }
        return [
            'round_index' => $roundIndex,
            'phase'       => 'reveal',
            'remain_sec'  => self::CYCLE_SEC - $offset,
            'offset'      => $offset,
        ];
    }

    public static function diceForRound($roundIndex)
    {
        $raw = hash('sha256', 'yxx-hall-v1|' . (int)$roundIndex, true);
        return [
            self::FACE_IDS[ord($raw[0]) % 6],
            self::FACE_IDS[ord($raw[1]) % 6],
            self::FACE_IDS[ord($raw[2]) % 6],
        ];
    }

    private static function betKey($roundIndex)
    {
        return 'fh:yxx:bets:' . (int)$roundIndex;
    }

    private static function loadBets($roundIndex)
    {
        $rows = Cache::get(self::betKey($roundIndex));
        return is_array($rows) ? $rows : [];
    }

    private static function mergedBets($roundIndex)
    {
        $out = [];
        foreach (self::loadBets($roundIndex) as $row) {
            $out[] = self::formatBetLine($row);
        }
        $seed = hexdec(substr(hash('sha256', 'yxx-bots|' . (int)$roundIndex), 0, 8));
        $n = 8 + ($seed % 5);
        $nicks = ['用户A', '用户B', '阿明', '小龙', '阿花', '老陈', '阿强', 'Mei', 'Hùng', 'Siti', '用户C', '用户D'];
        $stakes = [50, 50, 100, 100, 150, 200, 80, 120];
        for ($i = 0; $i < $n; $i++) {
            $face = self::FACE_IDS[($seed + $i * 3) % 6];
            $nick = $nicks[($seed + $i) % count($nicks)];
            $stake = $stakes[($seed + $i * 2) % count($stakes)];
            $out[] = $nick . '  押' . (self::FACE_LABEL[$face] ?? $face) . '  ' . $stake . '积分';
        }
        return array_slice($out, 0, 16);
    }

    private static function formatBetLine(array $row)
    {
        $face = (string)($row['face'] ?? '');
        $label = self::FACE_LABEL[$face] ?? $face;
        $nick = (string)($row['nick'] ?? '用户');
        $stake = (int)($row['stake'] ?? 0);
        return $nick . '  押' . $label . '  ' . $stake . '积分';
    }
}
