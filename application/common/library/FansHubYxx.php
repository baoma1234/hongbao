<?php

namespace app\common\library;

use think\Cache;
use think\Db;

/**
 * 鱼虾蟹大厅：全局 20s 局钟；白皮书单骰结算（第一颗骰）；台面展示三骰。
 * yxx_real_money=true 时扣红宝并按 6×92%=5.52 倍派彩；false 为预览局。
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

    /** 单门中奖倍数：6 × 92% */
    const PAYOUT_MULT = 5.52;
    const BOOM_RATE = 0.05;

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
            'real_money'     => array_key_exists('yxx_real_money', $cfg) ? !empty($cfg['yxx_real_money']) : false,
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
        $uid = (int)$uid;
        // 登录用户热路径只读快照；机器人/结算交给 yxxtick（匿名/cron）
        $clock = self::clock();
        self::tickBotsThrottled($clock);
        if ((string)$clock['phase'] !== 'betting') {
            self::maybeSettleRounds();
        }
        $clock = self::clock();
        $snap = self::publicHallSnap($clock);
        $my = null;
        if ($uid > 0) {
            self::lazyCreditWin($uid);
            $row = FansHubYxxStore::getBet((int)$clock['round_index'], $uid);
            if (is_array($row)) {
                $my = self::formatMyBet($row);
            }
        }
        $rainPopup = ($uid > 0) ? FansHubYxxPool::pendingPopup($uid) : null;
        $snap['rain_popup'] = $rainPopup;
        $snap['my_bet'] = $my;
        $snap['round']['remain_sec'] = (int)$clock['remain_sec'];
        $snap['round']['phase'] = (string)$clock['phase'];
        $snap['round']['round_index'] = (int)$clock['round_index'];
        $snap['server_ts'] = time();
        $snap['poll_ms'] = ($clock['phase'] === 'betting') ? 2500 : 1000;
        return $snap;
    }

    /**
     * cron / 匿名踢一脚：机器人入注 + 结算。大厅万人轮询不再走这里。
     */
    public static function tickEngine()
    {
        $clock = self::clock();
        $bots = self::tickBotsThrottled($clock);
        self::maybeSettleRounds();
        $clock = self::clock();
        return [
            'ok'          => 1,
            'round_index' => (int)$clock['round_index'],
            'phase'       => (string)$clock['phase'],
            'remain_sec'  => (int)$clock['remain_sec'],
            'bots'        => (int)$bots,
        ];
    }

    /**
     * 开奖派彩惰性入账：结算进程只写 Redis，用户进大厅再打钱包。
     */
    protected static function lazyCreditWin($uid)
    {
        $uid = (int)$uid;
        if ($uid <= 0) {
            return;
        }
        $pay = FansHubYxxStore::getJson('fh:yxx:winpay:' . $uid);
        if (!is_array($pay)) {
            return;
        }
        $roundIndex = (int)($pay['round_index'] ?? 0);
        $amount = (int)($pay['amount'] ?? 0);
        $face = (string)($pay['face'] ?? '');
        if ($roundIndex < 0 || $amount <= 0) {
            FansHubYxxStore::delName('fh:yxx:winpay:' . $uid);
            return;
        }
        $lockName = 'fh:yxx:wpaid:' . $roundIndex . ':' . $uid;
        if (!FansHubYxxStore::acquireLock($lockName, 86400 * 7)) {
            FansHubYxxStore::delName('fh:yxx:winpay:' . $uid);
            return;
        }
        try {
            FansHubHongbaoLedger::credit($uid, $amount, 'yxx_win', '鱼虾蟹中奖 R' . $roundIndex, [
                'biz_no'   => 'YXXW' . $roundIndex . '-' . $uid,
                'ref_type' => 'yxx_round',
                'ref_id'   => $roundIndex,
                'channel'  => $face,
            ]);
            FansHubYxxStore::delName('fh:yxx:winpay:' . $uid);
        } catch (\Throwable $e) {
            FansHubYxxStore::releaseLock($lockName);
        }
    }

    /**
     * 大厅公共快照 TTL 1s。remain_sec / my_bet / rain_popup 在外层覆盖。
     */
    protected static function publicHallSnap(array $clock)
    {
        $roundIndex = (int)$clock['round_index'];
        $phase = (string)$clock['phase'];
        $cached = FansHubYxxStore::getSnap();
        if (is_array($cached)
            && (int)($cached['round']['round_index'] ?? -1) === $roundIndex
            && (string)($cached['round']['phase'] ?? '') === $phase
        ) {
            return $cached;
        }

        $cfg = self::configMap();
        $dice = self::diceForRound($roundIndex);
        $revealed = ($phase === 'reveal');
        $stats = FansHubYxxStore::stats($roundIndex);
        $stakeSum = (int)$stats['stake'];
        $playerN = (int)$stats['players'];
        $botN = (int)($stats['bots'] ?? 0);
        $boomPool = self::boomPool();
        $cycleCount = self::cycleCount();
        $pool = (int)floor($stakeSum * 0.92) + $boomPool;
        $realMoney = !empty($cfg['real_money']);

        $history = [];
        for ($i = 1; $i <= 8; $i++) {
            $idx = $roundIndex - $i;
            if ($idx < 0) {
                break;
            }
            $d = self::diceForRound($idx);
            $history[] = [
                'round_index' => $idx,
                'dice'        => $d,
                'settle_face' => $d[0],
                'hash_seed'   => self::roundHashSeed($idx),
            ];
        }

        $live = [];
        foreach (FansHubYxxStore::liveFeed($roundIndex, 16) as $row) {
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
        }

        $poolInfo = FansHubYxxPool::hallPoolPayload();
        $payload = [
            'enabled'     => $cfg['enabled'] ? 1 : 0,
            'tab_visible' => $cfg['tab_visible'] ? 1 : 0,
            'preview'     => $realMoney ? 0 : 1,
            'real_money'  => $realMoney ? 1 : 0,
            'debit'       => $realMoney ? 1 : 0,
            'stake_min'   => $cfg['stake_min'],
            'stake_max'   => $cfg['stake_max'],
            'cycle_max'   => $cfg['cycle_max'],
            'boom_from'   => $cfg['boom_from'],
            'bot_enabled' => $cfg['bot_enabled'] ? 1 : 0,
            'payout_mult' => self::PAYOUT_MULT,
            'faces'       => self::FACE_IDS,
            'settle_mode' => 'single_die',
            'pool'        => $poolInfo,
            'rain_popup'  => null,
            'pool_status' => (string)($poolInfo['pool_status'] ?? 'normal'),
            'round'       => [
                'round_index'  => $roundIndex,
                'phase'        => $phase,
                'remain_sec'   => (int)$clock['remain_sec'],
                'pool'         => $pool,
                'boom_pool'    => $boomPool,
                'cycle_count'  => $cycleCount,
                'player_count' => $playerN,
                'bot_count'    => $botN,
                'in_boom_zone' => ($cycleCount >= $cfg['boom_from'] && $cycleCount <= $cfg['cycle_max']) ? 1 : 0,
                'status'       => $cfg['enabled'] ? ($realMoney ? 'live' : 'preview') : 'off',
            ],
            'dice'        => $revealed ? $dice : ['', '', ''],
            'settle_face' => $revealed ? $dice[0] : '',
            'history'     => $history,
            'live_bets'   => $live,
            'my_bet'      => null,
        ];
        FansHubYxxStore::setSnap($payload, 1);
        return $payload;
    }

    public static function placeBet($uid, $face, $stake, $nick = '')
    {
        $cfg = self::configMap();
        if (!empty($cfg['real_money'])) {
            return self::placeRealBet($uid, $face, $stake, $nick);
        }
        return self::placePreviewBet($uid, $face, $stake, $nick);
    }

    public static function placePreviewBet($uid, $face, $stake, $nick = '')
    {
        $uid = (int)$uid;
        self::assertBetInput($uid, $face, $stake);
        $nick = self::normalizeNick($uid, $nick);
        $roundIndex = (int)self::clock()['round_index'];
        $lockName = 'fh:yxx:ubet:' . $roundIndex . ':' . $uid;
        if (!FansHubYxxStore::acquireLock($lockName, 8)) {
            throw new \RuntimeException(FansHubService::h5CopyText('yxx_err_fast') ?: '操作太快，请稍后再试');
        }
        try {
            $face = strtolower(trim((string)$face));
            FansHubYxxStore::putBet($roundIndex, [
                'uid'     => $uid,
                'nick'    => $nick,
                'face'    => $face,
                'stake'   => (int)$stake,
                'bot'     => 0,
                'debited' => 0,
                'settled' => 0,
                'won'     => 0,
                'payout'  => 0,
                'ts'      => time(),
            ]);
        } finally {
            FansHubYxxStore::releaseLock($lockName);
        }
        return self::hallPayload($uid);
    }

    public static function placeRealBet($uid, $face, $stake, $nick = '')
    {
        $uid = (int)$uid;
        self::assertBetInput($uid, $face, $stake);
        $nick = self::normalizeNick($uid, $nick);
        $roundIndex = (int)self::clock()['round_index'];
        $lockName = 'fh:yxx:ubet:' . $roundIndex . ':' . $uid;
        if (!FansHubYxxStore::acquireLock($lockName, 8)) {
            throw new \RuntimeException(FansHubService::h5CopyText('yxx_err_fast') ?: '操作太快，请稍后再试');
        }
        try {
            $prev = FansHubYxxStore::getBet($roundIndex, $uid);
            $prevStake = 0;
            if (is_array($prev) && empty($prev['bot'])) {
                $prevStake = (int)($prev['stake'] ?? 0);
            }
            $stake = (int)$stake;
            if ($prevStake !== $stake) {
                if ($prevStake > 0) {
                    FansHubHongbaoLedger::credit($uid, $prevStake, 'yxx_bet_refund', '鱼虾蟹改注退回 R' . $roundIndex, [
                        'biz_no'   => 'YXXR' . $roundIndex . '-' . $uid,
                        'ref_type' => 'yxx_round',
                        'ref_id'   => $roundIndex,
                    ]);
                    FansHubYxxPool::adjustDailyBet($uid, -$prevStake);
                }
                FansHubHongbaoLedger::debit($uid, $stake, 'yxx_bet', '鱼虾蟹下注 R' . $roundIndex, [
                    'biz_no'   => 'YXX' . $roundIndex . '-' . $uid,
                    'ref_type' => 'yxx_round',
                    'ref_id'   => $roundIndex,
                ], true);
                FansHubYxxPool::touchDailyBet($uid, $stake);
            }
            $face = strtolower(trim((string)$face));
            FansHubYxxStore::putBet($roundIndex, [
                'uid'     => $uid,
                'nick'    => $nick,
                'face'    => $face,
                'stake'   => $stake,
                'bot'     => 0,
                'debited' => 1,
                'settled' => 0,
                'won'     => 0,
                'payout'  => 0,
                'ts'      => time(),
            ]);
        } finally {
            FansHubYxxStore::releaseLock($lockName);
        }
        return self::hallPayload($uid);
    }

    protected static function tickBotsThrottled(array $clock)
    {
        if ((string)($clock['phase'] ?? '') !== 'betting') {
            return 0;
        }
        if (!FansHubYxxStore::acquireLock('fh:yxx:bottick', 1)) {
            return 0;
        }
        return self::tickBots();
    }

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
        $added = 0;
        foreach ($plan as $bot) {
            if ((int)$bot['at'] > $offset) {
                continue;
            }
            $buid = (int)$bot['uid'];
            if (FansHubYxxStore::hasBet($roundIndex, $buid)) {
                continue;
            }
            FansHubYxxStore::putBet($roundIndex, [
                'uid'   => $buid,
                'nick'  => (string)$bot['nick'],
                'face'  => (string)$bot['face'],
                'stake' => (int)$bot['stake'],
                'bot'   => 1,
                'ts'    => time(),
            ]);
            $added++;
        }
        return $added;
    }

    protected static function maybeSettleRounds()
    {
        $clock = self::clock();
        $roundIndex = (int)$clock['round_index'];
        if ($clock['phase'] !== 'betting') {
            self::settleRoundIfNeeded($roundIndex);
        }
        self::settleRoundIfNeeded($roundIndex - 1);
    }

    protected static function settleRoundIfNeeded($roundIndex)
    {
        $roundIndex = (int)$roundIndex;
        if ($roundIndex < 0) {
            return;
        }
        $doneKey = 'fh:yxx:settled:' . $roundIndex;
        if (Cache::get($doneKey)) {
            return;
        }
        $lockName = 'fh:yxx:settlelock:' . $roundIndex;
        if (!FansHubYxxStore::acquireLock($lockName, 15)) {
            return;
        }
        try {
            if (Cache::get($doneKey)) {
                return;
            }
            self::doSettleRound($roundIndex);
            Cache::set($doneKey, 1, 86400 * 7);
            FansHubYxxStore::clearSnap();
        } finally {
            FansHubYxxStore::releaseLock($lockName);
        }
    }

    protected static function doSettleRound($roundIndex)
    {
        $cfg = self::configMap();
        $realMoney = !empty($cfg['real_money']);

        $dice = self::diceForRound($roundIndex);
        $settleFace = $dice[0];
        $rows = FansHubYxxStore::loadBets($roundIndex);

        // 先计算人类下注量 + 中奖门口总下注量（用于爆点分摊）
        $humanStake = 0;
        $totalStakeWon = 0;
        $totalStakeAll = 0;
        for ($i = 0; $i < count($rows); $i++) {
            $row = $rows[$i];
            $stake = (int)($row['stake'] ?? 0);
            $uid = (int)($row['uid'] ?? 0);
            $face = (string)($row['face'] ?? '');
            $isBot = !empty($row['bot']) || $uid <= 0;

            $totalStakeAll += $stake;
            if (!$isBot) {
                $humanStake += $stake;
            }
            if ($face === $settleFace) {
                $totalStakeWon += $stake;
            }
        }

        // 爆点池累积（按人类有效投注 5% 进入）
        $boomPoolBefore = self::boomPool();
        $boomAdd = $humanStake > 0 ? (int)floor($humanStake * self::BOOM_RATE) : 0;
        $boomPoolAfterAdd = max(0, $boomPoolBefore + $boomAdd);

        // 循环计数：仅在人类有投注时推进一次（保持“有效局”概念）
        $cycleBefore = self::cycleCount();
        $cycleAdvance = $humanStake > 0 ? 1 : 0;
        $cycleAfter = $cycleBefore + $cycleAdvance;

        // 爆点释放：在 [boom_from, cycle_max] 区间内触发一次（熔断 paused/locked 时只蓄水不释放）
        $releasedBoom = 0;
        $nextHalfBoomCount = 0;
        $halfBoomCount = (int)Cache::get('fh:yxx:boom_half_count');
        if (FansHubYxxPool::canBoomRelease()
            && $cycleAfter >= (int)$cfg['boom_from']
            && $cycleAfter <= (int)$cfg['cycle_max']
        ) {
            $releasedKey = 'fh:yxx:boom_release:' . (int)$cycleAfter;
            if (!Cache::get($releasedKey)) {
                $forceFull = $halfBoomCount >= 2;
                if ($forceFull) {
                    $releasePercent = 1.0;
                    $nextHalfBoomCount = 0;
                } else {
                    $seed = hexdec(substr(hash('sha256', 'yxx-boom|' . (int)$cycleAfter . '|' . (int)$roundIndex), 0, 8));
                    $isHalf = ($seed % 2) === 0;
                    if ($isHalf) {
                        $releasePercent = 0.5;
                        $nextHalfBoomCount = $halfBoomCount + 1;
                    } else {
                        $releasePercent = 1.0;
                        $nextHalfBoomCount = 0;
                    }
                }

                $releasedBoom = (int)floor($boomPoolAfterAdd * $releasePercent);
                $releasedBoom = max(0, min($releasedBoom, $boomPoolAfterAdd));

                Cache::set($releasedKey, 1, 86400 * 30);
                Cache::set('fh:yxx:boom_half_count', $nextHalfBoomCount, 86400 * 30);
            } else {
                $nextHalfBoomCount = $halfBoomCount;
            }
        } else {
            $nextHalfBoomCount = $halfBoomCount;
        }

        // 更新爆点/循环缓存（无论是否释放都先写入最新累积值）
        $boomPoolAfterRelease = max(0, $boomPoolAfterAdd - $releasedBoom);
        $hashSeed = self::roundHashSeed($roundIndex);
        Cache::set('fh:yxx:cycle_count', max(0, $cycleAfter), 86400 * 30);
        Cache::set('fh:yxx:boom_half_count', max(0, $nextHalfBoomCount), 86400 * 30);

        // 结算行：单骰固定倍率 + 爆点释放奖金分摊（双重上限）
        $winWeights = [];
        for ($i = 0; $i < count($rows); $i++) {
            if (!empty($rows[$i]['settled'])) {
                continue;
            }

            $stake = (int)($rows[$i]['stake'] ?? 0);
            $uid = (int)($rows[$i]['uid'] ?? 0);
            $isBot = !empty($rows[$i]['bot']) || $uid <= 0;
            $won = ((string)($rows[$i]['face'] ?? '') === $settleFace);

            $rows[$i]['settled'] = 1;
            $rows[$i]['won'] = $won ? 1 : 0;

            $basePayout = $won ? (int)round($stake * self::PAYOUT_MULT) : 0;
            $rows[$i]['payout'] = $basePayout;

            if ($won && !$isBot && $releasedBoom > 0 && $totalStakeWon > 0) {
                $winWeights[$i] = $stake;
            }
        }

        $bonusFloor = ($releasedBoom > 0 && $winWeights)
            ? FansHubYxxPool::capProportionalShares($releasedBoom, $winWeights)
            : [];

        $winPays = [];
        for ($i = 0; $i < count($rows); $i++) {
            if (empty($rows[$i]['settled'])) {
                continue;
            }
            $rows[$i]['payout'] = (int)($rows[$i]['payout'] ?? 0) + (int)($bonusFloor[$i] ?? 0);

            $won = !empty($rows[$i]['won']);
            if (!$won) {
                continue;
            }

            $uid = (int)($rows[$i]['uid'] ?? 0);
            $isBot = !empty($rows[$i]['bot']) || $uid <= 0;
            $payout = (int)($rows[$i]['payout'] ?? 0);

            if ($realMoney && !$isBot && !empty($rows[$i]['debited']) && $payout > 0) {
                $winPays[] = [
                    'uid' => $uid,
                    'pay' => [
                        'round_index' => $roundIndex,
                        'amount'      => $payout,
                        'face'        => $settleFace,
                    ],
                ];
            }
        }

        FansHubYxxStore::writeBets($roundIndex, $rows, 86400);
        if ($winPays) {
            FansHubYxxStore::fanoutWin($winPays);
        }
        $roundMeta = [
            'settle_face' => $settleFace,
            'dice'        => $dice,
            'human_stake' => $humanStake,
            'total_stake_won' => $totalStakeWon,
            'boom_add'    => $boomAdd,
            'boom_release'=> $releasedBoom,
            'cycle_after' => $cycleAfter,
            'hash_seed'   => $hashSeed,
            'ts'          => time(),
        ];
        Cache::set('fh:yxx:roundmeta:' . $roundIndex, $roundMeta, 86400 * 7);

        $rainSummary = FansHubYxxPool::afterRoundSettled($roundIndex, $boomPoolAfterRelease, $roundMeta);
        if (is_array($rainSummary)) {
            Cache::set('fh:yxx:last_rain', $rainSummary, 86400);
        }
    }

    protected static function assertBetInput($uid, $face, $stake)
    {
        if ($uid <= 0) {
            throw new \RuntimeException(FansHubService::h5CopyText('yxx_err_login') ?: '请先登录');
        }
        $cfg = self::configMap();
        if (empty($cfg['enabled'])) {
            throw new \RuntimeException(FansHubService::h5CopyText('yxx_err_closed') ?: '鱼虾蟹暂未开放');
        }
        if (!FansHubYxxPool::canBet()) {
            throw new \RuntimeException(FansHubService::h5CopyText('yxx_err_locked') ?: '鱼虾蟹已暂停');
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
    }

    protected static function normalizeNick($uid, $nick)
    {
        $nick = trim((string)$nick);
        if ($nick === '') {
            $nick = 'U' . (int)$uid;
        }
        return mb_substr($nick, 0, 12, 'UTF-8');
    }

    protected static function formatMyBet(array $row)
    {
        $out = [
            'face'  => (string)($row['face'] ?? ''),
            'stake' => (int)($row['stake'] ?? 0),
        ];
        if (!empty($row['settled'])) {
            $out['result'] = !empty($row['won']) ? 'win' : 'lose';
            $out['payout'] = (int)($row['payout'] ?? 0);
        }
        return $out;
    }

    protected static function boomPool()
    {
        return FansHubYxxPool::grossPool();
    }

    protected static function cycleCount()
    {
        return max(0, (int)Cache::get('fh:yxx:cycle_count'));
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
        return self::diceFromSeed(self::roundHashSeed($roundIndex));
    }

    public static function roundHashSeed($roundIndex)
    {
        return hash('sha256', 'yxx-hall-v1|' . (int)$roundIndex);
    }

    public static function diceFromSeed($hexSeed)
    {
        $hexSeed = strtolower(trim((string)$hexSeed));
        $raw = ctype_xdigit($hexSeed) ? @hex2bin($hexSeed) : false;
        if ($raw === false || strlen($raw) < 3) {
            $raw = hash('sha256', (string)$hexSeed, true);
        }
        return [
            self::FACE_IDS[ord($raw[0]) % 6],
            self::FACE_IDS[ord($raw[1]) % 6],
            self::FACE_IDS[ord($raw[2]) % 6],
        ];
    }

    /**
     * 公开验真：仅返回已开奖期（当前局须已进入 reveal）。
     */
    public static function verifyPayload($roundIndex)
    {
        $roundIndex = (int)$roundIndex;
        if ($roundIndex < 0) {
            throw new \RuntimeException('无效期号');
        }
        $clock = self::clock();
        $cur = (int)$clock['round_index'];
        $revealed = ($roundIndex < $cur) || ($roundIndex === $cur && $clock['phase'] === 'reveal');
        $seed = self::roundHashSeed($roundIndex);
        $dice = self::diceForRound($roundIndex);
        $archived = null;
        try {
            $archived = Db::name('fans_yxx_rounds')->where('round_index', $roundIndex)->find();
        } catch (\Throwable $e) {
            $archived = null;
        }

        $faceMatch = true;
        $seedMatch = true;
        if ($revealed && $archived) {
            $storedFace = (string)($archived['settle_face'] ?? '');
            $storedSeed = (string)($archived['hash_seed'] ?? '');
            if ($storedFace !== '') {
                $faceMatch = ($storedFace === $dice[0]);
            }
            if ($storedSeed !== '') {
                $seedMatch = ($storedSeed === $seed);
            }
        }

        $ok = $revealed && $faceMatch && $seedMatch;
        $labels = [];
        foreach ($dice as $id) {
            $labels[] = self::FACE_LABEL[$id] ?? $id;
        }

        return [
            'kind'          => 'yxx',
            'type_label'    => '鱼虾蟹大厅',
            'round_index'   => $roundIndex,
            'revealed'      => $revealed ? 1 : 0,
            'status_label'  => $revealed ? '已开奖' : '尚未开奖',
            'settle_face'   => $revealed ? $dice[0] : '',
            'settle_label'  => $revealed ? (self::FACE_LABEL[$dice[0]] ?? $dice[0]) : '',
            'dice'          => $revealed ? $dice : ['', '', ''],
            'dice_labels'   => $revealed ? $labels : ['', '', ''],
            'hash_seed'     => $revealed ? $seed : '',
            'hash_formula'  => 'SHA256("yxx-hall-v1|" + round_index)',
            'hash_rule'     => '种子前 3 字节各自 mod 6 → 三骰；第一颗为结算门',
            'verify_ok'     => $ok ? 1 : 0,
            'face_match'    => $faceMatch ? 1 : 0,
            'seed_match'    => $seedMatch ? 1 : 0,
            'human_stake'   => $archived ? (int)$archived['human_stake'] : 0,
            'pool_inject'   => $archived ? (int)$archived['pool_inject'] : 0,
            'boom_release'  => $archived ? (int)$archived['boom_release'] : 0,
            'verify_hint'   => $revealed
                ? ('复算：' . $seed . ' 前 3 字节映射为 ' . implode(' / ', $labels) . '，结算门=' . (self::FACE_LABEL[$dice[0]] ?? $dice[0]))
                : '该期尚未开奖，开奖后再查询可核对种子与图腾。',
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
}
