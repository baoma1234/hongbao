<?php

namespace app\common\library;

use app\common\model\fanshub\Account;
use app\common\model\fanshub\Checkin;
use app\common\model\fanshub\Invite;
use app\common\model\fanshub\Secret;
use app\common\model\User;
use think\Db;

/**
 * 福利大厅二期：团长态、7天签到、荣誉天梯、战队雷达
 */
class FansHubPhase2
{
    public static function enabled()
    {
        return !empty(FansHubService::config('phase2_enabled'));
    }

    public static function honorTiers()
    {
        $cfg = FansHubService::config('honor_tiers');
        if (!is_array($cfg) || !$cfg) {
            return self::defaultHonorTiers();
        }
        $rows = [];
        foreach ($cfg as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $rows[$id] = [
                'id'        => $id,
                'name'      => (string)($row['name'] ?? ('段位' . $id)),
                'threshold' => max(1, (int)($row['threshold'] ?? 1)),
                'rights'    => (float)($row['rights'] ?? 0),
                'balance'   => (float)($row['balance'] ?? 0),
            ];
        }
        ksort($rows);
        return $rows ?: self::defaultHonorTiers();
    }

    public static function defaultHonorTiers()
    {
        return [
            1 => ['id' => 1, 'name' => '青铜团长', 'threshold' => 1, 'rights' => 10, 'balance' => 0],
            2 => ['id' => 2, 'name' => '白银团长', 'threshold' => 5, 'rights' => 50, 'balance' => 100],
            3 => ['id' => 3, 'name' => '钻石团长', 'threshold' => 10, 'rights' => 100, 'balance' => 300],
            4 => ['id' => 4, 'name' => '最强王者', 'threshold' => 20, 'rights' => 200, 'balance' => 800],
            5 => ['id' => 5, 'name' => '荣耀王者', 'threshold' => 50, 'rights' => 500, 'balance' => 2000],
        ];
    }

    public static function checkinBaseAmount()
    {
        return (float)FansHubService::config('checkin_base_amount', 1);
    }

    public static function checkinViolentBonus()
    {
        return (float)FansHubService::config('checkin_violent_bonus', 4);
    }

    public static function registerBonusRights()
    {
        return (float)FansHubService::config('register_bonus_rights', 1);
    }

    protected static function eventCacheKey($userId)
    {
        return 'fanshub_p2_events:' . (int)$userId;
    }

    public static function queueEvents($userId, array $events)
    {
        if ($userId <= 0 || !$events) {
            return;
        }
        $key = self::eventCacheKey($userId);
        $existing = \think\Cache::get($key);
        if (!is_array($existing)) {
            $existing = [];
        }
        \think\Cache::set($key, array_merge($existing, $events), 86400);
    }

    protected static function pullPendingEvents($userId)
    {
        $key = self::eventCacheKey($userId);
        $events = \think\Cache::get($key);
        \think\Cache::rm($key);
        return is_array($events) ? $events : [];
    }

    /**
     * 天梯明牌折算单价：与「今日大盘实时持仓行权价」一致
     */
    public static function honorDisplayPrice()
    {
        $price = (float)FansHubService::getSharePrice(false);
        if ($price <= 0) {
            $price = (float)FansHubService::config('market_share_price_base', 0);
        }
        if ($price <= 0) {
            $price = (float)FansHubService::config('single_ticket_value', 5);
        }
        return max(0.01, $price);
    }

    public static function localizedHonorName(array $tier)
    {
        $id = (int)($tier['id'] ?? 0);
        $fallback = (string)($tier['name'] ?? ('段位' . $id));
        if ($id <= 0) {
            return $fallback;
        }
        $text = FansHubService::h5CopyText('phase2_honor_name_' . $id);
        if ($text === '' || $text === ('phase2_honor_name_' . $id)) {
            return $fallback;
        }
        return $text;
    }

    protected static function honorRewardLine($rights, $balance, $rightsVal)
    {
        $rightsText = rtrim(rtrim(number_format((float)$rights, 2, '.', ''), '0'), '.');
        $valText = (string)(int)round((float)$rightsVal);
        $bal = (float)$balance;
        if ($bal > 0) {
            $line = FansHubService::h5CopyText('phase2_honor_reward_full', [
                'rights'     => $rightsText,
                'rights_val' => $valText,
                'balance'    => (string)(int)round($bal),
            ]);
            if ($line !== '' && $line !== 'phase2_honor_reward_full') {
                return $line;
            }
            return '解禁 ' . $rightsText . '股（￥' . $valText . '）＋ 额外直发 ' . (int)round($bal) . '元 现金';
        }
        $line = FansHubService::h5CopyText('phase2_honor_reward_rights', [
            'rights'     => $rightsText,
            'rights_val' => $valText,
        ]);
        if ($line !== '' && $line !== 'phase2_honor_reward_rights') {
            return $line;
        }
        return '解禁 ' . $rightsText . '股（￥' . $valText . '）';
    }

    protected static function honorTierEvent(array $tier, $subCount)
    {
        $id = (int)$tier['id'];
        $rights = (float)$tier['rights'];
        $balance = (float)$tier['balance'];
        $price = self::honorDisplayPrice();
        $rightsVal = number_format($rights * $price, 2, '.', '');
        $packTotal = number_format($rights * $price + $balance, 2, '.', '');
        $tierName = self::localizedHonorName($tier);
        $nextNeed = 0;
        foreach (self::honorTiers() as $row) {
            if ($row['id'] > $id && $subCount < $row['threshold']) {
                $nextNeed = $row['threshold'] - $subCount;
                break;
            }
        }
        $titleKey = 'phase2_honor_' . $id . '_title';
        $msgKey = 'phase2_honor_' . $id . '_msg';
        $title = FansHubService::h5CopyText($titleKey, ['name' => $tierName]);
        if ($title === '' || $title === $titleKey) {
            $title = FansHubService::h5CopyText('phase2_honor_tier_title', ['name' => $tierName]);
        }
        $message = FansHubService::h5CopyText($msgKey, [
            'name'       => $tierName,
            'rights'     => $rights,
            'balance'    => number_format($balance, 2, '.', ''),
            'rights_val' => $rightsVal,
            'pack_total' => $packTotal,
            'sub'        => $subCount,
            'need_next'  => max(0, $nextNeed),
        ]);
        if ($message === '' || $message === $msgKey) {
            $message = FansHubService::h5CopyText('phase2_honor_tier_msg', [
                'name'       => $tierName,
                'rights'     => $rights,
                'balance'    => number_format($balance, 2, '.', ''),
                'rights_val' => $rightsVal,
                'pack_total' => $packTotal,
                'sub'        => $subCount,
            ]);
        }
        return [
            'type'    => 'honor_tier',
            'tier'    => $id,
            'title'   => $title,
            'message' => $message,
            'html'    => true,
            'rights'  => $rights,
            'balance' => $balance,
            'capped'  => $id >= 5,
        ];
    }

    protected static function buildCheckinSuccessEvent($streakDay, $base, $bonus, $violent, $bonusUnlocked)
    {
        $unlockedText = $bonusUnlocked
            ? FansHubService::h5CopyText('phase2_checkin_bonus_unlocked')
            : FansHubService::h5CopyText('phase2_checkin_bonus_pending');
        $message = FansHubService::h5CopyText('phase2_checkin_success_msg', [
            'streak'   => $streakDay,
            'base'     => number_format($base, 2, '.', ''),
            'bonus'    => number_format($bonus, 2, '.', ''),
            'unlocked' => $unlockedText,
        ]);
        if ($violent && !$bonusUnlocked && $bonus > 0) {
            $message .= "\n\n" . FansHubService::h5CopyText('phase2_checkin_unlock_hint');
        }
        return [
            'type'           => 'checkin_success',
            'title'          => FansHubService::h5CopyText('phase2_checkin_success_title', ['streak' => $streakDay]),
            'message'        => $message,
            'html'           => true,
            'violent'        => $violent,
            'bonus_unlocked' => (bool)$bonusUnlocked,
            'streak_day'     => $streakDay,
        ];
    }

    public static function todayInviteCount($userId)
    {
        $today = self::todayDate();
        return (int)Invite::where('inviter_user_id', $userId)
            ->where('createtime', '>=', strtotime($today . ' 00:00:00'))
            ->count();
    }

    protected static function todayDate()
    {
        return date('Y-m-d');
    }

    protected static function yesterdayDate()
    {
        return date('Y-m-d', strtotime('-1 day'));
    }

    public static function totalRegisterCount($userId)
    {
        return (int)Invite::where('inviter_user_id', $userId)->count();
    }

    public static function recountSubWithdrawn($userId)
    {
        $count = (int)Db::name('fans_invite')->alias('i')
            ->join('fans_account a', 'a.user_id = i.invitee_user_id')
            ->where('i.inviter_user_id', $userId)
            ->where('a.first_withdraw_done', 1)
            ->count();
        $account = FansHubService::getOrCreateAccount($userId);
        if ((int)$account->sub_withdrawn_count !== $count) {
            $account->sub_withdrawn_count = $count;
            $account->updatetime = time();
            $account->save();
        }
        return $count;
    }

    public static function honorProgress($subWithdrawnCount)
    {
        $tiers = self::honorTiers();
        $price = self::honorDisplayPrice();
        $nodes = [];
        $icons = [1 => 'bronze', 2 => 'silver', 3 => 'diamond', 4 => 'crown', 5 => 'glory'];
        foreach ($tiers as $tier) {
            $rights = (float)$tier['rights'];
            $balance = (float)$tier['balance'];
            $rightsVal = round($rights * $price, 0);
            $packTotal = round($rightsVal + $balance, 0);
            $nodes[] = [
                'id'         => $tier['id'],
                'name'       => self::localizedHonorName($tier),
                'threshold'  => $tier['threshold'],
                'reached'    => $subWithdrawnCount >= $tier['threshold'],
                'icon'       => $icons[(int)$tier['id']] ?? '🎁',
                'rights'     => $rights,
                'balance'    => $balance,
                'rights_val' => $rightsVal,
                'pack_total' => $packTotal,
                'reward_line'=> self::honorRewardLine($rights, $balance, $rightsVal),
            ];
        }
        $next = null;
        foreach ($tiers as $tier) {
            if ($subWithdrawnCount < $tier['threshold']) {
                $next = $tier;
                break;
            }
        }
        $maxThreshold = 0;
        foreach ($tiers as $tier) {
            $maxThreshold = max($maxThreshold, $tier['threshold']);
        }
        $pct = $maxThreshold > 0 ? min(100, round($subWithdrawnCount / $maxThreshold * 100, 1)) : 0;
        if ($next) {
            $prevThreshold = 0;
            foreach ($tiers as $tier) {
                if ($tier['id'] === $next['id']) {
                    break;
                }
                $prevThreshold = $tier['threshold'];
            }
            $span = max(1, $next['threshold'] - $prevThreshold);
            $pct = min(100, round(($subWithdrawnCount - $prevThreshold) / $span * 100, 1));
            $nRights = (float)$next['rights'];
            $nBalance = (float)$next['balance'];
            $nRightsVal = round($nRights * $price, 0);
            $next['rights'] = $nRights;
            $next['balance'] = $nBalance;
            $next['rights_val'] = $nRightsVal;
            $next['pack_total'] = round($nRightsVal + $nBalance, 0);
            $next['need'] = max(0, (int)$next['threshold'] - (int)$subWithdrawnCount);
            $next['reward_line'] = self::honorRewardLine($nRights, $nBalance, $nRightsVal);
            $next['name'] = self::localizedHonorName($next);
        } elseif ($subWithdrawnCount >= $maxThreshold) {
            $pct = 100;
        }
        $topPack = 0;
        $cumPack = 0;
        if ($nodes) {
            foreach ($nodes as $node) {
                $cumPack += (float)$node['pack_total'];
            }
            $last = $nodes[count($nodes) - 1];
            $topPack = (float)$last['pack_total'];
        }
        return [
            'nodes'              => $nodes,
            'sub_withdrawn_count'=> $subWithdrawnCount,
            'next_tier'          => $next,
            'progress_percent'   => $pct,
            'capped'             => $subWithdrawnCount >= $maxThreshold,
            'top_pack_total'     => $topPack,
            'cum_pack_total'     => $cumPack,
            'share_price'        => round($price, 2),
        ];
    }

    public static function enrichProfile($userId, array $profile)
    {
        if (!self::enabled()) {
            $profile['phase2'] = ['enabled' => false];
            return $profile;
        }
        $account = FansHubService::getOrCreateAccount($userId);
        $subCount = self::recountSubWithdrawn($userId);
        $registerCount = self::totalRegisterCount($userId);
        $today = self::todayDate();
        $todayCheckin = Checkin::where('user_id', $userId)->where('checkin_date', $today)->find();
        $pendingBonus = (float)Checkin::where('user_id', $userId)
            ->where('bonus_amount', '>', 0)
            ->where('bonus_unlocked', 0)
            ->sum('bonus_amount');

        $profile['phase2'] = [
            'enabled'                 => true,
            'user_mode'               => (string)($account->user_mode ?: 'newbie'),
            'total_register_count'    => $registerCount,
            'fission_streak_days'     => (int)$account->fission_streak_days,
            'fission_streak_qualified'=> !empty($account->fission_streak_qualified),
            'streak_frozen'           => (string)$account->user_mode === 'master'
                && empty($account->fission_streak_qualified)
                && (int)$account->fission_streak_days > 0,
            'today_invite_count'      => self::todayInviteCount($userId),
            'sub_withdrawn_count'     => $subCount,
            'honor_tier_claimed'      => (int)$account->honor_tier_claimed,
            'first_withdraw_done'     => !empty($account->first_withdraw_done),
            'honor'                   => self::honorProgress($subCount),
            'checkin'                 => [
                'checked_today'    => (bool)$todayCheckin,
                'today_mode'       => $todayCheckin ? (string)$todayCheckin->mode : '',
                'bonus_unlocked'   => $todayCheckin ? !empty($todayCheckin->bonus_unlocked) : false,
                'pending_bonus'    => round($pendingBonus, 2),
                'streak_day'       => (int)$account->fission_streak_days,
                'streak_qualified' => !empty($account->fission_streak_qualified),
            ],
            'events'                  => self::pullPendingEvents($userId),
        ];
        return $profile;
    }

    public static function teamRadar($userId)
    {
        $threshold = (float)FansHubService::config('withdraw_threshold', 50);
        $rows = Db::name('fans_invite')->alias('i')
            ->join('user u', 'u.id = i.invitee_user_id', 'LEFT')
            ->join('fans_account a', 'a.user_id = i.invitee_user_id', 'LEFT')
            ->where('i.inviter_user_id', $userId)
            ->field('i.invitee_user_id,i.invitee_mobile,a.hongbao,a.first_withdraw_done,u.mobile')
            ->order('i.id', 'desc')
            ->select();
        $list = [];
        foreach ($rows as $row) {
            // 提现进度按红宝计（兼容旧字段名 balance）
            $balance = round((float)($row['hongbao'] ?? 0), 2);
            $withdrawn = !empty($row['first_withdraw_done']);
            $mobile = (string)($row['mobile'] ?: $row['invitee_mobile']);
            $list[] = [
                'user_id'    => (int)$row['invitee_user_id'],
                'mobile_mask'=> FansHubService::maskMobile($mobile),
                'balance'    => $balance,
                'hongbao'    => $balance,
                'threshold'  => $threshold,
                'progress'   => $threshold > 0 ? min(100, round($balance / $threshold * 100, 1)) : 0,
                'withdrawn'  => $withdrawn,
                'can_urge'   => !$withdrawn,
            ];
        }
        return ['list' => $list, 'total' => count($list)];
    }

    public static function checkIn($userId, $violent = true, $confirmed = false)
    {
        if (!self::enabled()) {
            FansHubService::throwCopy('api_operation_fail');
        }
        $account = FansHubService::getOrCreateAccount($userId);
        if ((string)$account->user_mode !== 'master') {
            FansHubService::throwCopy('phase2_master_only');
        }
        $today = self::todayDate();
        if (Checkin::where('user_id', $userId)->where('checkin_date', $today)->find()) {
            FansHubService::throwCopy('phase2_checkin_done');
        }

        if (!$violent && !$confirmed) {
            return [
                'need_confirm' => true,
                'events'       => [[
                    'type'    => 'confirm_normal',
                    'title'   => FansHubService::h5CopyText('phase2_confirm_violent_title'),
                    'message' => FansHubService::h5CopyText('phase2_confirm_violent_msg'),
                    'html'    => true,
                ]],
                'profile'      => self::enrichProfile($userId, FansHubService::profilePayload($userId)),
            ];
        }

        $events = [];
        $result = self::processCheckin($userId, $account, (bool)$violent, $events);
        $result['share'] = FansHubService::buildSharePayload($userId);
        return $result;
    }

    /** @deprecated use checkIn */
    public static function confirmCheckIn($userId, $violent = true)
    {
        return self::checkIn($userId, $violent, true);
    }

    protected static function processCheckin($userId, Account $account, $violent, array &$events)
    {
        $today = self::todayDate();
        $yesterday = self::yesterdayDate();
        $base = self::checkinBaseAmount();
        $bonus = $violent ? self::checkinViolentBonus() : 0;
        $lastDate = (string)($account->fission_last_checkin_date ?? '');
        $streak = (int)$account->fission_streak_days;
        $qualified = !empty($account->fission_streak_qualified);

        if ($lastDate === $yesterday) {
            $streak++;
        } elseif ($lastDate !== $today && $lastDate !== '') {
            if ($qualified) {
                $events[] = [
                    'type'    => 'streak_broken',
                    'title'   => FansHubService::h5CopyText('phase2_streak_broken_title'),
                    'message' => FansHubService::h5CopyText('phase2_streak_broken_msg'),
                    'html'    => true,
                ];
                $qualified = false;
            }
            $streak = 1;
        } elseif ($lastDate === '') {
            $streak = 1;
            $qualified = $violent;
        }

        if ($violent && !$qualified) {
            $qualified = true;
        }

        $streakDay = min(7, max(1, $streak));
        $now = time();
        Db::startTrans();
        try {
            FansHubService::changeAssets($userId, 0, $base, 'checkin', '星火签到基础福利');
            $bonusUnlocked = 0;
            if ($bonus > 0) {
                if ($violent) {
                    $todayRegs = (int)Invite::where('inviter_user_id', $userId)
                        ->where('createtime', '>=', strtotime($today . ' 00:00:00'))
                        ->count();
                    if ($todayRegs > 0) {
                        FansHubService::changeAssets($userId, 0, $bonus, 'checkin_bonus', '暴力分享对账成功');
                        $bonusUnlocked = 1;
                    }
                }
            }

            Checkin::create([
                'user_id'         => $userId,
                'checkin_date'    => $today,
                'mode'            => $violent ? 'violent' : 'normal',
                'base_amount'     => $base,
                'bonus_amount'    => $bonus,
                'bonus_unlocked'  => $bonusUnlocked,
                'streak_day'      => $streakDay,
                'day7_settled'    => 0,
                'createtime'      => $now,
            ]);

            $day7Extra = 0;
            if ($streakDay === 7 && $violent && $qualified) {
                $day7Extra = self::settleDay7Bonus($userId, $events);
            }

            $account->fission_streak_days = $streakDay >= 7 ? 0 : $streakDay;
            $account->fission_streak_qualified = $qualified ? 1 : 0;
            $account->fission_last_checkin_date = $today;
            if ($streakDay >= 7) {
                $account->fission_streak_qualified = $violent ? 1 : 0;
            }
            $account->updatetime = $now;
            $account->save();

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        $events[] = self::buildCheckinSuccessEvent($streakDay, $base, $bonus, $violent, (bool)$bonusUnlocked);
        if ($day7Extra > 0) {
            // day7 event already pushed in settleDay7Bonus
        }

        $profile = FansHubService::profilePayload($userId);
        $honorEvents = self::tryClaimHonorTiers($userId);
        $events = array_merge($events, $honorEvents);
        $profile['phase2']['events'] = $events;

        return [
            'profile' => $profile,
            'events'  => $events,
        ];
    }

    protected static function settleDay7Bonus($userId, array &$events)
    {
        $already = Checkin::where('user_id', $userId)->where('day7_settled', 1)->find();
        if ($already) {
            return 0;
        }
        $extra = 145.0;
        FansHubService::changeAssets($userId, 0, $extra, 'checkin_day7', '7天星火暴击差额补齐');
        Checkin::where('user_id', $userId)->where('streak_day', 7)->update(['day7_settled' => 1]);
        $balance = (float)FansHubService::getOrCreateAccount($userId)->balance;
        $events[] = [
            'type'    => 'day7_explosion',
            'title'   => FansHubService::h5CopyText('phase2_day7_title'),
            'message' => FansHubService::h5CopyText('phase2_day7_msg', [
                'extra'   => number_format($extra, 2, '.', ''),
                'balance' => number_format($balance, 2, '.', ''),
            ]),
            'html'    => true,
            'extra'   => $extra,
        ];
        return $extra;
    }

    public static function onInviteRegistered($inviterUserId)
    {
        if (!self::enabled() || $inviterUserId <= 0) {
            return;
        }
        $bonusRights = self::registerBonusRights();
        if ($bonusRights > 0) {
            FansHubService::changeAssets($inviterUserId, $bonusRights, 0, 'register_bonus', '拉新注册额外股份');
        }
        self::unlockTodayViolentBonus($inviterUserId);
        self::tryReviveStreak($inviterUserId);
    }

    protected static function unlockTodayViolentBonus($userId)
    {
        $today = self::todayDate();
        $row = Checkin::where('user_id', $userId)
            ->where('checkin_date', $today)
            ->where('mode', 'violent')
            ->where('bonus_unlocked', 0)
            ->where('bonus_amount', '>', 0)
            ->find();
        if (!$row) {
            return;
        }
        FansHubService::changeAssets($userId, 0, (float)$row->bonus_amount, 'checkin_bonus', '今日暴力对账箱结算');
        $row->bonus_unlocked = 1;
        $row->save();
        self::queueEvents($userId, [[
            'type'    => 'bonus_unlocked',
            'title'   => FansHubService::h5CopyText('phase2_bonus_unlocked_title'),
            'message' => FansHubService::h5CopyText('phase2_bonus_unlocked_msg', [
                'amount' => number_format((float)$row->bonus_amount, 2, '.', ''),
                'bonus'  => number_format((float)$row->bonus_amount, 2, '.', ''),
            ]),
            'html'    => true,
        ]]);
    }

    protected static function tryReviveStreak($userId)
    {
        $account = FansHubService::getOrCreateAccount($userId);
        if (!empty($account->fission_streak_qualified)) {
            return;
        }
        if (self::todayInviteCount($userId) < 2) {
            return;
        }
        $account->fission_streak_qualified = 1;
        $account->updatetime = time();
        $account->save();
        self::queueEvents($userId, [[
            'type'    => 'streak_revived',
            'title'   => FansHubService::h5CopyText('phase2_streak_revived_title'),
            'message' => FansHubService::h5CopyText('phase2_streak_revived_msg'),
            'html'    => true,
        ]]);
    }

    public static function onSecretCompleted($userId, $secret)
    {
        if (!self::enabled()) {
            return [];
        }
        $account = FansHubService::getOrCreateAccount($userId);
        $threshold = (float)FansHubService::config('withdraw_threshold', 50);
        $amount = (float)($secret->amount ?? 0);
        $tier = (string)($secret->tier ?? '');
        if ($tier !== 'VIP' || $amount < $threshold) {
            return [];
        }
        $events = [];
        $wasFirst = empty($account->first_withdraw_done);
        if ($wasFirst) {
            $account->first_withdraw_done = 1;
            $account->user_mode = 'master';
            $account->fission_streak_qualified = 1;
            $account->updatetime = time();
            $account->save();
            $events[] = [
                'type'    => 'mode_master',
                'title'   => FansHubService::h5CopyText('phase2_master_unlock_title'),
                'message' => FansHubService::h5CopyText('phase2_master_unlock_msg'),
                'html'    => true,
            ];
            self::queueEvents($userId, $events);
            $events = [];
        }
        $invite = Invite::where('invitee_user_id', $userId)->find();
        if ($invite) {
            $inviterId = (int)$invite->inviter_user_id;
            self::recountSubWithdrawn($inviterId);
            $honorEvents = self::tryClaimHonorTiers($inviterId);
            if ($honorEvents) {
                self::queueEvents($inviterId, $honorEvents);
            }
        }
        return $events;
    }

    public static function tryClaimHonorTiers($userId)
    {
        if (!self::enabled()) {
            return [];
        }
        $account = FansHubService::getOrCreateAccount($userId);
        if ((string)$account->user_mode !== 'master') {
            return [];
        }
        $subCount = self::recountSubWithdrawn($userId);
        $claimed = (int)$account->honor_tier_claimed;
        $events = [];
        foreach (self::honorTiers() as $tier) {
            if ($tier['id'] <= $claimed) {
                continue;
            }
            if ($subCount < $tier['threshold']) {
                break;
            }
            $rights = (float)$tier['rights'];
            $balance = (float)$tier['balance'];
            if ($rights > 0 || $balance > 0) {
                FansHubService::changeAssets($userId, $rights, $balance, 'honor_tier', $tier['name'] . '晋升奖励');
            }
            $account->honor_tier_claimed = (int)$tier['id'];
            $account->updatetime = time();
            $account->save();
            $events[] = self::honorTierEvent($tier, $subCount);
        }
        return $events;
    }

    /**
     * 后台人工晋升团长：用户态=团长，荣誉段位=青铜团长（不发放段位奖励）
     */
    public static function adminPromoteToMaster($userId)
    {
        if (!self::enabled()) {
            throw new \Exception('团长二期功能未开启');
        }
        $userId = (int)$userId;
        $account = FansHubService::getOrCreateAccount($userId);
        $bronzeId = 1;
        foreach (self::honorTiers() as $tier) {
            if ((int)$tier['id'] === 1 || (string)$tier['name'] === '青铜团长') {
                $bronzeId = (int)$tier['id'];
                break;
            }
        }
        $account->save([
            'user_mode'               => 'master',
            'honor_tier_claimed'      => $bronzeId,
            'fission_streak_qualified'=> 1,
            'updatetime'              => time(),
        ]);
        return [
            'user_mode'          => 'master',
            'honor_tier_claimed' => $bronzeId,
            'honor_tier_name'    => self::honorTierName($bronzeId),
        ];
    }

    public static function urgeCopyText($inviterUserId, $inviteeUserId)
    {
        $profile = FansHubService::profilePayload($inviterUserId);
        $code = $profile['invite_code'] ?? '';
        $base = rtrim((string)FansHubService::config('invite_base_url', ''), '/');
        if ($base === '') {
            $base = rtrim((string)request()->domain(), '/');
        }
        $h5Path = trim((string)FansHubService::config('h5_entry_path', '888'), '/');
        $link = $base . '/' . $h5Path . '?code=' . rawurlencode($code);
        return FansHubService::h5CopyText('phase2_urge_copy', ['link' => $link]);
    }

    /**
     * 二期运营统计（供后台总览）
     */
    public static function dashboardStats($start = 0, $end = 0)
    {
        if (!self::enabled()) {
            return ['enabled' => false];
        }
        $prefix = config('database.prefix');
        $accountTable = $prefix . 'fans_account';
        $checkinTable = $prefix . 'fans_checkin';
        $ledgerTable = $prefix . 'fans_ledger';
        $timeSql = ($start > 0 && $end > 0) ? ' AND createtime BETWEEN ' . (int)$start . ' AND ' . (int)$end : '';
        $checkinDateSql = '';
        if ($start > 0 && $end > 0) {
            $checkinDateSql = ' AND checkin_date BETWEEN \'' . date('Y-m-d', $start) . '\' AND \'' . date('Y-m-d', $end) . '\'';
        }
        $today = self::todayDate();

        $hasUserMode = self::accountColumnExists('user_mode');
        $masterCount = 0;
        $newbieCount = 0;
        $streakFrozen = 0;
        if ($hasUserMode) {
            $masterCount = (int)Account::where('user_mode', 'master')->count();
            $newbieCount = (int)Account::where('user_mode', 'newbie')->count();
            $streakFrozen = (int)Account::where('user_mode', 'master')
                ->where('fission_streak_qualified', 0)
                ->where('fission_streak_days', '>', 0)
                ->count();
        }

        $checkinTableExists = self::tableExists($checkinTable);
        $checkinToday = 0;
        $checkinViolentToday = 0;
        $checkinBonusPending = 0;
        $checkinTotal = 0;
        if ($checkinTableExists) {
            $checkinToday = (int)Db::query("SELECT COUNT(*) AS c FROM `{$checkinTable}` WHERE checkin_date = '{$today}'")[0]['c'];
            $checkinViolentToday = (int)Db::query("SELECT COUNT(*) AS c FROM `{$checkinTable}` WHERE checkin_date = '{$today}' AND mode = 'violent'")[0]['c'];
            $checkinBonusPending = (int)Db::query("SELECT COUNT(*) AS c FROM `{$checkinTable}` WHERE checkin_date = '{$today}' AND mode = 'violent' AND bonus_unlocked = 0 AND bonus_amount > 0")[0]['c'];
            $checkinTotal = (int)Db::query("SELECT COUNT(*) AS c FROM `{$checkinTable}` WHERE 1=1{$checkinDateSql}")[0]['c'];
        }

        $ledgerTypes = ['checkin', 'checkin_bonus', 'checkin_day7', 'honor_tier', 'register_bonus'];
        $ledgerCounts = [];
        foreach ($ledgerTypes as $type) {
            $ledgerCounts[$type] = (int)Db::query("SELECT COUNT(*) AS c FROM `{$ledgerTable}` WHERE type = '{$type}'{$timeSql}")[0]['c'];
        }

        $honorDistribution = [];
        if ($hasUserMode && self::accountColumnExists('honor_tier_claimed')) {
            $rows = Db::query("SELECT honor_tier_claimed AS tier, COUNT(*) AS cnt FROM `{$accountTable}` WHERE user_mode = 'master' GROUP BY honor_tier_claimed ORDER BY tier ASC");
            $tierMap = self::honorTiers();
            foreach ($rows as $row) {
                $tier = (int)$row['tier'];
                $name = $tier > 0 && isset($tierMap[$tier]) ? $tierMap[$tier]['name'] : ($tier > 0 ? '段位' . $tier : '未晋升');
                $honorDistribution[] = [
                    'tier'  => $tier,
                    'name'  => $name,
                    'count' => (int)$row['cnt'],
                ];
            }
        }

        $subWithdrawnTotal = 0;
        if (self::accountColumnExists('sub_withdrawn_count')) {
            $subWithdrawnTotal = (int)Db::query("SELECT COALESCE(SUM(sub_withdrawn_count), 0) AS s FROM `{$accountTable}`")[0]['s'];
        }

        $firstWithdrawDone = 0;
        if (self::accountColumnExists('first_withdraw_done')) {
            $firstWithdrawDone = (int)Account::where('first_withdraw_done', 1)->count();
        }

        $topMasters = [];
        if (self::accountColumnExists('sub_withdrawn_count')) {
            $rows = Account::where('user_mode', 'master')
                ->where('sub_withdrawn_count', '>', 0)
                ->order('sub_withdrawn_count', 'desc')
                ->limit(10)
                ->select();
            $rank = 1;
            foreach ($rows as $row) {
                $user = User::get($row->user_id);
                $topMasters[] = [
                    'rank'               => $rank++,
                    'user_id'            => (int)$row->user_id,
                    'mobile_mask'        => FansHubService::maskMobile($user ? $user->mobile : ''),
                    'sub_withdrawn_count'=> (int)$row->sub_withdrawn_count,
                    'honor_tier_claimed' => (int)($row->honor_tier_claimed ?? 0),
                ];
            }
        }

        return [
            'enabled'              => true,
            'master_count'         => $masterCount,
            'newbie_count'         => $newbieCount,
            'first_withdraw_done'  => $firstWithdrawDone,
            'streak_frozen'        => $streakFrozen,
            'checkin_today'        => $checkinToday,
            'checkin_violent_today'=> $checkinViolentToday,
            'checkin_bonus_pending'=> $checkinBonusPending,
            'checkin_total'        => $checkinTotal,
            'sub_withdrawn_total'  => $subWithdrawnTotal,
            'ledger'               => $ledgerCounts,
            'honor_distribution' => $honorDistribution,
            'top_masters'          => $topMasters,
        ];
    }

    protected static function tableExists($table)
    {
        $table = str_replace('`', '', (string)$table);
        $rows = Db::query('SHOW TABLES LIKE \'' . addslashes($table) . '\'');
        return !empty($rows);
    }

    protected static function accountColumnExists($column)
    {
        static $cache = [];
        $prefix = config('database.prefix');
        $table = $prefix . 'fans_account';
        if (!isset($cache[$table])) {
            $cache[$table] = [];
            $cols = Db::query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
            foreach ($cols as $col) {
                $cache[$table][$col['Field']] = true;
            }
        }
        return !empty($cache[$table][$column]);
    }

    public static function honorTierName($tierId)
    {
        $tierId = (int)$tierId;
        if ($tierId <= 0) {
            return '未晋升';
        }
        $tiers = self::honorTiers();
        return isset($tiers[$tierId]) ? $tiers[$tierId]['name'] : ('段位' . $tierId);
    }
}
