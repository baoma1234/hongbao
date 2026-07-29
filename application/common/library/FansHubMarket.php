<?php

namespace app\common\library;

use app\admin\model\fanshub\Account;
use app\admin\model\fanshub\Ledger;
use app\admin\model\fanshub\Secret;
use think\Db;

/**
 * 福利大厅控盘：虚拟人数 / 累计彩金 / 万能保底公式股价
 *
 * 闭环约定：
 * - 初始资金 888,888 ÷ 股价 5 = 总股 177,777.6（种子盘，不发给虚拟人）
 * - 初期虚拟人数 ~8,000 ⇒ 人均约 22.2 股
 * - 虚拟人只是展示数字，绝不落库发股
 * - 万能公式：
 *   最新股价 = (888888 + Σ任务虚拟注资) / (177777.6 + Σ活动已送出股份)
 *   送出 N 股时自动注资 N × 5 × 1.3，保证永不跌破 5 元
 * - 股价钳制在 [5, 7]，棘轮只涨不跌
 * - 大盘展示为「累计创造价值」，只升不降
 */
class FansHubMarket
{
    const CACHE_SMOOTH = 'fanshub_market_smooth';
    const CACHE_SMOOTH_MINUTE = 'fanshub_market_smooth_minute';
    const CACHE_DAILY_DATE = 'fanshub_market_daily_ymd';
    const CACHE_TODAY_ADD = 'fanshub_market_today_add';
    const CACHE_YDAY_COUNT = 'fanshub_market_yday_count';
    const CACHE_YDAY_PRICE = 'fanshub_market_yday_price';
    const CACHE_WITHDRAW_N = 'fanshub_market_withdraw_n';
    const CACHE_PRICE_FLOOR = 'fanshub_market_price_floor_v2';
    const CACHE_CUMULATIVE = 'fanshub_market_cumulative';
    const CACHE_CUMULATIVE_DATE = 'fanshub_market_cumulative_ymd';
    const CACHE_CUMULATIVE_TICK_AT = 'fanshub_market_cumulative_tick_at';
    const CACHE_ISSUED_SHARES = 'fanshub_market_issued_shares';
    const CACHE_N_USER_PREFIX = 'fanshub_market_n_user_';
    const CACHE_REAL_COUNT = 'fanshub_market_real_count';

    public static function cfg($key, $default = null)
    {
        return FansHubService::config($key, $default);
    }

    /** 初期虚拟合伙人基数（建议 5000~10000，默认 8000） */
    public static function virtualBase()
    {
        return max(0, (int)self::cfg('market_virtual_base', 8000));
    }

    /** 每 1 个真人注册，展示人数再加几个虚拟人 */
    public static function virtualPerReal()
    {
        return max(0, (int)self::cfg('market_virtual_per_real', 5));
    }

    /** 每日虚拟增量下限（无真人注册也会加） */
    public static function dailyGrowMin()
    {
        return max(0, (int)self::cfg('market_daily_grow_min', 90));
    }

    /** 每日虚拟增量上限 */
    public static function dailyGrowMax()
    {
        $min = self::dailyGrowMin();
        return max($min, (int)self::cfg('market_daily_grow_max', 130));
    }

    /** 种子总股数 */
    public static function seedTotalShares()
    {
        return (float)self::cfg('market_total_shares_seed', 177777.6);
    }

    /**
     * 种子资金池（股价万能公式分子）
     * 与大屏「累计创造价值」展示基数拆开，避免改展示数字时搞乱行权价
     */
    public static function seedCapital()
    {
        $seed = (float)self::cfg('market_seed_capital', 0);
        if ($seed <= 0) {
            $seed = 888888;
        }
        return $seed;
    }

    /** 大屏「累计创造价值」展示基数（默认 100 万） */
    public static function cumulativeBase()
    {
        return max(0, (float)self::cfg('jackpot_base', 1000000));
    }

    /** 大屏创造价值软顶（只升不降，到顶后日增趋近 0） */
    public static function cumulativeCeiling()
    {
        $base = self::cumulativeBase();
        $ceil = (float)self::cfg('jackpot_ceiling', 100000000);
        return max($base, $ceil);
    }

    /** 每日创造价值增量下限 */
    public static function cumulativeDailyGrowMin()
    {
        return max(0, (float)self::cfg('jackpot_grow_min', 1000));
    }

    /** 每日创造价值增量上限 */
    public static function cumulativeDailyGrowMax()
    {
        $min = self::cumulativeDailyGrowMin();
        return max($min, (float)self::cfg('jackpot_grow_max', 20000));
    }

    /** 注资倍率：送 N 股 → 注资 N × 单价基数 × factor */
    public static function injectFactor()
    {
        return max(1.0, (float)self::cfg('market_inject_factor', 1.3));
    }

    public static function priceMin()
    {
        return (float)self::cfg('market_share_price_min', self::cfg('market_share_price_base', 5));
    }

    public static function priceMax()
    {
        return (float)self::cfg('market_share_price_max', self::cfg('share_price_max', 7));
    }

    /** 真实粉丝厅账户数（仅真人）；短缓存 + 注册时主动失效 */
    public static function realAccountCount()
    {
        $cached = \think\Cache::get(self::CACHE_REAL_COUNT);
        if ($cached !== false && $cached !== null) {
            return max(0, (int)$cached);
        }
        try {
            $n = (int)Account::count();
        } catch (\Throwable $e) {
            $n = 0;
        }
        \think\Cache::set(self::CACHE_REAL_COUNT, $n, 2);
        return $n;
    }

    /** 新真人注册后立刻刷新大盘人数缓存 */
    public static function onRealUserJoined()
    {
        \think\Cache::rm(self::CACHE_REAL_COUNT);
        try {
            $n = (int)Account::count();
            \think\Cache::set(self::CACHE_REAL_COUNT, $n, 2);
        } catch (\Throwable $e) {
        }
    }

    /**
     * 每日跨天结算：人数 +90~130（可配置），股价在 [5,7] 内缓升；可追补漏天
     */
    public static function tickDailyGrowth()
    {
        $today = date('Ymd');
        $last = (string)\think\Cache::get(self::CACHE_DAILY_DATE);
        if ($last === $today) {
            return self::smoothCount();
        }

        $partnersBefore = self::partnerCountRaw();
        $priceBefore = self::getSharePrice(false);
        \think\Cache::set(self::CACHE_YDAY_COUNT, $partnersBefore, 86400 * 3650);
        \think\Cache::set(self::CACHE_YDAY_PRICE, $priceBefore, 86400 * 3650);

        $daysMissed = 1;
        if ($last !== '' && preg_match('/^\d{8}$/', $last)) {
            $start = strtotime($last . ' 00:00:00');
            $end = strtotime($today . ' 00:00:00');
            if ($start !== false && $end !== false && $end > $start) {
                $daysMissed = max(1, (int)round(($end - $start) / 86400));
                $daysMissed = min(60, $daysMissed);
            }
        }

        $gMin = self::dailyGrowMin();
        $gMax = self::dailyGrowMax();
        $addTotal = 0;
        $todayAdd = 0;
        for ($i = 0; $i < $daysMissed; $i++) {
            $delta = $gMin >= $gMax ? $gMin : mt_rand($gMin, $gMax);
            $addTotal += $delta;
            $todayAdd = $delta;
        }

        $smooth = self::smoothCount() + max(0, $addTotal);
        \think\Cache::set(self::CACHE_SMOOTH, $smooth, 86400 * 3650);
        \think\Cache::set(self::CACHE_DAILY_DATE, $today, 86400 * 3650);
        \think\Cache::set(self::CACHE_TODAY_ADD, $todayAdd, 86400 * 3);

        self::bumpPriceFloorDaily($daysMissed);

        return $smooth;
    }

    /**
     * 无真人活动时也让股价缓慢抬升，钳制在 [min, max]
     */
    protected static function bumpPriceFloorDaily($days = 1)
    {
        $days = max(1, (int)$days);
        $min = self::priceMin();
        $max = self::priceMax();
        if ($max < $min) {
            $max = $min;
        }
        $price = self::getSharePrice(false);
        for ($i = 0; $i < $days; $i++) {
            $headroom = $max - $price;
            if ($headroom <= 0.001) {
                $price = $max;
                break;
            }
            // 每天约 +0.02~0.08，越接近上限越小
            $lift = min($headroom, mt_rand(2, 8) / 100);
            $price = round($price + $lift, 2);
        }
        $price = max($min, min($max, $price));
        \think\Cache::set(self::CACHE_PRICE_FLOOR, $price, 86400 * 3650);
        return $price;
    }

    /**
     * 分钟级平滑（可选，默认关闭；主要靠每日 90~130）
     */
    public static function tickVirtualSmooth()
    {
        self::tickDailyGrowth();

        $dayMin = max(0, (int)self::cfg('market_smooth_day_min', 0));
        $dayMax = max($dayMin, (int)self::cfg('market_smooth_day_max', 0));
        $nightMin = max(0, (int)self::cfg('market_smooth_night_min', 0));
        $nightMax = max($nightMin, (int)self::cfg('market_smooth_night_max', 0));
        if ($dayMax <= 0 && $nightMax <= 0) {
            return self::smoothCount();
        }

        $minuteKey = date('YmdHi');
        $last = (string)\think\Cache::get(self::CACHE_SMOOTH_MINUTE);
        if ($last === $minuteKey) {
            return self::smoothCount();
        }

        $now = time();
        $startTs = $last && preg_match('/^\d{12}$/', $last)
            ? strtotime(substr($last, 0, 8) . ' ' . substr($last, 8, 2) . ':' . substr($last, 10, 2) . ':00')
            : ($now - 60);
        if ($startTs === false) {
            $startTs = $now - 60;
        }

        $add = 0;
        $steps = 0;
        for ($ts = $startTs + 60; $ts <= $now && $steps < 60; $ts += 60, $steps++) {
            $add += self::smoothDeltaForHour((int)date('G', $ts));
        }
        if ($add <= 0 && $last === '') {
            $add = self::smoothDeltaForHour((int)date('G'));
        }

        $smooth = self::smoothCount() + max(0, $add);
        \think\Cache::set(self::CACHE_SMOOTH, $smooth, 86400 * 365);
        \think\Cache::set(self::CACHE_SMOOTH_MINUTE, $minuteKey, 86400 * 365);
        return $smooth;
    }

    protected static function smoothDeltaForHour($hour)
    {
        $dayStart = (int)self::cfg('market_day_start_hour', 8);
        $dayEnd = (int)self::cfg('market_day_end_hour', 23);
        $isDay = ($hour >= $dayStart && $hour < $dayEnd);
        if ($isDay) {
            $min = max(0, (int)self::cfg('market_smooth_day_min', 0));
            $max = max($min, (int)self::cfg('market_smooth_day_max', 0));
        } else {
            $min = max(0, (int)self::cfg('market_smooth_night_min', 0));
            $max = max($min, (int)self::cfg('market_smooth_night_max', 0));
        }
        if ($max <= 0) {
            return 0;
        }
        return mt_rand($min, $max);
    }

    public static function smoothCount()
    {
        $v = \think\Cache::get(self::CACHE_SMOOTH);
        if ($v === false || $v === null) {
            return 0;
        }
        return max(0, (int)$v);
    }

    /** 不含跨天 tick 的当前人数 */
    protected static function partnerCountRaw()
    {
        $base = self::virtualBase();
        $real = self::realAccountCount();
        $per = self::virtualPerReal();
        $smooth = self::smoothCount();
        return (int)max(0, $base + ($real * $per) + $smooth);
    }

    /**
     * 前端展示合伙人人数 = 基数 + 真人×倍数 + 每日虚拟增量（及可选分钟平滑）
     * 虚拟人绝不写库发股
     */
    public static function partnerCount($tickSmooth = false)
    {
        if ($tickSmooth) {
            self::tickVirtualSmooth();
        } else {
            // 读路径也做一次跨天结算，避免没有 cron 时人数停更
            self::tickDailyGrowth();
        }
        return self::partnerCountRaw();
    }

    public static function yesterdayPartnerCount()
    {
        $v = \think\Cache::get(self::CACHE_YDAY_COUNT);
        if ($v === false || $v === null) {
            return self::partnerCountRaw();
        }
        return max(0, (int)$v);
    }

    public static function yesterdaySharePrice()
    {
        $v = \think\Cache::get(self::CACHE_YDAY_PRICE);
        if ($v === false || $v === null) {
            return self::getSharePrice(false);
        }
        return max(self::priceMin(), (float)$v);
    }

    public static function todayPartnerUp()
    {
        $today = self::partnerCountRaw();
        $yday = self::yesterdayPartnerCount();
        $up = max(0, $today - $yday);
        if ($up > 0) {
            return $up;
        }
        $cached = \think\Cache::get(self::CACHE_TODAY_ADD);
        return max(0, (int)$cached);
    }

    public static function priceUpPercent()
    {
        $today = self::getSharePrice(false);
        $yday = self::yesterdaySharePrice();
        if ($yday <= 0) {
            return 0.0;
        }
        $pct = (($today - $yday) / $yday) * 100;
        return round(max(0, $pct), 1);
    }

    /**
     * 历史累计提现达标人数 N（VIP 密令用户去重，只加不减）
     */
    public static function withdrawAchieverCount()
    {
        $cached = \think\Cache::get(self::CACHE_WITHDRAW_N);
        if ($cached !== false && $cached !== null) {
            return max(0, (int)$cached);
        }
        try {
            $row = Db::query("SELECT COUNT(DISTINCT user_id) AS c FROM `" . (new Secret())->getTable() . "` WHERE `tier` = 'VIP'");
            $n = (int)($row[0]['c'] ?? 0);
        } catch (\Throwable $e) {
            $n = 0;
        }
        \think\Cache::set(self::CACHE_WITHDRAW_N, $n, 86400 * 30);
        return $n;
    }

    /** 用户首次达到 VIP 提现门槛并生成密令时 +1 */
    public static function bumpWithdrawAchiever($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return self::withdrawAchieverCount();
        }
        $flag = self::CACHE_N_USER_PREFIX . $userId;
        if (\think\Cache::get($flag)) {
            return self::withdrawAchieverCount();
        }
        \think\Cache::set($flag, 1, 86400 * 3650);
        $n = self::withdrawAchieverCount() + 1;
        \think\Cache::set(self::CACHE_WITHDRAW_N, $n, 86400 * 3650);
        return $n;
    }

    /**
     * 全网活动已送出总股数（仅统计 rights_change > 0；闪兑销毁不回退分母）
     */
    public static function totalSharesIssued($forceRebuild = false)
    {
        if (!$forceRebuild) {
            $cached = \think\Cache::get(self::CACHE_ISSUED_SHARES);
            if ($cached !== false && $cached !== null) {
                return max(0.0, (float)$cached);
            }
        }
        $sum = 0.0;
        try {
            $sum = (float)Ledger::where('rights_change', '>', 0)->sum('rights_change');
        } catch (\Throwable $e) {
            $sum = 0.0;
        }
        $sum = max(0.0, round($sum, 2));
        \think\Cache::set(self::CACHE_ISSUED_SHARES, $sum, 86400 * 3650);
        return $sum;
    }

    /**
     * 活动送股后刷新分母（流水入账后调用；由 ledger 重算，避免缓存重复累加）
     */
    public static function onSharesGranted($shares)
    {
        if ((float)$shares <= 0) {
            return self::getSharePrice(false);
        }
        \think\Cache::rm(self::CACHE_ISSUED_SHARES);
        return self::getSharePrice(false);
    }

    /**
     * Σ任务虚拟注资 = 已送出股份 × 单价基数 × 注资倍率
     */
    public static function totalVirtualInject()
    {
        $base = (float)self::cfg('market_share_price_base', 5);
        return round(self::totalSharesIssued() * $base * self::injectFactor(), 2);
    }

    /**
     * 万能保底公式：
     * price = (888888 + Σ注资) / (177777.6 + Σ已送出股)
     * 钳制 [5, 7]，两位小数，棘轮只涨不跌
     */
    public static function getSharePrice($tick = false)
    {
        unset($tick);
        $num = self::seedCapital() + self::totalVirtualInject();
        $den = self::seedTotalShares() + self::totalSharesIssued();
        if ($den <= 0) {
            $den = self::seedTotalShares();
        }
        $price = $num / $den;
        $min = self::priceMin();
        $max = self::priceMax();
        if ($max < $min) {
            $max = $min;
        }
        $price = round(max($min, min($max, $price)), 2);

        $floor = \think\Cache::get(self::CACHE_PRICE_FLOOR);
        if ($floor !== false && $floor !== null) {
            $floor = (float)$floor;
            if ($price < $floor) {
                $price = min($max, $floor);
            }
        }
        \think\Cache::set(self::CACHE_PRICE_FLOOR, $price, 86400 * 3650);
        return $price;
    }

    /**
     * 每日跨天抬升创造价值；已结算过则直接返回缓存，禁止再调 getCumulativePayout（会死循环）
     */
    public static function tickDailyCumulative()
    {
        $today = date('Ymd');
        $last = (string)\think\Cache::get(self::CACHE_CUMULATIVE_DATE);
        $base = self::cumulativeBase();
        $ceiling = self::cumulativeCeiling();
        $cached = \think\Cache::get(self::CACHE_CUMULATIVE);

        if ($last === $today) {
            if ($cached === false || $cached === null) {
                $cached = max($base, self::rebuildCumulativeFromLedger());
                \think\Cache::set(self::CACHE_CUMULATIVE, $cached, 86400 * 3650);
            }
            return (float)$cached;
        }

        if ($cached === false || $cached === null) {
            $cached = max($base, self::rebuildCumulativeFromLedger());
        }
        $cached = (float)$cached;
        // 历史异常缓存（远超软顶）回落到展示基数
        if ($cached > $ceiling * 1.2 && $base <= $ceiling) {
            $cached = $base;
        }
        // 配置上调基数后，缓存低于新基数时抬升，避免长期卡在旧低位
        if ($cached < $base) {
            $cached = $base;
        }

        $daysMissed = 1;
        if ($last !== '' && preg_match('/^\d{8}$/', $last)) {
            $start = strtotime($last . ' 00:00:00');
            $end = strtotime($today . ' 00:00:00');
            if ($start !== false && $end !== false && $end > $start) {
                $daysMissed = max(1, (int)round(($end - $start) / 86400));
                $daysMissed = min(60, $daysMissed);
            }
        }

        $gMin = self::cumulativeDailyGrowMin();
        $gMax = self::cumulativeDailyGrowMax();
        for ($i = 0; $i < $daysMissed; $i++) {
            if ($cached >= $ceiling) {
                $cached = $ceiling;
                break;
            }
            $room = $ceiling - $cached;
            $delta = $gMin >= $gMax
                ? $gMin
                : ($gMin + (mt_rand(0, 1000) / 1000) * ($gMax - $gMin));
            // 越接近软顶，日增越小，但仍 > 0
            if ($room < ($gMax * 3)) {
                $delta = max(1, min($delta, $room * 0.35 + 1));
            }
            $delta = min($delta, $room);
            $cached = round($cached + $delta, 2);
        }

        \think\Cache::set(self::CACHE_CUMULATIVE, $cached, 86400 * 3650);
        \think\Cache::set(self::CACHE_CUMULATIVE_DATE, $today, 86400 * 3650);
        return $cached;
    }

    /**
     * 累计创造价值（大盘数字）：日线抬升 + 可选微跳动；只升不降，软顶封控在配置区间
     * 微跳动按「全局时钟」每 2 秒最多加一次，多人同时轮询不会叠加快涨
     */
    public static function getCumulativePayout($tick = false)
    {
        $base = self::cumulativeBase();
        $ceiling = self::cumulativeCeiling();
        $cached = self::tickDailyCumulative();
        $cached = (float)$cached;
        if ($cached > $ceiling * 1.2 && $base <= $ceiling) {
            $cached = $base;
            \think\Cache::set(self::CACHE_CUMULATIVE, $cached, 86400 * 3650);
        }

        if ($tick && !empty(self::cfg('jackpot_auto_grow')) && $cached < $ceiling) {
            $interval = max(1, (int)self::cfg('jackpot_tick_seconds', 2));
            $now = time();
            $lastTick = (int)\think\Cache::get(self::CACHE_CUMULATIVE_TICK_AT);
            if ($lastTick <= 0 || ($now - $lastTick) >= $interval) {
                // 抢占本窗口，避免并发请求重复加钱
                \think\Cache::set(self::CACHE_CUMULATIVE_TICK_AT, $now, 86400);
                $microMin = (float)self::cfg('jackpot_micro_grow_min', 1);
                $microMax = (float)self::cfg('jackpot_micro_grow_max', 5);
                if ($microMax < $microMin) {
                    $microMax = $microMin;
                }
                $room = $ceiling - $cached;
                $growth = $microMin + (mt_rand(0, 1000) / 1000) * max(0, $microMax - $microMin);
                $growth = min($growth, max(0.01, $room));
                $cached = round($cached + $growth, 2);
                \think\Cache::set(self::CACHE_CUMULATIVE, $cached, 86400 * 3650);
            }
        }

        return round(max($base, min($ceiling, $cached)), 2);
    }

    protected static function rebuildCumulativeFromLedger()
    {
        $base = self::cumulativeBase();
        $ceiling = self::cumulativeCeiling();
        $extra = 0.0;
        try {
            $ex = Ledger::where('type', 'exchange')->sum('balance_change');
            $extra += max(0, (float)$ex);
        } catch (\Throwable $e) {
        }
        try {
            $table = (new Secret())->getTable();
            $row = Db::query("SELECT COALESCE(SUM(t.amount),0) AS s FROM (
                SELECT user_id, MAX(amount) AS amount FROM `{$table}` WHERE tier='VIP' GROUP BY user_id
            ) t");
            $extra += max(0, (float)($row[0]['s'] ?? 0));
        } catch (\Throwable $e) {
        }
        return round(min($ceiling, $base + $extra), 2);
    }

    /** 闪兑/任务后推高累计价值（仍受软顶约束） */
    public static function bumpCumulative($amount)
    {
        $amount = max(0, (float)$amount);
        if ($amount <= 0) {
            return self::getCumulativePayout(false);
        }
        $ceiling = self::cumulativeCeiling();
        $cur = self::getCumulativePayout(false);
        $next = round(min($ceiling, $cur + $amount), 2);
        \think\Cache::set(self::CACHE_CUMULATIVE, $next, 86400 * 3650);
        return $next;
    }

    public static function resetCaches()
    {
        \think\Cache::rm(self::CACHE_SMOOTH);
        \think\Cache::rm(self::CACHE_SMOOTH_MINUTE);
        \think\Cache::rm(self::CACHE_DAILY_DATE);
        \think\Cache::rm(self::CACHE_TODAY_ADD);
        \think\Cache::rm(self::CACHE_YDAY_COUNT);
        \think\Cache::rm(self::CACHE_YDAY_PRICE);
        \think\Cache::rm(self::CACHE_WITHDRAW_N);
        \think\Cache::rm(self::CACHE_PRICE_FLOOR);
        \think\Cache::rm(self::CACHE_CUMULATIVE);
        \think\Cache::rm(self::CACHE_CUMULATIVE_DATE);
        \think\Cache::rm(self::CACHE_CUMULATIVE_TICK_AT);
        \think\Cache::rm(self::CACHE_ISSUED_SHARES);
        \think\Cache::rm('fanshub_market_price_floor');
        \think\Cache::rm(self::CACHE_REAL_COUNT);
    }

    /**
     * 大屏 payload（jackpot 接口 / config）
     * @param bool $tick 是否做微量爬升写缓存（轮询请 false）
     * @param bool $lite 精简字段，跳过 DB/调试项（轮询请 true）
     */
    public static function screenPayload($tick = true, $lite = false)
    {
        // 同请求内 memo，避免 totalSharesIssued / inject 重复算
        static $issuedMemo = null;
        static $injectMemo = null;

        if ($tick) {
            self::tickVirtualSmooth();
        } else {
            self::tickDailyGrowth();
        }
        $partners = self::partnerCountRaw();
        $price = self::getSharePrice(false);
        $amount = self::getCumulativePayout($tick);
        $seedShares = self::seedTotalShares();
        if ($issuedMemo === null) {
            $issuedMemo = self::totalSharesIssued();
        }
        $issued = $issuedMemo;
        $todayUp = self::todayPartnerUp();
        $priceUpPct = self::priceUpPercent();

        $payload = [
            'amount'              => $amount,
            'cumulative_payout'   => $amount,
            'share_price'         => $price,
            'partner_count'       => $partners,
            'fission_user_count'  => $partners,
            'partner_today_up'    => $todayUp,
            'price_up_pct'        => $priceUpPct,
            'seed_total_shares'   => $seedShares,
            'auto_grow'           => !empty(self::cfg('jackpot_auto_grow')),
            'server_sync'         => !empty(self::cfg('jackpot_server_sync')),
        ];

        if ($lite) {
            return $payload;
        }

        if ($injectMemo === null) {
            $injectMemo = self::totalVirtualInject();
        }
        $inject = $injectMemo;
        $avg = $partners > 0 ? round($seedShares / $partners, 2) : 0;

        return $payload + [
            'yesterday_partner_count' => self::yesterdayPartnerCount(),
            'yesterday_share_price'   => self::yesterdaySharePrice(),
            'real_user_count'     => self::realAccountCount(),
            'shares_issued'       => $issued,
            'virtual_inject'      => $inject,
            'formula_numerator'   => round(self::seedCapital() + $inject, 2),
            'formula_denominator' => round($seedShares + $issued, 2),
            'avg_shares_display'  => $avg,
            'withdraw_n'          => self::withdrawAchieverCount(),
            'price_min'           => self::priceMin(),
            'price_max'           => self::priceMax(),
        ];
    }
}
