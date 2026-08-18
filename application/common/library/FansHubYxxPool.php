<?php

namespace app\common\library;

use think\Cache;
use think\Db;

/**
 * 鱼虾蟹大奖池：底座保护、双重上限、红包雨触发与确定性派发。
 */
class FansHubYxxPool
{
    const CACHE_GROSS = 'fh:yxx:boom_pool';
    const CACHE_STATUS = 'fh:yxx:pool_status';

    const STATUS_NORMAL = 'normal';
    const STATUS_DEGRADED = 'degraded';
    const STATUS_PAUSED = 'paused';
    const STATUS_LOCKED = 'locked';

    public static function statusLabels()
    {
        return [
            self::STATUS_NORMAL   => '正常运行',
            self::STATUS_DEGRADED => '降级（停红包雨，普通结算继续）',
            self::STATUS_PAUSED   => '暂停派奖（停红包雨/爆点释放，仍可下注）',
            self::STATUS_LOCKED   => '全局锁死（禁止下注）',
        ];
    }

    public static function poolStatus()
    {
        $cached = Cache::get(self::CACHE_STATUS);
        if (is_string($cached) && $cached !== '') {
            return self::normalizeStatus($cached);
        }
        try {
            $st = (string)Db::name('fans_yxx_pool_state')->where('id', 1)->value('status');
            $st = self::normalizeStatus($st);
            Cache::set(self::CACHE_STATUS, $st, 86400);
            return $st;
        } catch (\Throwable $e) {
            return self::STATUS_NORMAL;
        }
    }

    public static function canBet()
    {
        return self::poolStatus() !== self::STATUS_LOCKED;
    }

    public static function canBoomRelease()
    {
        return in_array(self::poolStatus(), [self::STATUS_NORMAL, self::STATUS_DEGRADED], true);
    }

    public static function canRain()
    {
        return self::poolStatus() === self::STATUS_NORMAL;
    }

    public static function setStatus($status)
    {
        $status = self::normalizeStatus($status);
        Cache::set(self::CACHE_STATUS, $status, 86400);
        Cache::rm('fh:yxx:poolsnap');
        try {
            $exists = Db::name('fans_yxx_pool_state')->where('id', 1)->value('id');
            if ($exists) {
                Db::name('fans_yxx_pool_state')->where('id', 1)->update([
                    'status'     => $status,
                    'updatetime' => time(),
                ]);
            } else {
                Db::name('fans_yxx_pool_state')->insert([
                    'id'         => 1,
                    'gross_pool' => self::grossPool(),
                    'status'     => $status,
                    'updatetime' => time(),
                ]);
            }
        } catch (\Throwable $e) {
        }
        return $status;
    }

    public static function dashboard()
    {
        $gross = self::grossPool();
        $split = self::splitPool($gross);
        $cfg = self::configMap();
        $state = [];
        try {
            $state = Db::name('fans_yxx_pool_state')->where('id', 1)->find() ?: [];
        } catch (\Throwable $e) {
            $state = [];
        }
        $today = date('Ymd');
        $dayCount = ((string)($state['rain_day'] ?? '') === $today) ? (int)($state['rain_day_count'] ?? 0) : 0;
        return $split + [
            'status'          => self::poolStatus(),
            'status_label'    => self::statusLabels()[self::poolStatus()] ?? self::poolStatus(),
            'cycle_count'     => max(0, (int)Cache::get('fh:yxx:cycle_count')),
            'last_rain_at'    => (int)($state['last_rain_at'] ?? 0),
            'rain_day_count'  => $dayCount,
            'rain_trigger'    => (int)$cfg['rain_trigger'],
            'rain_daily_max'  => (int)$cfg['rain_daily_max'],
            'rain_cooldown'   => (int)$cfg['rain_cooldown_sec'],
            'user_cap_hint'   => self::singleUserCap($split['distributable']),
        ];
    }

    protected static function normalizeStatus($status)
    {
        $status = strtolower(trim((string)$status));
        if (!isset(self::statusLabels()[$status])) {
            return self::STATUS_NORMAL;
        }
        return $status;
    }

    public static function configMap()
    {
        $cfg = FansHubService::config();
        return [
            'enabled'            => array_key_exists('yxx_pool_enabled', $cfg) ? !empty($cfg['yxx_pool_enabled']) : true,
            'reserve_rate'       => self::clampFloat($cfg['yxx_pool_reserve_rate'] ?? 0.20, 0, 0.5),
            'rain_trigger'         => max(1000, (int)($cfg['yxx_rain_trigger'] ?? 200000)),
            'rain_release_rate'    => self::clampFloat($cfg['yxx_rain_release_rate'] ?? 0.10, 0.01, 0.5),
            'user_rate_cap'        => self::clampFloat($cfg['yxx_user_rate_cap'] ?? 0.05, 0.01, 0.5),
            'user_abs_cap'         => max(1, (int)($cfg['yxx_user_abs_cap'] ?? 10000)),
            'rain_cooldown_sec'    => max(60, (int)($cfg['yxx_rain_cooldown_sec'] ?? 3600)),
            'rain_daily_max'       => max(1, (int)($cfg['yxx_rain_daily_max'] ?? 3)),
            'rain_min_bet'         => max(1, (int)($cfg['yxx_rain_min_bet'] ?? 50)),
        ];
    }

    public static function splitPool($grossPool)
    {
        $grossPool = max(0, (int)$grossPool);
        $cfg = self::configMap();
        $reserve = (int)floor($grossPool * $cfg['reserve_rate']);
        return [
            'gross'         => $grossPool,
            'base_reserve'  => $reserve,
            'distributable' => max(0, $grossPool - $reserve),
        ];
    }

    public static function singleUserCap($poolAmount)
    {
        $poolAmount = max(0, (int)$poolAmount);
        $cfg = self::configMap();
        $byRate = (int)floor($poolAmount * $cfg['user_rate_cap']);
        return min($byRate, $cfg['user_abs_cap']);
    }

    /**
     * 按比例分摊并执行单人上限（取较小值原则）。
     *
     * @param int   $totalAmount 待分总额
     * @param int[] $weights     index => weight(stake)
     * @return int[] index => amount
     */
    public static function capProportionalShares($totalAmount, array $weights)
    {
        $totalAmount = max(0, (int)$totalAmount);
        if ($totalAmount <= 0 || !$weights) {
            return [];
        }
        $cap = self::singleUserCap($totalAmount);
        $weightSum = 0;
        foreach ($weights as $w) {
            $weightSum += max(0, (int)$w);
        }
        if ($weightSum <= 0) {
            return [];
        }

        $raw = [];
        $floors = [];
        $remainders = [];
        $sumFloor = 0;
        foreach ($weights as $idx => $w) {
            $w = max(0, (int)$w);
            if ($w <= 0) {
                continue;
            }
            $raw[$idx] = $totalAmount * ($w / $weightSum);
            $floor = (int)floor($raw[$idx]);
            $floor = min($floor, $cap);
            $floors[$idx] = $floor;
            $remainders[$idx] = $raw[$idx] - floor($raw[$idx]);
            $sumFloor += $floor;
        }

        $left = max(0, $totalAmount - $sumFloor);
        if ($left > 0) {
            arsort($remainders);
            foreach ($remainders as $idx => $r) {
                if ($left <= 0) {
                    break;
                }
                if (($floors[$idx] ?? 0) >= $cap) {
                    continue;
                }
                $floors[$idx] = ((int)($floors[$idx] ?? 0)) + 1;
                $left--;
            }
        }

        return $floors;
    }

    public static function grossPool()
    {
        $cached = Cache::get(self::CACHE_GROSS);
        if ($cached !== false && $cached !== null) {
            return max(0, (int)$cached);
        }
        try {
            $row = Db::name('fans_yxx_pool_state')->where('id', 1)->find();
            $gross = max(0, (int)($row['gross_pool'] ?? 0));
            Cache::set(self::CACHE_GROSS, $gross, 86400 * 30);
            return $gross;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function setGrossPool($amount)
    {
        $amount = max(0, (int)$amount);
        Cache::set(self::CACHE_GROSS, $amount, 86400 * 30);
        Cache::rm('fh:yxx:poolsnap');
        try {
            Db::name('fans_yxx_pool_state')->where('id', 1)->update([
                'gross_pool' => $amount,
                'updatetime' => time(),
            ]);
        } catch (\Throwable $e) {
        }
        return $amount;
    }

    public static function touchDailyBet($uid, $stake)
    {
        self::adjustDailyBet($uid, max(0, (int)$stake), true);
    }

    public static function adjustDailyBet($uid, $delta, $countInc = false)
    {
        $uid = (int)$uid;
        $delta = (int)$delta;
        if ($uid <= 0 || $delta === 0) {
            return;
        }
        $date = date('Ymd');
        $now = time();
        try {
            $row = Db::name('fans_yxx_daily_bet')
                ->where(['user_id' => $uid, 'bet_date' => $date])
                ->find();
            if ($row) {
                $nextTotal = max(0, (int)$row['bet_total'] + $delta);
                $nextCount = (int)$row['bet_count'] + ($countInc && $delta > 0 ? 1 : 0);
                Db::name('fans_yxx_daily_bet')->where('id', (int)$row['id'])->update([
                    'bet_count'  => $nextCount,
                    'bet_total'  => $nextTotal,
                    'updatetime' => $now,
                ]);
            } elseif ($delta > 0) {
                Db::name('fans_yxx_daily_bet')->insert([
                    'user_id'    => $uid,
                    'bet_date'   => $date,
                    'bet_count'  => 1,
                    'bet_total'  => $delta,
                    'updatetime' => $now,
                ]);
            }
        } catch (\Throwable $e) {
            $key = 'fh:yxx:daily_bet:' . $date . ':' . $uid;
            $prev = Cache::get($key);
            $total = is_array($prev) ? (int)($prev['total'] ?? 0) : 0;
            $count = is_array($prev) ? (int)($prev['count'] ?? 0) : 0;
            $total = max(0, $total + $delta);
            if ($countInc && $delta > 0) {
                $count++;
            }
            Cache::set($key, ['total' => $total, 'count' => $count], 86400);
        }
    }

    public static function eligibleRainUsers()
    {
        $cfg = self::configMap();
        $date = date('Ymd');
        $min = (int)$cfg['rain_min_bet'];
        try {
            $rows = Db::name('fans_yxx_daily_bet')
                ->where('bet_date', $date)
                ->where('bet_total', '>=', $min)
                ->field('user_id,bet_total')
                ->select();
            $out = [];
            foreach ($rows as $row) {
                $uid = (int)($row['user_id'] ?? 0);
                if ($uid > 0) {
                    $out[$uid] = max(1, (int)($row['bet_total'] ?? 1));
                }
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function archiveRound($roundIndex, array $meta)
    {
        $roundIndex = (int)$roundIndex;
        if ($roundIndex < 0) {
            return;
        }
        try {
            $exists = Db::name('fans_yxx_rounds')->where('round_index', $roundIndex)->value('id');
            if ($exists) {
                return;
            }
            Db::name('fans_yxx_rounds')->insert([
                'round_index'      => $roundIndex,
                'settle_face'      => (string)($meta['settle_face'] ?? ''),
                'human_stake'      => (int)($meta['human_stake'] ?? 0),
                'pool_inject'      => (int)($meta['boom_add'] ?? 0),
                'boom_release'     => (int)($meta['boom_release'] ?? 0),
                'gross_pool_after' => (int)($meta['gross_pool_after'] ?? 0),
                'cycle_count'      => (int)($meta['cycle_after'] ?? 0),
                'hash_seed'        => (string)($meta['hash_seed'] ?? ''),
                'createtime'       => time(),
            ]);
        } catch (\Throwable $e) {
        }
    }

    /**
     * 局后：同步奖池、判定红包雨、归档。
     *
     * @return array|null rain summary for broadcast
     */
    public static function afterRoundSettled($roundIndex, $grossPoolAfter, array $meta = [])
    {
        $cfg = self::configMap();
        if (empty($cfg['enabled'])) {
            self::setGrossPool($grossPoolAfter);
            self::archiveRound($roundIndex, $meta + ['gross_pool_after' => $grossPoolAfter]);
            return null;
        }

        self::setGrossPool($grossPoolAfter);
        $meta['gross_pool_after'] = $grossPoolAfter;
        self::archiveRound($roundIndex, $meta);

        return self::maybeTriggerRain($roundIndex, $grossPoolAfter, (string)($meta['hash_seed'] ?? ''));
    }

    protected static function maybeTriggerRain($roundIndex, $grossPool, $hashSeed)
    {
        if (!self::canRain()) {
            return null;
        }
        $cfg = self::configMap();
        $split = self::splitPool($grossPool);
        if ($split['distributable'] < (int)$cfg['rain_trigger']) {
            return null;
        }

        $lockName = 'fh:yxx:rain_lock';
        if (!FansHubYxxStore::acquireLock($lockName, 25)) {
            return null;
        }

        try {
            $state = Db::name('fans_yxx_pool_state')->where('id', 1)->find();
            $now = time();
            $today = date('Ymd');
            $dayCount = 0;
            if ($state) {
                $dayCount = ((string)($state['rain_day'] ?? '') === $today) ? (int)($state['rain_day_count'] ?? 0) : 0;
                $lastAt = (int)($state['last_rain_at'] ?? 0);
                if ($now - $lastAt < (int)$cfg['rain_cooldown_sec']) {
                    return null;
                }
                if ($dayCount >= (int)$cfg['rain_daily_max']) {
                    return null;
                }
            }

            $eligible = self::eligibleRainUsers();
            if (count($eligible) < 1) {
                return null;
            }

            $releaseAmount = (int)floor($split['distributable'] * $cfg['rain_release_rate']);
            $releaseAmount = max(0, min($releaseAmount, $split['distributable']));
            if ($releaseAmount <= 0) {
                return null;
            }

            $seed = $hashSeed !== '' ? $hashSeed : hash('sha256', 'yxx-rain|' . (int)$roundIndex . '|' . $grossPool . '|' . $now);
            $shares = self::capProportionalShares($releaseAmount, $eligible);
            if (!$shares) {
                return null;
            }

            $grantRows = [];
            $paidSum = 0;
            $participant = 0;
            $maxGrant = 0;
            foreach ($shares as $uid => $amount) {
                $uid = (int)$uid;
                $amount = (int)$amount;
                if ($uid <= 0 || $amount <= 0) {
                    continue;
                }
                $grantRows[] = [
                    'user_id'    => $uid,
                    'amount'     => $amount,
                    'weight'     => (int)($eligible[$uid] ?? 1),
                    'popup_seen' => 0,
                    'paid'       => 0,
                    'createtime' => $now,
                ];
                $paidSum += $amount;
                $participant++;
                $maxGrant = max($maxGrant, $amount);
            }
            if (!$grantRows) {
                return null;
            }

            Db::startTrans();
            try {
                $eventId = Db::name('fans_yxx_rain_events')->insertGetId([
                    'round_index'       => (int)$roundIndex,
                    'release_amount'    => $paidSum,
                    'participant_count' => $participant,
                    'hash_seed'         => substr($seed, 0, 128),
                    'gross_pool_before' => (int)$grossPool,
                    'gross_pool_after'  => max(0, (int)$grossPool - $paidSum),
                    'status'            => 1,
                    'createtime'        => $now,
                ]);

                foreach ($grantRows as &$row) {
                    $row['event_id'] = $eventId;
                }
                unset($row);

                $chunks = array_chunk($grantRows, 400);
                $omitPaid = false;
                foreach ($chunks as $chunk) {
                    $toInsert = $chunk;
                    if ($omitPaid) {
                        foreach ($toInsert as &$row) {
                            unset($row['paid']);
                        }
                        unset($row);
                    }
                    try {
                        Db::name('fans_yxx_rain_grants')->insertAll($toInsert);
                    } catch (\Throwable $e) {
                        if (!$omitPaid) {
                            $omitPaid = true;
                            foreach ($toInsert as &$row) {
                                unset($row['paid']);
                            }
                            unset($row);
                            Db::name('fans_yxx_rain_grants')->insertAll($toInsert);
                        } else {
                            throw $e;
                        }
                    }
                }

                $newGross = max(0, (int)$grossPool - $paidSum);
                Db::name('fans_yxx_pool_state')->where('id', 1)->update([
                    'gross_pool'       => $newGross,
                    'last_rain_at'     => $now,
                    'rain_day'         => $today,
                    'rain_day_count'   => $dayCount + 1,
                    'updatetime'       => $now,
                ]);
                Db::commit();
                self::setGrossPool($newGross);

                $fanout = [];
                foreach ($grantRows as $row) {
                    $uid = (int)$row['user_id'];
                    $amount = (int)$row['amount'];
                    $pop = [
                        'grant_id'     => 0,
                        'event_id'     => (int)$eventId,
                        'amount'       => $amount,
                        'release'      => $paidSum,
                        'participants' => $participant,
                        'hash_seed'    => substr($seed, 0, 32),
                        'round_index'  => (int)$roundIndex,
                    ];
                    $fanout[] = [
                        'uid' => $uid,
                        'pop' => $pop,
                        'pay' => [
                            'event_id'    => (int)$eventId,
                            'amount'      => $amount,
                            'round_index' => (int)$roundIndex,
                        ],
                    ];
                }
                FansHubYxxStore::fanoutRain($fanout);

                return [
                    'event_id'     => (int)$eventId,
                    'release'      => $paidSum,
                    'participants' => $participant,
                    'max_grant'    => $maxGrant,
                    'hash_seed'    => substr($seed, 0, 32),
                    'round_index'  => (int)$roundIndex,
                ];
            } catch (\Throwable $e) {
                Db::rollback();
                return null;
            }
        } finally {
            FansHubYxxStore::releaseLock($lockName);
        }
    }

    /**
     * 首次进入大厅时入账，避免触发时 1 万次 wallet 循环。
     */
    protected static function lazyCreditRain($uid)
    {
        $uid = (int)$uid;
        if ($uid <= 0) {
            return;
        }
        $pay = FansHubYxxStore::getJson('fh:yxx:rainpay:' . $uid);
        if (!is_array($pay)) {
            return;
        }
        $eventId = (int)($pay['event_id'] ?? 0);
        $amount = (int)($pay['amount'] ?? 0);
        $roundIndex = (int)($pay['round_index'] ?? 0);
        if ($eventId <= 0 || $amount <= 0) {
            FansHubYxxStore::delName('fh:yxx:rainpay:' . $uid);
            return;
        }
        $lockName = 'fh:yxx:rainpaylock:' . $uid . ':' . $eventId;
        if (!FansHubYxxStore::acquireLock($lockName, 20)) {
            return;
        }
        try {
            $paid = null;
            try {
                $paid = Db::name('fans_yxx_rain_grants')
                    ->where(['event_id' => $eventId, 'user_id' => $uid])
                    ->value('paid');
            } catch (\Throwable $e) {
                $paid = null;
            }
            if ((int)$paid === 1) {
                FansHubYxxStore::delName('fh:yxx:rainpay:' . $uid);
                return;
            }
            FansHubHongbaoLedger::credit($uid, $amount, 'yxx_rain', '鱼虾蟹红包雨 R' . $roundIndex, [
                'biz_no'   => 'YXXRAIN' . $eventId . '-' . $uid,
                'ref_type' => 'yxx_rain',
                'ref_id'   => $eventId,
            ]);
            try {
                Db::name('fans_yxx_rain_grants')
                    ->where(['event_id' => $eventId, 'user_id' => $uid])
                    ->update(['paid' => 1]);
            } catch (\Throwable $e) {
            }
            FansHubYxxStore::delName('fh:yxx:rainpay:' . $uid);
        } catch (\Throwable $e) {
        } finally {
            FansHubYxxStore::releaseLock($lockName);
        }
    }

    public static function pendingPopup($uid)
    {
        $uid = (int)$uid;
        if ($uid <= 0) {
            return null;
        }
        self::lazyCreditRain($uid);
        $cached = FansHubYxxStore::getJson('fh:yxx:rainpop:' . $uid);
        if (is_array($cached) && (int)($cached['amount'] ?? 0) > 0) {
            return $cached;
        }
        try {
            $row = Db::name('fans_yxx_rain_grants')
                ->alias('g')
                ->join('fans_yxx_rain_events e', 'e.id = g.event_id')
                ->where('g.user_id', $uid)
                ->where('g.popup_seen', 0)
                ->order('g.id desc')
                ->field('g.id,g.amount,g.event_id,e.release_amount,e.participant_count,e.hash_seed,e.round_index')
                ->find();
            if (!$row) {
                return null;
            }
            return [
                'grant_id'     => (int)$row['id'],
                'event_id'     => (int)$row['event_id'],
                'amount'       => (int)$row['amount'],
                'release'      => (int)$row['release_amount'],
                'participants' => (int)$row['participant_count'],
                'hash_seed'    => (string)$row['hash_seed'],
                'round_index'  => (int)$row['round_index'],
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function ackPopup($uid, $grantId = 0)
    {
        $uid = (int)$uid;
        if ($uid <= 0) {
            return false;
        }
        FansHubYxxStore::delName('fh:yxx:rainpop:' . $uid);
        try {
            $q = Db::name('fans_yxx_rain_grants')->where('user_id', $uid)->where('popup_seen', 0);
            if ($grantId > 0) {
                $q->where('id', (int)$grantId);
            }
            $q->update(['popup_seen' => 1]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function hallPoolPayload()
    {
        $hit = Cache::get('fh:yxx:poolsnap');
        if (is_array($hit)) {
            return $hit;
        }
        $cfg = self::configMap();
        $gross = self::grossPool();
        $split = self::splitPool($gross);
        $status = self::poolStatus();
        $out = [
            'pool_enabled'    => !empty($cfg['enabled']) ? 1 : 0,
            'gross_pool'      => $split['gross'],
            'base_reserve'    => $split['base_reserve'],
            'distributable'   => $split['distributable'],
            'rain_trigger'    => (int)$cfg['rain_trigger'],
            'rain_progress'   => min(100, (int)floor($split['distributable'] / max(1, (int)$cfg['rain_trigger']) * 100)),
            'user_cap_hint'   => self::singleUserCap($split['distributable']),
            'pool_status'     => $status,
        ];
        Cache::set('fh:yxx:poolsnap', $out, 1);
        return $out;
    }

    protected static function clampFloat($v, $min, $max)
    {
        $v = (float)$v;
        if ($v < $min) {
            return $min;
        }
        if ($v > $max) {
            return $max;
        }
        return $v;
    }
}
