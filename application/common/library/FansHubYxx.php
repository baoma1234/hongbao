<?php

namespace app\common\library;

use think\Cache;

/**
 * 鱼虾蟹大厅：隐藏预览局（不扣款、底栏默认不展示）。
 * 结算口径按白皮书单骰；台面按效果图展示三骰。
 * 机器人在下注窗内按计划秒错峰入场，写入与真人同一份投注缓存。
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

    const BOT_NICKS = [
        '阿明', '小龙', '阿花', '老陈', '阿强', '阿珍', '阿杰', '阿辉',
        'Hùng', 'Linh', 'Nam', 'Bảo', 'Siti', 'Putri', 'Farah', 'Rina',
        'Mei', 'Ken', 'Tom', 'Anna', '用户A', '用户B', '用户C', '用户D',
    ];

    const BET_SEC = 12;
    const LOCK_SEC = 3;
    const REVEAL_SEC = 5;
    const CYCLE_SEC = 20;
    const EPOCH = 1755446400;

    public static function configMap()
    {
        $cfg = FansHubService::config();
        $min = max(1, (int)($cfg['yxx_stake_min'] ?? 50));
        $max = max($min, (int)($cfg['yxx_stake_max'] ?? 200));
        $botMin = max(0, (int)($cfg['yxx_bot_count_min'] ?? 10));
        $botMax = max($botMin, (int)($cfg['yxx_bot_count_max'] ?? 22));
        return [
            'enabled'        => !empty($cfg['yxx_enabled']),
            'tab_visible'    => !empty($cfg['yxx_tab_visible']),
            'stake_min'      => $min,
            'stake_max'      => $max,
            'cycle_max'      => max(2, (int)($cfg['yxx_cycle_max'] ?? 50)),
            'boom_from'      => max(1, (int)($cfg['yxx_boom_from'] ?? 30)),
            'bot_enabled'    => array_key_exists('yxx_bot_enabled', $cfg) ? !empty($cfg['yxx_bot_enabled']) : true,
            'bot_count_min'  => $botMin,
            'bot_count_max'  => $botMax,
        ];
    }

    public static function hallPayload($uid = 0)
    {
        self::tickBots();
        $cfg = self::configMap();
        $clock = self::clock();
        $roundIndex = (int)$clock['round_index'];
        $phase = (string)$clock['phase'];
        $dice = self::diceForRound($roundIndex);
        $revealed = ($phase === 'reveal');
        $rows = self::loadBets($roundIndex);
        $stakeSum = 0;
        $users = [];
        $botN = 0;
        foreach ($rows as $row) {
            $stakeSum += (int)($row['stake'] ?? 0);
            $users[(int)($row['uid'] ?? 0)] = 1;
            if (!empty($row['bot'])) {
                $botN++;
            }
        }
        $pool = 18000 + ($roundIndex % 8000) + $stakeSum;
        $my = null;
        if ($uid > 0) {
            foreach ($rows as $row) {
                if ((int)($row['uid'] ?? 0) === (int)$uid) {
                    $my = $row;
                    break;
                }
            }
        }

        $history = [];
        for ($i = 1; $i <= 8; $i++) {
            $idx = $roundIndex - $i;
            if ($idx < 0) {
                break;
            }
            $history[] = [
                'round_index' => $idx,
                'dice'        => self::diceForRound($idx),
            ];
        }

        $live = [];
        foreach (array_reverse($rows) as $row) {
            $face = (string)($row['face'] ?? '');
            if (!isset(self::FACE_LABEL[$face])) {
                continue;
            }
            $live[] = [
                'nick'  => (string)($row['nick'] ?? ''),
                'face'  => $face,
                'stake' => (int)($row['stake'] ?? 0),
                'bot'   => !empty($row['bot']) ? 1 : 0,
            ];
            if (count($live) >= 16) {
                break;
            }
        }

        return [
            'enabled'     => $cfg['enabled'] ? 1 : 0,
            'tab_visible' => 0,
            'preview'     => 1,
            'debit'       => 0,
            'stake_min'   => $cfg['stake_min'],
            'stake_max'   => $cfg['stake_max'],
            'cycle_max'   => $cfg['cycle_max'],
            'boom_from'   => $cfg['boom_from'],
            'bot_enabled' => $cfg['bot_enabled'] ? 1 : 0,
            'faces'       => self::FACE_IDS,
            'settle_mode' => 'single_die_preview',
            'round'       => [
                'round_index'  => $roundIndex,
                'phase'        => $phase,
                'remain_sec'   => (int)$clock['remain_sec'],
                'pool'         => $pool,
                'boom_pool'    => 0,
                'player_count' => count($users),
                'bot_count'    => $botN,
                'in_boom_zone' => 0,
                'status'       => $cfg['enabled'] ? 'preview' : 'off',
            ],
            'dice'        => $revealed ? $dice : ['', '', ''],
            'settle_face' => $revealed ? $dice[0] : '',
            'history'     => $history,
            'live_bets'   => $live,
            'my_bet'      => $my,
        ];
    }

    public static function placePreviewBet($uid, $face, $stake, $nick = '')
    {
        $uid = (int)$uid;
        if ($uid <= 0) {
            throw new \RuntimeException(FansHubService::h5CopyText('yxx_err_login') ?: '请先登录');
        }
        $cfg = self::configMap();
        if (empty($cfg['enabled'])) {
            throw new \RuntimeException(FansHubService::h5CopyText('yxx_err_closed') ?: '鱼虾蟹暂未开放');
        }
        $clock = self::clock();
        if ($clock['phase'] !== 'betting') {
            $key = $clock['phase'] === 'locking' ? 'yxx_err_sealed' : 'yxx_err_drawing';
            throw new \RuntimeException(FansHubService::h5CopyText($key) ?: '已封盘');
        }
        $face = strtolower(trim((string)$face));
        if (!isset(self::FACE_LABEL[$face])) {
            throw new \RuntimeException(FansHubService::h5CopyText('yxx_err_face') ?: '请选择一个图案');
        }
        $stake = (int)$stake;
        if ($stake < $cfg['stake_min'] || $stake > $cfg['stake_max']) {
            throw new \RuntimeException(FansHubService::h5CopyText('yxx_err_stake', [
                'min' => $cfg['stake_min'],
                'max' => $cfg['stake_max'],
            ]) ?: ('下注 ' . $cfg['stake_min'] . '-' . $cfg['stake_max'] . ' 积分'));
        }
        $nick = trim((string)$nick);
        if ($nick === '') {
            $nick = 'U' . $uid;
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
                $row['bot'] = 0;
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
                'bot'   => 0,
                'ts'    => time(),
            ];
        }
        if (count($rows) > 120) {
            $rows = array_slice($rows, -120);
        }
        Cache::set(self::betKey($roundIndex), $rows, 120);
        return self::hallPayload($uid);
    }

    /**
     * 按本局计划把到期机器人写入投注缓存（可被大厅轮询或 IM cron 反复调用）。
     */
    public static function tickBots()
    {
        $cfg = self::configMap();
        if (empty($cfg['enabled']) || empty($cfg['bot_enabled'])) {
            return 0;
        }
        $clock = self::clock();
        $roundIndex = (int)$clock['round_index'];
        $offset = (int)$clock['offset'];
        $plan = self::botPlan($roundIndex, $cfg);
        $due = [];
        foreach ($plan as $bot) {
            if ((int)$bot['at'] <= $offset) {
                $due[] = $bot;
            }
        }
        if (!$due) {
            return 0;
        }
        $lockKey = 'fh:yxx:botlock:' . $roundIndex;
        if (Cache::get($lockKey)) {
            return 0;
        }
        Cache::set($lockKey, 1, 2);
        try {
            $rows = self::loadBets($roundIndex);
            $have = [];
            foreach ($rows as $row) {
                $have[(int)($row['uid'] ?? 0)] = 1;
            }
            $added = 0;
            foreach ($due as $bot) {
                $buid = (int)$bot['uid'];
                if (isset($have[$buid])) {
                    continue;
                }
                $rows[] = [
                    'uid'   => $buid,
                    'nick'  => (string)$bot['nick'],
                    'face'  => (string)$bot['face'],
                    'stake' => (int)$bot['stake'],
                    'bot'   => 1,
                    'ts'    => time(),
                ];
                $have[$buid] = 1;
                $added++;
            }
            if ($added > 0) {
                Cache::set(self::betKey($roundIndex), $rows, 120);
            }
            return $added;
        } finally {
            Cache::rm($lockKey);
        }
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

    private static function botPlan($roundIndex, array $cfg)
    {
        $min = (int)$cfg['bot_count_min'];
        $max = (int)$cfg['bot_count_max'];
        if ($max <= 0) {
            return [];
        }
        $seed = hexdec(substr(hash('sha256', 'yxx-bots|' . (int)$roundIndex), 0, 8));
        $n = $min + ($seed % max(1, $max - $min + 1));
        $stakes = [50, 100, 150, 200];
        $nicks = self::BOT_NICKS;
        $plan = [];
        for ($i = 0; $i < $n; $i++) {
            $plan[] = [
                'uid'   => -20000 - $i,
                'nick'  => $nicks[($seed + $i * 7) % count($nicks)],
                'face'  => self::FACE_IDS[($seed + $i * 3) % 6],
                'stake' => $stakes[($seed + $i * 11) % count($stakes)],
                'at'    => 1 + (($seed + $i * 13) % 10),
            ];
        }
        return $plan;
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
}
