<?php

namespace app\common\library;

use think\Cache;
use think\Db;

/**
 * 鱼虾蟹大厅：全局 20s 局钟；白皮书单骰结算（第一颗骰）；台面展示三骰。
 * 开奖绑定未来波场区块：投注/封盘锁定高度，揭晓用 Block Hash（跳过前导 0x00 后）前 3 字节。
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

    const BET_SEC = 9;
    const LOCK_SEC = 3;
    const REVEAL_SEC = 8;
    const CYCLE_SEC = 20;
    const EPOCH = 1755446400;

    /** 单门中奖倍数：6 × 92% */
    const PAYOUT_MULT = 5.52;
    const BOOM_RATE = 0.05;

    /** 当前请求作用域：0 大厅 */
    protected static $groupId = 0;

    public static function currentGroupId()
    {
        return (int)self::$groupId;
    }

    /**
     * 群桌作用域。务必在 finally 还原，避免 php-fpm worker 串桌。
     */
    public static function withGroup($groupId, $fn)
    {
        $prev = (int)self::$groupId;
        self::$groupId = max(0, (int)$groupId);
        FansHubYxxStore::useGroup(self::$groupId);
        try {
            return $fn();
        } finally {
            self::$groupId = $prev;
            FansHubYxxStore::useGroup($prev);
        }
    }

    protected static function ck($tail)
    {
        $g = (int)self::$groupId;
        return $g > 0 ? ('fh:yxx:g' . $g . ':' . $tail) : ('fh:yxx:' . $tail);
    }

    public static function configMap()
    {
        $cfg = FansHubService::config();
        $over = FansHubYxxPool::runtimeSettings();
        $min = max(1, (int)($over['stake_min'] ?? $cfg['yxx_stake_min'] ?? 50));
        $max = max($min, (int)($over['stake_max'] ?? $cfg['yxx_stake_max'] ?? 200));
        $botMin = max(0, (int)($over['bot_count_min'] ?? $cfg['yxx_bot_count_min'] ?? 10));
        $botMax = max($botMin, (int)($over['bot_count_max'] ?? $cfg['yxx_bot_count_max'] ?? 22));
        $botStakeMin = max(1, (int)($over['bot_stake_min'] ?? $cfg['yxx_bot_stake_min'] ?? $min));
        $botStakeMax = max($botStakeMin, (int)($over['bot_stake_max'] ?? $cfg['yxx_bot_stake_max'] ?? $max));
        // 机器人注额不得超出大厅允许区间
        if ($botStakeMin < $min) {
            $botStakeMin = $min;
        }
        if ($botStakeMax > $max) {
            $botStakeMax = $max;
        }
        if ($botStakeMax < $botStakeMin) {
            $botStakeMax = $botStakeMin;
        }
        $botEnabled = array_key_exists('yxx_bot_enabled', $cfg) ? !empty($cfg['yxx_bot_enabled']) : true;
        if (array_key_exists('bot_enabled', $over)) {
            $botEnabled = !empty($over['bot_enabled']);
        }
        $botReal = array_key_exists('yxx_bot_real', $cfg) ? !empty($cfg['yxx_bot_real']) : false;
        if (array_key_exists('bot_real', $over)) {
            $botReal = !empty($over['bot_real']);
        }
        return [
            'enabled'        => !empty($cfg['yxx_enabled']),
            'tab_visible'    => !empty($cfg['yxx_tab_visible']),
            'real_money'     => array_key_exists('yxx_real_money', $cfg) ? !empty($cfg['yxx_real_money']) : false,
            'stake_min'      => $min,
            'stake_max'      => $max,
            'cycle_max'      => max(2, (int)($over['cycle_max'] ?? $cfg['yxx_cycle_max'] ?? 50)),
            'boom_from'      => max(1, (int)($over['boom_from'] ?? $cfg['yxx_boom_from'] ?? 30)),
            'bot_enabled'    => $botEnabled,
            'bot_count_min'  => $botMin,
            'bot_count_max'  => $botMax,
            'bot_stake_min'  => $botStakeMin,
            'bot_stake_max'  => $botStakeMax,
            'bot_real'       => $botReal,
            'tron_offset'    => max(2, min(8, (int)($over['tron_offset'] ?? $cfg['yxx_tron_offset'] ?? 4))),
        ];
    }

    public static function hallPayload($uid = 0, $groupId = 0)
    {
        $uid = (int)$uid;
        $groupId = (int)$groupId;
        return self::withGroup($groupId, function () use ($uid, $groupId) {
            if ($groupId > 0) {
                if ($uid <= 0) {
                    throw new \RuntimeException(FansHubService::h5CopyText('yxx_err_login') ?: '请先登录');
                }
                FansHubYxxGroup::assertMember($groupId, $uid);
                FansHubYxxGroup::assertPermitted($groupId);
                if (!FansHubYxxGroup::isOpen($groupId)) {
                    throw new \RuntimeException(FansHubService::h5CopyText('yxx_err_table_closed') ?: '本群尚未开启鱼虾蟹');
                }
            }
            // 万人轮询只读：结算/机器人/波场锁定一律走 tickEngine(cron)
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
            $rainPopup = ($uid > 0 && $groupId <= 0) ? FansHubYxxPool::pendingPopup($uid) : null;
            $snap['rain_popup'] = $rainPopup;
            $snap['my_bet'] = $my;
            $snap['group_id'] = $groupId;
            if ($groupId > 0) {
                $snap['group'] = FansHubYxxGroup::publicState($groupId);
            }
            $snap['round']['remain_sec'] = (int)$clock['remain_sec'];
            $snap['round']['phase'] = (string)$clock['phase'];
            $snap['round']['round_index'] = (int)$clock['round_index'];
            $snap['server_ts'] = time();
            // 拉长轮询：下注 4s / 封盘揭晓 1.5s；有红包雨时 1.5s
            $poll = ($clock['phase'] === 'betting') ? 4000 : 1500;
            if (is_array($rainPopup)) {
                $poll = 1500;
            }
            $snap['poll_ms'] = $poll;
            return $snap;
        });
    }

    /**
     * cron / 匿名踢一脚：机器人入注 + 结算。大厅万人轮询不再走这里。
     */
    public static function tickEngine()
    {
        $hall = self::withGroup(0, function () {
            $clock = self::clock();
            self::ensureTronCommit((int)$clock['round_index']);
            $bots = self::tickBotsThrottled($clock);
            self::maybeSettleRounds();
            self::dripWins(240, 450);
            FansHubYxxPool::expireUnclaimedRain();
            $clock = self::clock();
            return [
                'ok'          => 1,
                'round_index' => (int)$clock['round_index'],
                'phase'       => (string)$clock['phase'],
                'remain_sec'  => (int)$clock['remain_sec'],
                'bots'        => (int)$bots,
            ];
        });
        $gids = FansHubYxxGroup::openIds();
        $totalG = count($gids);
        $cursor = (int)Cache::get('fh:yxx:gcursor');
        if ($cursor < 0) {
            $cursor = 0;
        }
        $limit = 60;
        $n = 0;
        for ($i = 0; $i < $totalG && $n < $limit; $i++) {
            $idx = ($cursor + $i) % $totalG;
            $gid = (int)$gids[$idx];
            if ($gid <= 0) {
                continue;
            }
            try {
                self::withGroup($gid, function () {
                    self::maybeSettleRounds();
                    self::dripWins(200, 350);
                });
                $n++;
            } catch (\Throwable $e) {
            }
        }
        if ($totalG > 0) {
            Cache::set('fh:yxx:gcursor', ($cursor + max(1, $n)) % $totalG, 86400);
        }
        $hall['group_tables'] = $n;
        $hall['group_open'] = $totalG;
        return $hall;
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
        $payName = FansHubYxxStore::cacheName('winpay:' . $uid);
        $blob = FansHubYxxStore::getJson($payName);
        if (!is_array($blob)) {
            return;
        }
        $rest = [];
        if (!empty($blob['batch']) && is_array($blob['batch'])) {
            $rest = $blob['batch'];
            $pay = array_shift($rest);
            if (!is_array($pay)) {
                FansHubYxxStore::delName($payName);
                return;
            }
        } else {
            $pay = $blob;
        }
        $roundIndex = (int)($pay['round_index'] ?? 0);
        $amount = (int)($pay['amount'] ?? 0);
        $face = (string)($pay['face'] ?? '');
        if ($roundIndex < 0 || $amount <= 0) {
            if ($rest) {
                FansHubYxxStore::setJson($payName, ['batch' => $rest, 'round_index' => $roundIndex, 'amount' => 1], 3600);
            } else {
                FansHubYxxStore::delName($payName);
            }
            return;
        }
        $g = self::currentGroupId();
        $type = (string)($pay['type'] ?? 'yxx_win');
        if ($type !== 'yxx_owner' && $type !== 'yxx_win') {
            $type = 'yxx_win';
        }
        if (!empty($pay['biz_no'])) {
            $biz = mb_substr((string)$pay['biz_no'], 0, 40);
        } else {
            $biz = ($type === 'yxx_owner' ? 'YXXOWN' : 'YXXW') . $roundIndex . ($g > 0 ? ('-G' . $g) : '') . '-' . $uid;
        }
        $lockName = self::ck('wpaid:' . $biz);
        if (!FansHubYxxStore::acquireLock($lockName, 86400 * 7)) {
            if ($rest) {
                FansHubYxxStore::setJson($payName, ['batch' => $rest, 'round_index' => $roundIndex, 'amount' => 1], 3600);
            } else {
                FansHubYxxStore::delName($payName);
            }
            return;
        }
        try {
            if (!empty($pay['remark'])) {
                $remark = (string)$pay['remark'];
            } else {
                $remark = $type === 'yxx_owner'
                    ? ('鱼虾蟹群主分成 R' . $roundIndex)
                    : ($g > 0 ? ('鱼虾蟹群中奖 R' . $roundIndex) : ('鱼虾蟹中奖 R' . $roundIndex));
            }
            FansHubHongbaoLedger::credit($uid, $amount, $type, $remark, [
                'biz_no'   => $biz,
                'ref_type' => (string)($pay['ref_type'] ?? 'yxx_round'),
                'ref_id'   => (int)($pay['ref_id'] ?? $roundIndex),
                'channel'  => $face,
            ]);
            if ($rest) {
                FansHubYxxStore::setJson($payName, ['batch' => $rest, 'round_index' => $roundIndex, 'amount' => 1], 3600);
                $redis = FansHubYxxStore::redis();
                if ($redis) {
                    try {
                        $redis->rPush(FansHubYxxStore::rkey(FansHubYxxStore::cacheName('winq')), (string)$uid);
                    } catch (\Throwable $e) {
                    }
                }
            } else {
                FansHubYxxStore::delName($payName);
            }
        } catch (\Throwable $e) {
            FansHubYxxStore::releaseLock($lockName);
        }
    }

    /**
     * cron 分批把中奖写入钱包；按时间预算截断，避免拖死 yxxtick。
     */
    protected static function dripWins($batch = 240, $budgetMs = 450)
    {
        $batch = max(40, min(400, (int)$batch));
        $budgetMs = max(100, min(2000, (int)$budgetMs));
        $t0 = microtime(true);
        foreach (FansHubYxxStore::popWinQueue($batch) as $uid) {
            self::lazyCreditWin((int)$uid);
            if (((microtime(true) - $t0) * 1000) >= $budgetMs) {
                break;
            }
        }
    }

    /**
     * 解散群：先尽量结算/退注当局，再按本群累计下注权重强制分完爆点池。
     * 关桌（stop）不走这里。
     */
    public static function dissolveGroupTable($groupId, array $memberIds)
    {
        $groupId = (int)$groupId;
        if ($groupId <= 0) {
            return ['ok' => 0, 'paid' => 0];
        }
        $lockName = 'fh:yxx:dissolve:g' . $groupId;
        if (!FansHubYxxStore::acquireLock($lockName, 30)) {
            return ['ok' => 0, 'busy' => 1];
        }
        try {
            return self::withGroup($groupId, function () use ($groupId, $memberIds) {
                try {
                    self::maybeSettleRounds();
                } catch (\Throwable $e) {
                }
                for ($i = 0; $i < 8; $i++) {
                    self::dripWins();
                }
                foreach ($memberIds as $uid) {
                    try {
                        self::lazyCreditWin((int)$uid);
                    } catch (\Throwable $e) {
                    }
                }
                $clock = self::clock();
                $ri = (int)($clock['round_index'] ?? 0);
                $phase = (string)($clock['phase'] ?? '');
                if ($ri >= 0 && ($phase === 'betting' || $phase === 'lock')) {
                    $rows = FansHubYxxStore::loadBets($ri);
                    foreach (is_array($rows) ? $rows : [] as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $uid = (int)($row['uid'] ?? 0);
                        $stake = (int)($row['stake'] ?? 0);
                        if ($uid <= 0 || $stake <= 0 || !empty($row['bot']) || !empty($row['settled'])) {
                            continue;
                        }
                        if (empty($row['debited'])) {
                            continue;
                        }
                        try {
                            FansHubHongbaoLedger::credit($uid, $stake, 'yxx_bet_refund', '鱼虾蟹群解散退注 R' . $ri, [
                                'biz_no'   => 'YXXDISR' . $ri . '-G' . $groupId . '-' . $uid,
                                'ref_type' => 'yxx_group',
                                'ref_id'   => $groupId,
                            ]);
                            FansHubYxxPool::adjustDailyBet($uid, -$stake);
                            FansHubYxxGroup::adjustDaily($groupId, $uid, -$stake);
                        } catch (\Throwable $e) {
                        }
                    }
                    FansHubYxxStore::clearRoundBets($ri);
                }
                return FansHubYxxGroup::payoutBoomOnDissolve($groupId, $memberIds);
            });
        } finally {
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
        $revealed = ($phase === 'reveal');
        if ($revealed || $phase === 'locking') {
            self::ensureDisplayDice($roundIndex, $phase);
        }
        $tronPub = self::tronPublic($roundIndex, $revealed);
        $dice = self::diceForRound($roundIndex);
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
                'round_index'    => $idx,
                'dice'           => $d,
                'settle_face'    => $d[0],
                'hash_seed'      => self::roundHashSeed($idx),
                'tron_block_num' => (int)(self::tronPublic($idx, true)['tron_block_num'] ?? 0),
            ];
        }

        $live = [];
        foreach (FansHubYxxStore::liveFeed($roundIndex, 16) as $row) {
            $faces = self::facesOf($row);
            if (!$faces) {
                continue;
            }
            $live[] = [
                'nick'  => (string)($row['nick'] ?? ''),
                'face'  => $faces[0],
                'faces' => $faces,
                'unit'  => self::unitOf($row),
                'stake' => self::totalOf($row),
                'bot'   => !empty($row['bot']) ? 1 : 0,
            ];
        }

        $poolInfo = FansHubYxxPool::hallPoolPayload();
        $gid = self::currentGroupId();
        if ($gid > 0) {
            $gstate = FansHubYxxGroup::publicState($gid);
            $poolInfo['gross_pool'] = (int)$gstate['gross_pool'];
            $poolInfo['rain_progress'] = 0;
            $poolInfo['pool_enabled'] = 1;
        }
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
            'allow_multi' => $gid > 0 ? 1 : 0,
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
                'tron_block_num' => (int)($tronPub['tron_block_num'] ?? 0),
                'tron_ready'     => (int)($tronPub['tron_ready'] ?? 0),
            ],
            'dice'        => ($revealed || ($phase === 'locking' && ($dice[0] ?? '') !== '')) ? $dice : ['', '', ''],
            'settle_face' => $revealed ? $dice[0] : (($phase === 'locking' && ($dice[0] ?? '') !== '') ? $dice[0] : ''),
            'history'     => $history,
            'live_bets'   => $live,
            'my_bet'      => null,
        ];
        FansHubYxxStore::setSnap($payload, 2);
        return $payload;
    }

    public static function placeBet($uid, $face, $stake, $nick = '', $groupId = 0)
    {
        $groupId = (int)$groupId;
        return self::withGroup($groupId, function () use ($uid, $face, $stake, $nick, $groupId) {
            if ($groupId > 0) {
                FansHubYxxGroup::assertMember($groupId, (int)$uid);
                FansHubYxxGroup::assertPermitted($groupId);
                if (!FansHubYxxGroup::isOpen($groupId)) {
                    throw new \RuntimeException(FansHubService::h5CopyText('yxx_err_table_closed') ?: '本群尚未开启鱼虾蟹');
                }
            }
            $cfg = self::configMap();
            if (!empty($cfg['real_money'])) {
                if (!FansHubYxxStore::redis()) {
                    throw new \RuntimeException(FansHubService::h5CopyText('yxx_err_busy') ?: '大厅繁忙，请稍后再试');
                }
                return self::placeRealBet($uid, $face, $stake, $nick);
            }
            return self::placePreviewBet($uid, $face, $stake, $nick);
        });
    }

    public static function placePreviewBet($uid, $face, $stake, $nick = '')
    {
        $uid = (int)$uid;
        list($faces, $unit) = self::assertBetInput($uid, $face, $stake);
        $nick = self::normalizeNick($uid, $nick);
        $roundIndex = (int)self::clock()['round_index'];
        $lockName = self::ck('ubet:' . $roundIndex . ':' . $uid);
        if (!FansHubYxxStore::acquireLock($lockName, 8)) {
            throw new \RuntimeException(FansHubService::h5CopyText('yxx_err_fast') ?: '操作太快，请稍后再试');
        }
        try {
            $total = $unit * count($faces);
            FansHubYxxStore::putBet($roundIndex, [
                'uid'     => $uid,
                'nick'    => $nick,
                'face'    => $faces[0],
                'faces'   => $faces,
                'unit'    => $unit,
                'stake'   => $total,
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
        return self::hallPayload($uid, self::currentGroupId());
    }

    public static function placeRealBet($uid, $face, $stake, $nick = '', $asBot = false)
    {
        $uid = (int)$uid;
        $asBot = !empty($asBot);
        list($faces, $unit) = self::assertBetInput($uid, $face, $stake);
        $nick = self::normalizeNick($uid, $nick);
        $roundIndex = (int)self::clock()['round_index'];
        $lockName = self::ck('ubet:' . $roundIndex . ':' . $uid);
        if (!FansHubYxxStore::acquireLock($lockName, 8)) {
            throw new \RuntimeException(FansHubService::h5CopyText('yxx_err_fast') ?: '操作太快，请稍后再试');
        }
        try {
            $prev = FansHubYxxStore::getBet($roundIndex, $uid);
            $prevStake = 0;
            if (is_array($prev) && (empty($prev['bot']) || $asBot)) {
                $prevStake = self::totalOf($prev);
            }
            $stake = $unit * count($faces);
            $g = self::currentGroupId();
            $tag = $g > 0 ? ('-G' . $g) : '';
            if ($prevStake !== $stake) {
                if ($prevStake > 0) {
                    FansHubHongbaoLedger::credit($uid, $prevStake, 'yxx_bet_refund', '鱼虾蟹改注退回 R' . $roundIndex, [
                        'biz_no'   => 'YXXR' . $roundIndex . $tag . '-' . $uid,
                        'ref_type' => 'yxx_round',
                        'ref_id'   => $roundIndex,
                    ]);
                    if (!$asBot) {
                        FansHubYxxPool::adjustDailyBet($uid, -$prevStake);
                        if ($g > 0) {
                            FansHubYxxGroup::adjustDaily($g, $uid, -$prevStake);
                        }
                    }
                }
                FansHubHongbaoLedger::debit($uid, $stake, 'yxx_bet', '鱼虾蟹下注 R' . $roundIndex, [
                    'biz_no'   => 'YXX' . $roundIndex . $tag . '-' . $uid,
                    'ref_type' => 'yxx_round',
                    'ref_id'   => $roundIndex,
                ], true);
                // 机器人不计入红包雨资格日投，避免抢走真人雨包
                if (!$asBot) {
                    FansHubYxxPool::touchDailyBet($uid, $stake);
                    if ($g > 0) {
                        FansHubYxxGroup::touchDaily($g, $uid, $stake);
                    }
                }
            }
            FansHubYxxStore::putBet($roundIndex, [
                'uid'     => $uid,
                'nick'    => $nick,
                'face'    => $faces[0],
                'faces'   => $faces,
                'unit'    => $unit,
                'stake'   => $stake,
                'bot'     => $asBot ? 1 : 0,
                'debited' => 1,
                'settled' => 0,
                'won'     => 0,
                'payout'  => 0,
                'ts'      => time(),
            ]);
        } finally {
            FansHubYxxStore::releaseLock($lockName);
        }
        return self::hallPayload($uid, self::currentGroupId());
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
        $useReal = !empty($cfg['bot_real']) && !empty($cfg['real_money']);
        $plan = self::botPlan($roundIndex, $cfg, $useReal);
        $added = 0;
        foreach ($plan as $bot) {
            if ((int)$bot['at'] > $offset) {
                continue;
            }
            $buid = (int)$bot['uid'];
            if ($buid === 0) {
                continue;
            }
            if (FansHubYxxStore::hasBet($roundIndex, $buid)) {
                continue;
            }
            if ($useReal && $buid > 0) {
                try {
                    self::placeRealBet($buid, (string)$bot['face'], (int)$bot['stake'], (string)$bot['nick'], true);
                    $added++;
                    continue;
                } catch (\Throwable $e) {
                    // 余额不足等：同 uid 写展示注，计入人数/奖金池/蓄水，下轮 tick 不再重试
                }
            }
            FansHubYxxStore::putBet($roundIndex, [
                'uid'     => $buid,
                'nick'    => (string)$bot['nick'],
                'face'    => (string)$bot['face'],
                'stake'   => (int)$bot['stake'],
                'bot'     => 1,
                'debited' => 0,
                'settled' => 0,
                'won'     => 0,
                'payout'  => 0,
                'ts'      => time(),
            ]);
            $added++;
        }
        return $added;
    }

    protected static function maybeSettleRounds()
    {
        $clock = self::clock();
        $roundIndex = (int)$clock['round_index'];
        // 当局等 reveal，给锁定的波场块出块时间（封盘 3s 不够）
        if ($clock['phase'] === 'reveal') {
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
        $doneKey = self::ck('settled:' . $roundIndex);
        if (Cache::get($doneKey)) {
            return;
        }
        $lockName = self::ck('settlelock:' . $roundIndex);
        if (!FansHubYxxStore::acquireLock($lockName, 15)) {
            return;
        }
        try {
            if (Cache::get($doneKey)) {
                return;
            }
            if (!self::resolveTronForRound($roundIndex)) {
                return;
            }
            $dice = self::diceForRound($roundIndex);
            if ($dice[0] === '' || !isset(self::FACE_LABEL[$dice[0]])) {
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

        $tronRes = Cache::get('fh:yxx:tronres:' . $roundIndex);
        if (!is_array($tronRes)) {
            $tronRes = [];
        }
        $dice = self::diceForRound($roundIndex);
        $settleFace = $dice[0];
        $rows = FansHubYxxStore::loadBets($roundIndex);

        // 先计算人类下注量 + 中奖门口总下注量（用于爆点分摊）
        $humanStake = 0;
        $totalStakeWon = 0;
        $totalStakeAll = 0;
        for ($i = 0; $i < count($rows); $i++) {
            $row = $rows[$i];
            $uid = (int)($row['uid'] ?? 0);
            $isBot = !empty($row['bot']) || $uid <= 0;
            $total = self::totalOf($row);
            $unit = self::unitOf($row);
            $faces = self::facesOf($row);

            $totalStakeAll += $total;
            if (!$isBot) {
                $humanStake += $total;
            }
            if (in_array($settleFace, $faces, true)) {
                $totalStakeWon += $unit;
            }
        }

        $gid = self::currentGroupId();
        if ($gid > 0 && $realMoney && $humanStake > 0) {
            $ownerId = FansHubYxxGroup::ownerId($gid);
            $cut = (int)floor($humanStake * FansHubYxxGroup::OWNER_RATE);
            if ($ownerId > 0 && $cut > 0) {
                FansHubYxxStore::fanoutWin([[
                    'uid' => $ownerId,
                    'pay' => [
                        'round_index' => $roundIndex,
                        'amount'      => $cut,
                        'face'        => '',
                        'type'        => 'yxx_owner',
                        'biz_no'      => 'YXXOWN' . $roundIndex . '-G' . $gid,
                        'remark'      => '鱼虾蟹群主分成 R' . $roundIndex,
                        'ref_type'    => 'yxx_group',
                        'ref_id'      => $gid,
                    ],
                ]]);
            }
        }

        // 爆点/蓄水池：人类 + 机器人下注一并计入（人数/奖金池展示已含机器人，蓄水与红包雨需同步）
        $boomPoolBefore = self::boomPool();
        $boomAdd = $totalStakeAll > 0 ? (int)floor($totalStakeAll * self::BOOM_RATE) : 0;
        $boomPoolAfterAdd = max(0, $boomPoolBefore + $boomAdd);

        // 循环计数：本局有任意有效投注（含机器人）即推进
        $cycleBefore = self::cycleCount();
        $cycleAdvance = $totalStakeAll > 0 ? 1 : 0;
        $cycleAfter = $cycleBefore + $cycleAdvance;

        // 爆点释放：在 [boom_from, cycle_max] 区间内触发一次（熔断 paused/locked 时只蓄水不释放）
        $releasedBoom = 0;
        $nextHalfBoomCount = 0;
        $halfRaw = Cache::get(self::ck('boom_half_count'));
        if ($halfRaw === false || $halfRaw === null) {
            $halfBoomCount = $gid > 0 ? FansHubYxxGroup::halfBoom($gid) : 0;
        } else {
            $halfBoomCount = (int)$halfRaw;
        }
        if (FansHubYxxPool::canBoomRelease()
            && $cycleAfter >= (int)$cfg['boom_from']
            && $cycleAfter <= (int)$cfg['cycle_max']
        ) {
            $releasedKey = self::ck('boom_release:' . (int)$cycleAfter);
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
                Cache::set(self::ck('boom_half_count'), $nextHalfBoomCount, 86400 * 30);
            } else {
                $nextHalfBoomCount = $halfBoomCount;
            }
        } else {
            $nextHalfBoomCount = $halfBoomCount;
        }

        // 更新爆点/循环缓存（无论是否释放都先写入最新累积值）
        $boomPoolAfterRelease = max(0, $boomPoolAfterAdd - $releasedBoom);
        $hashSeed = self::roundHashSeed($roundIndex);
        Cache::set(self::ck('cycle_count'), max(0, $cycleAfter), 86400 * 30);
        Cache::set(self::ck('boom_half_count'), max(0, $nextHalfBoomCount), 86400 * 30);
        $gid = self::currentGroupId();
        if ($gid > 0) {
            FansHubYxxGroup::setCycle($gid, $cycleAfter, $nextHalfBoomCount);
        }

        // 结算行：单骰固定倍率 + 爆点释放奖金分摊（双重上限）
        $dayGames = [];
        if ($gid <= 0 && $releasedBoom > 0) {
            $need = [];
            for ($i = 0; $i < count($rows); $i++) {
                $u = (int)($rows[$i]['uid'] ?? 0);
                if ($u > 0 && empty($rows[$i]['bot'])) {
                    $need[$u] = true;
                }
            }
            $dayGames = FansHubYxxPool::dayGameCounts(array_keys($need));
        }
        $winWeights = [];
        for ($i = 0; $i < count($rows); $i++) {
            if (!empty($rows[$i]['settled'])) {
                continue;
            }

            $uid = (int)($rows[$i]['uid'] ?? 0);
            $isBot = !empty($rows[$i]['bot']) || $uid <= 0;
            $unit = self::unitOf($rows[$i]);
            $won = in_array($settleFace, self::facesOf($rows[$i]), true);

            $rows[$i]['settled'] = 1;
            $rows[$i]['won'] = $won ? 1 : 0;

            $basePayout = $won ? (int)round($unit * self::PAYOUT_MULT) : 0;
            $rows[$i]['payout'] = $basePayout;

            if ($won && !$isBot && $releasedBoom > 0 && $totalStakeWon > 0) {
                $w = $unit;
                if ($gid <= 0) {
                    $games = (int)($dayGames[$uid] ?? 0);
                    if ($games < 10) {
                        $w = max(1, (int)floor($unit * 0.1));
                    }
                }
                $winWeights[$i] = $w;
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
            $payout = (int)($rows[$i]['payout'] ?? 0);

            // 真金已扣款（含真金机器人）派彩；虚机人 uid<=0 / 未扣款不派。爆点加成仅人类（上段 winWeights）。
            if ($realMoney && $uid > 0 && !empty($rows[$i]['debited']) && $payout > 0) {
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
            'total_stake' => $totalStakeAll,
            'total_stake_won' => $totalStakeWon,
            'boom_add'    => $boomAdd,
            'boom_release'=> $releasedBoom,
            'cycle_after' => $cycleAfter,
            'hash_seed'      => $hashSeed,
            'tron_block_num' => (int)($tronRes['block_num'] ?? 0),
            'tron_block_id'  => (string)($tronRes['block_id'] ?? ''),
            'ts'             => time(),
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
        $allowMulti = self::currentGroupId() > 0;
        $faces = self::parseFaceInput($face, $allowMulti);
        $unit = (int)$stake;
        if ($unit < $cfg['stake_min'] || $unit > $cfg['stake_max']) {
            throw new \RuntimeException(FansHubService::h5CopyText('yxx_err_stake', [
                'min' => $cfg['stake_min'],
                'max' => $cfg['stake_max'],
            ]) ?: ('下注 ' . $cfg['stake_min'] . '-' . $cfg['stake_max'] . ' 积分'));
        }
        return [$faces, $unit];
    }

    /**
     * @param mixed $raw
     * @return string[]
     */
    public static function parseFaceInput($raw, $allowMulti)
    {
        $parts = [];
        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $raw = trim((string)$raw);
            if ($raw !== '') {
                $parts = preg_split('/[,\s|]+/', $raw);
            }
        }
        $out = [];
        foreach ($parts as $f) {
            $f = strtolower(trim((string)$f));
            if (isset(self::FACE_LABEL[$f]) && !isset($out[$f])) {
                $out[$f] = $f;
            }
        }
        $out = array_values($out);
        if (!$out) {
            throw new \RuntimeException(FansHubService::h5CopyText('yxx_err_face') ?: '请选择一个图案');
        }
        if (!$allowMulti && count($out) > 1) {
            throw new \RuntimeException(FansHubService::h5CopyText('yxx_err_face_one') ?: '大厅每局限选一门');
        }
        return $out;
    }

    /**
     * @return string[]
     */
    public static function facesOf(array $row)
    {
        $out = [];
        $raw = $row['faces'] ?? null;
        $parts = [];
        if (is_array($raw)) {
            $parts = $raw;
        } elseif (is_string($raw) && $raw !== '') {
            $parts = preg_split('/[,\s|]+/', $raw);
        }
        foreach ($parts as $f) {
            $f = strtolower(trim((string)$f));
            if (isset(self::FACE_LABEL[$f]) && !isset($out[$f])) {
                $out[$f] = $f;
            }
        }
        if (!$out) {
            $f = strtolower(trim((string)($row['face'] ?? '')));
            if (isset(self::FACE_LABEL[$f])) {
                $out[$f] = $f;
            }
        }
        return array_values($out);
    }

    public static function unitOf(array $row)
    {
        $unit = (int)($row['unit'] ?? 0);
        if ($unit > 0) {
            return $unit;
        }
        $faces = self::facesOf($row);
        $stake = max(0, (int)($row['stake'] ?? 0));
        $n = count($faces);
        if ($n > 1 && $stake > 0) {
            return max(1, (int)floor($stake / $n));
        }
        return $stake;
    }

    public static function totalOf(array $row)
    {
        $faces = self::facesOf($row);
        $unit = (int)($row['unit'] ?? 0);
        if ($unit > 0 && $faces) {
            return $unit * count($faces);
        }
        return max(0, (int)($row['stake'] ?? 0));
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
        $faces = self::facesOf($row);
        $unit = self::unitOf($row);
        $out = [
            'face'  => $faces ? $faces[0] : (string)($row['face'] ?? ''),
            'faces' => $faces,
            'unit'  => $unit,
            'stake' => self::totalOf($row),
        ];
        if (!empty($row['settled'])) {
            $out['result'] = !empty($row['won']) ? 'win' : 'lose';
            $out['payout'] = (int)($row['payout'] ?? 0);
        }
        return $out;
    }

    protected static function boomPool()
    {
        $g = self::currentGroupId();
        if ($g > 0) {
            return FansHubYxxGroup::gross($g);
        }
        return FansHubYxxPool::grossPool();
    }

    protected static function cycleCount()
    {
        $cached = Cache::get(self::ck('cycle_count'));
        if ($cached !== false && $cached !== null) {
            return max(0, (int)$cached);
        }
        $g = self::currentGroupId();
        if ($g > 0) {
            return FansHubYxxGroup::cycle($g);
        }
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

    /**
     * 投注/封盘阶段锁定未来波场高度（只写高度，不写哈希）。
     */
    protected static function ensureTronCommit($roundIndex)
    {
        $roundIndex = (int)$roundIndex;
        if ($roundIndex < 0) {
            return;
        }
        if (Cache::get('fh:yxx:troncommit:' . $roundIndex) || Cache::get('fh:yxx:tronres:' . $roundIndex)) {
            return;
        }
        $clock = self::clock();
        if ((int)$clock['round_index'] !== $roundIndex || $clock['phase'] === 'reveal') {
            return;
        }
        $lockName = 'fh:yxx:tronclk:' . $roundIndex;
        if (!FansHubYxxStore::acquireLock($lockName, 8)) {
            return;
        }
        try {
            if (Cache::get('fh:yxx:troncommit:' . $roundIndex)) {
                return;
            }
            $now = TronBlockClient::getNowBlockNum(3);
            $offset = (int)(self::configMap()['tron_offset'] ?? 4);
            $target = (int)$now + $offset;
            if ($target <= 0) {
                return;
            }
            Cache::set('fh:yxx:troncommit:' . $roundIndex, [
                'block_num' => $target,
                'now_num'   => (int)$now,
                'ts'        => time(),
            ], 86400 * 7);
            FansHubYxxStore::clearSnap();
        } catch (\Throwable $e) {
        } finally {
            FansHubYxxStore::releaseLock($lockName);
        }
    }

    /**
     * 封盘/开奖展示阶段：尽量解析波场；reveal 仍无结果时用 legacy，避免前台空白。
     */
    protected static function ensureDisplayDice($roundIndex, $phase)
    {
        $roundIndex = (int)$roundIndex;
        $phase = (string)$phase;
        if ($phase !== 'reveal' && $phase !== 'locking') {
            return;
        }
        self::resolveTronForRound($roundIndex);
        $dice = self::diceForRound($roundIndex);
        if (($dice[0] ?? '') !== '') {
            return;
        }
        if ($phase !== 'reveal') {
            return;
        }
        Cache::set('fh:yxx:tronres:' . $roundIndex, [
            'block_num' => 0,
            'block_id'  => '',
            'legacy'    => 1,
        ], 86400 * 7);
        FansHubYxxStore::clearSnap();
    }

    /**
     * 拉取已锁定高度的 Block Hash。未出块则返回 false（不标记已结算）。
     */
    protected static function resolveTronForRound($roundIndex)
    {
        $roundIndex = (int)$roundIndex;
        if ($roundIndex < 0) {
            return false;
        }
        $res = Cache::get('fh:yxx:tronres:' . $roundIndex);
        if (is_array($res) && self::normalizeHexSeed((string)($res['block_id'] ?? '')) !== '') {
            return true;
        }
        if (is_array($res) && !empty($res['legacy'])) {
            return true;
        }
        $commit = Cache::get('fh:yxx:troncommit:' . $roundIndex);
        $blockNum = (int)($commit['block_num'] ?? 0);
        if ($blockNum <= 0) {
            $clock = self::clock();
            if ((int)$clock['round_index'] > $roundIndex) {
                Cache::set('fh:yxx:tronres:' . $roundIndex, [
                    'block_num' => 0,
                    'block_id'  => '',
                    'legacy'    => 1,
                ], 86400 * 7);
                return true;
            }
            return false;
        }
        try {
            $block = TronBlockClient::getBlockByNum($blockNum, 5);
            $id = self::normalizeHexSeed((string)($block['block_id'] ?? ''));
            if ($id === '') {
                return false;
            }
            Cache::set('fh:yxx:tronres:' . $roundIndex, [
                'block_num' => $blockNum,
                'block_id'  => $id,
            ], 86400 * 7);
            FansHubYxxStore::clearSnap();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected static function tronPublic($roundIndex, $revealed)
    {
        $roundIndex = (int)$roundIndex;
        $res = Cache::get('fh:yxx:tronres:' . $roundIndex);
        $commit = Cache::get('fh:yxx:troncommit:' . $roundIndex);
        $meta = Cache::get('fh:yxx:roundmeta:' . $roundIndex);
        $num = (int)($res['block_num'] ?? $commit['block_num'] ?? $meta['tron_block_num'] ?? 0);
        $id = '';
        if ($revealed) {
            $id = self::normalizeHexSeed((string)($res['block_id'] ?? $meta['tron_block_id'] ?? ''));
        }
        return [
            'tron_block_num' => $num,
            'tron_block_id'  => $id,
            'tron_ready'     => ($id !== '') ? 1 : 0,
            'legacy'         => !empty($res['legacy']) ? 1 : 0,
        ];
    }

    protected static function normalizeHexSeed($hex)
    {
        $hex = strtolower(trim((string)$hex));
        if (strpos($hex, '0x') === 0) {
            $hex = substr($hex, 2);
        }
        return $hex;
    }

    public static function diceForRound($roundIndex)
    {
        $seed = self::roundHashSeed($roundIndex);
        if ($seed === '') {
            return ['', '', ''];
        }
        return self::diceFromSeed($seed);
    }

    public static function roundHashSeed($roundIndex)
    {
        $roundIndex = (int)$roundIndex;
        $res = Cache::get('fh:yxx:tronres:' . $roundIndex);
        if (is_array($res)) {
            $id = self::normalizeHexSeed((string)($res['block_id'] ?? ''));
            if ($id !== '') {
                return $id;
            }
            if (!empty($res['legacy'])) {
                return hash('sha256', 'yxx-hall-v1|' . $roundIndex);
            }
        }
        $meta = Cache::get('fh:yxx:roundmeta:' . $roundIndex);
        if (is_array($meta)) {
            $s = self::normalizeHexSeed((string)($meta['hash_seed'] ?? ''));
            if ($s !== '') {
                return $s;
            }
        }
        try {
            $row = Db::name('fans_yxx_rounds')->where('round_index', $roundIndex)->find();
            if (is_array($row)) {
                $id = self::normalizeHexSeed((string)($row['tron_block_id'] ?? ''));
                if ($id !== '') {
                    Cache::set('fh:yxx:tronres:' . $roundIndex, [
                        'block_num' => (int)($row['tron_block_num'] ?? 0),
                        'block_id'  => $id,
                    ], 86400 * 7);
                    return $id;
                }
                $s = trim((string)($row['hash_seed'] ?? ''));
                if ($s !== '') {
                    Cache::set('fh:yxx:tronres:' . $roundIndex, [
                        'block_num' => 0,
                        'block_id'  => '',
                        'legacy'    => 1,
                    ], 86400 * 7);
                    return $s;
                }
            }
        } catch (\Throwable $e) {
        }
        return '';
    }

    public static function diceFromSeed($hexSeed)
    {
        $hexSeed = strtolower(trim((string)$hexSeed));
        $raw = ctype_xdigit($hexSeed) ? @hex2bin($hexSeed) : false;
        if ($raw === false || strlen($raw) < 3) {
            $raw = hash('sha256', (string)$hexSeed, true);
        } else {
            // Tron Block ID 常以大量 0x00 开头，直接取前 3 字节会恒为葫芦/葫芦/葫芦
            $trimmed = ltrim($raw, "\0");
            if (strlen($trimmed) >= 3) {
                $raw = $trimmed;
            } else {
                $raw = hash('sha256', $hexSeed, true);
            }
        }
        return [
            self::FACE_IDS[ord($raw[0]) % 6],
            self::FACE_IDS[ord($raw[1]) % 6],
            self::FACE_IDS[ord($raw[2]) % 6],
        ];
    }

    /**
     * 公开验真：仅返回已开奖期（当前局须已进入 reveal）；投注中可查锁定高度。
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
        if ($revealed) {
            self::resolveTronForRound($roundIndex);
        } else {
            self::ensureTronCommit($roundIndex);
        }
        $tron = self::tronPublic($roundIndex, $revealed);
        $seed = $revealed ? self::roundHashSeed($roundIndex) : '';
        $dice = ($revealed && $seed !== '') ? self::diceForRound($roundIndex) : ['', '', ''];
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
            $storedSeed = self::normalizeHexSeed((string)($archived['hash_seed'] ?? ''));
            if ($storedFace !== '' && $dice[0] !== '') {
                $faceMatch = ($storedFace === $dice[0]);
            }
            if ($storedSeed !== '' && $seed !== '') {
                $seedMatch = ($storedSeed === self::normalizeHexSeed($seed));
            }
        }

        $hasDice = ($dice[0] !== '');
        $ok = $revealed && $hasDice && $faceMatch && $seedMatch;
        $labels = [];
        foreach ($dice as $id) {
            $labels[] = $id !== '' ? (self::FACE_LABEL[$id] ?? $id) : '';
        }

        $tronNum = (int)($tron['tron_block_num'] ?? 0);
        if ($tronNum <= 0 && $archived) {
            $tronNum = (int)($archived['tron_block_num'] ?? 0);
        }
        $tronId = $revealed ? (string)($tron['tron_block_id'] ?? '') : '';
        if ($revealed && $tronId === '' && $archived) {
            $tronId = self::normalizeHexSeed((string)($archived['tron_block_id'] ?? ''));
        }
        $legacy = $revealed && $tronNum <= 0 && $seed !== '';
        $tronStatus = 0;
        if ($tronId !== '') {
            $tronStatus = 2;
        } elseif ($tronNum > 0) {
            $tronStatus = $revealed ? 1 : 1;
        }

        $formula = $legacy
            ? 'SHA256("yxx-hall-v1|" + round_index)（旧局）'
            : '波场 Block Hash（投注开始锁定高度，开奖取该块哈希）';
        $hint = '该期尚未开奖；已锁定波场高度后可在 TronScan 核对，出块后再复算三骰。';
        if ($revealed && $hasDice) {
            $hint = '复算：Block Hash 去掉前导 00 后取前 3 字节 → ' . implode(' / ', $labels) . '，结算门=' . (self::FACE_LABEL[$dice[0]] ?? $dice[0]);
        } elseif ($revealed && $tronNum > 0 && $tronId === '') {
            $hint = '已锁定波场高度 #' . $tronNum . '，等待出块后开奖。';
        } elseif ($revealed && $legacy) {
            $hint = '复算：' . $seed . ' 去掉前导 00 后取前 3 字节 → ' . implode(' / ', $labels);
        }

        $statusLabel = '尚未开奖';
        if ($revealed && $hasDice) {
            $statusLabel = '已开奖';
        } elseif ($revealed && $tronNum > 0) {
            $statusLabel = '等待波场出块';
        }

        return [
            'kind'            => 'yxx',
            'type_label'      => '鱼虾蟹大厅',
            'round_index'     => $roundIndex,
            'revealed'        => ($revealed && $hasDice) ? 1 : 0,
            'status_label'    => $statusLabel,
            'settle_face'     => $hasDice ? $dice[0] : '',
            'settle_label'    => $hasDice ? (self::FACE_LABEL[$dice[0]] ?? $dice[0]) : '',
            'dice'            => $hasDice ? $dice : ['', '', ''],
            'dice_labels'     => $hasDice ? $labels : ['', '', ''],
            'hash_seed'       => $hasDice ? $seed : '',
            'hash_formula'    => $formula,
            'hash_rule'       => 'Block Hash 去掉前导 00 后前 3 字节各自 mod 6 → 三骰；第一颗为结算门',
            'verify_ok'       => $ok ? 1 : 0,
            'face_match'      => $faceMatch ? 1 : 0,
            'seed_match'      => $seedMatch ? 1 : 0,
            'human_stake'     => $archived ? (int)$archived['human_stake'] : 0,
            'pool_inject'     => $archived ? (int)$archived['pool_inject'] : 0,
            'boom_release'    => $archived ? (int)$archived['boom_release'] : 0,
            'tron_status'     => $tronStatus,
            'tron_block_num'  => $tronNum,
            'targetBlockNum'  => $tronNum,
            'tron_block_id'   => $tronId,
            'block_id'        => $tronId,
            'tronscan_url'    => $tronNum > 0 ? ('https://tronscan.org/#/block/' . $tronNum) : '',
            'verify_hint'     => $hint,
        ];
    }

    private static function botPlan($roundIndex, array $cfg, $useReal = false)
    {
        $min = (int)$cfg['bot_count_min'];
        $max = (int)$cfg['bot_count_max'];
        if ($max <= 0) {
            return [];
        }
        $seed = hexdec(substr(hash('sha256', 'yxx-bots|' . (int)$roundIndex), 0, 8));
        $n = $min + ($seed % max(1, $max - $min + 1));
        $stakeMin = max(1, (int)($cfg['bot_stake_min'] ?? $cfg['stake_min'] ?? 50));
        $stakeMax = max($stakeMin, (int)($cfg['bot_stake_max'] ?? $cfg['stake_max'] ?? 200));
        // 在区间内按 50 步进取可选注额；区间过窄则用两端
        $step = 50;
        $stakes = [];
        for ($s = $stakeMin; $s <= $stakeMax; $s += $step) {
            $stakes[] = $s;
        }
        if (!$stakes) {
            $stakes = [$stakeMin];
        }
        if ($stakes[count($stakes) - 1] !== $stakeMax) {
            $stakes[] = $stakeMax;
        }
        $nicks = self::BOT_NICKS;
        $actors = [];
        if ($useReal) {
            $actors = self::listRobotActors($n * 3);
            if (!$actors) {
                return [];
            }
            // 打乱但可复现：按本期 seed 排序再截断
            usort($actors, function ($a, $b) use ($seed) {
                $ha = hexdec(substr(hash('sha256', $seed . '|' . $a['uid']), 0, 8));
                $hb = hexdec(substr(hash('sha256', $seed . '|' . $b['uid']), 0, 8));
                return $ha <=> $hb;
            });
            $n = min($n, count($actors));
        }
        $plan = [];
        for ($i = 0; $i < $n; $i++) {
            $stake = $stakes[($seed + $i * 11) % count($stakes)];
            if ($useReal) {
                $actor = $actors[$i];
                $bal = (float)($actor['hongbao'] ?? 0);
                if ($bal + 1e-6 < $stake) {
                    // 余额不够：降到区间内最大可下，仍不够则跳过
                    $afford = 0;
                    foreach ($stakes as $cand) {
                        if ($cand <= $bal && $cand > $afford) {
                            $afford = $cand;
                        }
                    }
                    if ($afford <= 0) {
                        continue;
                    }
                    $stake = $afford;
                }
                $plan[] = [
                    'uid'   => (int)$actor['uid'],
                    'nick'  => (string)$actor['nick'],
                    'face'  => self::FACE_IDS[($seed + $i * 3) % 6],
                    'stake' => (int)$stake,
                    'at'    => 1 + (($seed + $i * 13) % 10),
                ];
                continue;
            }
            $plan[] = [
                'uid'   => -20000 - $i,
                'nick'  => $nicks[($seed + $i * 7) % count($nicks)],
                'face'  => self::FACE_IDS[($seed + $i * 3) % 6],
                'stake' => (int)$stake,
                'at'    => 1 + (($seed + $i * 13) % 10),
            ];
        }
        return $plan;
    }

    /**
     * 真金机器人：fans_account.is_bot=1 且状态正常
     *
     * @return array{uid:int,nick:string,hongbao:float}[]
     */
    private static function listRobotActors($limit = 80)
    {
        $limit = max(1, min(200, (int)$limit));
        try {
            $rows = Db::name('fans_account')
                ->alias('a')
                ->join('user u', 'u.id = a.user_id', 'LEFT')
                ->where('a.is_bot', 1)
                ->where('a.status', 'normal')
                ->field('a.user_id AS uid, a.hongbao, u.nickname')
                ->order('a.id', 'asc')
                ->limit($limit)
                ->select();
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows ?: [] as $row) {
            $uid = (int)($row['uid'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            $nick = trim((string)($row['nickname'] ?? ''));
            if ($nick === '') {
                $nick = '机器人' . $uid;
            }
            $out[] = [
                'uid'     => $uid,
                'nick'    => mb_substr($nick, 0, 16),
                'hongbao' => (float)($row['hongbao'] ?? 0),
            ];
        }
        return $out;
    }
}
