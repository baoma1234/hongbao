<?php

namespace app\common\library;

use app\common\model\fanshub\FissionActivity;
use app\common\model\fanshub\FissionQual;
use app\common\model\fanshub\Invite;
use app\common\model\User;
use think\Db;
use think\Exception;

/**
 * 全网裂变红包 V1（单任务）
 */
class FansHubFission
{
    /**
     * 首页/弹窗入口摘要（可匿名）
     */
    public static function entryPayload($userId = 0)
    {
        self::tickExpire();
        $act = self::latestVisibleActivity();
        if (!$act) {
            return [
                'has_activity' => false,
                'entry_state'  => 'hidden',
                'activity'     => null,
                'popup'        => ['show' => false],
                'server_time'  => time(),
            ];
        }
        $status = (int)$act['status'];
        $entryState = 'hidden';
        if ($status === FissionActivity::STATUS_RUNNING) {
            $entryState = 'active';
        } elseif (in_array($status, [FissionActivity::STATUS_SUCCESS, FissionActivity::STATUS_EXPIRED], true)) {
            $entryState = 'ended';
        }

        $now = time();
        // 进行中即允许大厅弹窗；是否已看过由前端 localStorage 控制
        $popupShow = $entryState === 'active';

        return [
            'has_activity' => true,
            'entry_state'  => $entryState,
            'activity'     => self::publicActivityFields($act),
            'popup'        => [
                'show'         => $popupShow,
                'activity_id'  => (int)$act['id'],
                'title'        => (string)$act['title'],
                'pool_amount'  => round((float)$act['pool_amount'], 2),
                'remain_sec'   => max(0, (int)$act['end_time'] - $now),
            ],
            'server_time'  => $now,
            'user_id'      => (int)$userId,
        ];
    }

    /**
     * 活动页完整数据
     */
    public static function detailPayload($userId)
    {
        $userId = (int)$userId;
        self::tickExpire();
        $act = self::latestVisibleActivity();
        if (!$act) {
            return [
                'has_activity' => false,
                'state'        => 'none',
                'activity'     => null,
                'me'           => null,
                'server_time'  => time(),
            ];
        }

        $now = time();
        $status = (int)$act['status'];
        $state = 'running';
        if ($status === FissionActivity::STATUS_SUCCESS) {
            $state = 'success';
        } elseif ($status === FissionActivity::STATUS_EXPIRED) {
            $state = 'expired';
        } elseif ($status !== FissionActivity::STATUS_RUNNING) {
            $state = 'none';
        }

        // 有资格即可开包：把尚未赋值的资格按「奖金池/上限」定额发出
        self::ensureQualPayouts((int)$act['id']);

        $myQuals = 0;
        $myWin = 0.0;
        $unclaimed = 0;
        $claimed = 0;
        $joined = false;
        $qualItems = [];
        if ($userId > 0) {
            $rows = FissionQual::where('activity_id', (int)$act['id'])->where('user_id', $userId)->order('id', 'asc')->select();
            foreach ($rows as $r) {
                $myQuals++;
                $win = null;
                if ($r->win_amount !== null && $r->win_amount !== '') {
                    $win = round((float)$r->win_amount, 2);
                }
                $isClaimed = (int)($r->claimed ?? 0) === 1;
                if ($win !== null && $win > 0) {
                    if ($isClaimed) {
                        $claimed++;
                        // 仅已拆开的份计入对外展示的中奖合计，避免未拆先看到金额
                        $myWin = round($myWin + $win, 2);
                    } else {
                        $unclaimed++;
                    }
                }
                if ((string)$r->source === FissionQual::SOURCE_JOIN) {
                    $joined = true;
                }
                $qualItems[] = [
                    'id'         => (int)$r->id,
                    'source'     => (string)$r->source,
                    // 未领取前不回传具体金额
                    'win_amount' => $isClaimed ? $win : null,
                    'claimed'    => $isClaimed ? 1 : 0,
                    'claimed_at' => (int)($r->claimed_at ?? 0),
                ];
            }
        }
        // 跨期待领（含进行中已赋额、已开奖未领）
        $claimableUnclaimed = $userId > 0 ? self::countUserClaimable($userId) : 0;
        $priorUnclaimed = 0;
        if ($claimableUnclaimed > $unclaimed) {
            if ($state === 'running') {
                $priorUnclaimed = max(0, $claimableUnclaimed - $unclaimed);
            }
            $unclaimed = $claimableUnclaimed;
        }
        // 直属下级：仅统计活动开始后绑定的邀请（与资格发放窗口一致）
        $startTs = max(0, (int)$act['start_time']);
        $subCount = 0;
        if ($userId > 0) {
            $subQ = Invite::where('inviter_user_id', $userId);
            if ($startTs > 0) {
                $subQ->where('createtime', '>=', $startTs);
            }
            $subCount = (int)$subQ->count();
        }

        $inviteLink = '';
        $inviteCode = '';
        if ($userId > 0) {
            $share = FansHubService::buildSharePayload($userId);
            $inviteLink = (string)($share['share_link'] ?? '');
            $inviteCode = FansHubService::encodeInviteCode($userId);
            if ($inviteLink !== '' && strpos($inviteLink, 'fission=') === false) {
                $inviteLink .= (strpos($inviteLink, '?') === false ? '?' : '&') . 'fission=1&aid=' . (int)$act['id'];
            }
        }

        $global = (int)$act['global_quals'];
        $cap = max(1, (int)$act['global_cap']);
        $progressPct = min(100, round($global * 100 / $cap, 2));

        return [
            'has_activity' => true,
            'state'        => $state,
            'activity'     => array_merge(self::publicActivityFields($act), [
                'progress_pct' => $progressPct,
                'remain_sec'   => max(0, (int)$act['end_time'] - $now),
                'can_gain'     => $state === 'running' && $global < $cap,
            ]),
            'me' => [
                'joined'            => $joined,
                'qual_count'        => $myQuals,
                'user_cap'          => (int)$act['user_cap'],
                'subordinate_count' => $subCount,
                'win_amount'        => $myWin,
                'unclaimed_count'   => $unclaimed,
                'claimed_count'     => $claimed,
                // 有资格（已赋额未领）即可拆，无需等人数满
                'can_claim'         => $unclaimed > 0,
                'prior_claim_pending' => $priorUnclaimed > 0 ? 1 : 0,
                'quals'             => $qualItems,
                'invite_link'       => $inviteLink,
                'invite_code'       => $inviteCode,
            ],
            'group' => FansHubService::fissionGroupInvitePayload(),
            'pool_summary' => self::poolSummary((int)$act['id'], $act),
            'rules' => self::defaultRules(),
            'server_time' => $now,
        ];
    }

    /**
     * 奖金池领取记录（点击奖金池查看）
     * 未领也会把当前资格定额发出，列表可见
     */
    public static function claimsPayload($activityId = 0)
    {
        self::tickExpire();
        $activityId = (int)$activityId;
        if ($activityId <= 0) {
            $act = self::latestVisibleActivity();
        } else {
            $act = Db::name('fans_fission_activity')->where('id', $activityId)->find();
        }
        if (!$act) {
            return [
                'has_activity' => false,
                'activity'     => null,
                'summary'      => [
                    'pool_amount'      => 0,
                    'claimed_amount'   => 0,
                    'unclaimed_amount' => 0,
                    'remain_balance'   => 0,
                    'total'            => 0,
                    'claimed_count'    => 0,
                    'unclaimed_count'  => 0,
                ],
                'list'         => [],
                'server_time'  => time(),
            ];
        }
        $aid = (int)$act['id'];
        self::ensureQualPayouts($aid);
        // 打开领取记录时顺带校正误写入的未来时间
        $futureCnt = (int)Db::name('fans_fission_qual')
            ->where('activity_id', $aid)
            ->where('claimed', 1)
            ->where('claimed_at', '>', time())
            ->count();
        if ($futureCnt > 0) {
            try {
                Db::startTrans();
                self::clampFutureClaimedAtLocked($aid);
                Db::commit();
            } catch (\Throwable $e) {
                Db::rollback();
            }
        }
        $summary = self::poolSummary($aid, $act);
        $rows = Db::name('fans_fission_qual')
            ->alias('q')
            ->join('user u', 'u.id = q.user_id', 'LEFT')
            ->where('q.activity_id', $aid)
            ->field('q.id,q.user_id,q.win_amount,q.claimed,q.claimed_at,q.createtime,q.source,u.nickname,u.avatar')
            ->orderRaw('COALESCE(NULLIF(q.claimed_at, 0), q.createtime) DESC, q.id DESC')
            ->limit(200)
            ->select();
        $rows = is_array($rows) ? $rows : $rows->toArray();
        $list = [];
        foreach ($rows as $r) {
            $nick = trim((string)($r['nickname'] ?? ''));
            if ($nick === '') {
                $nick = '用户' . (int)$r['user_id'];
            }
            $list[] = [
                'id'         => (int)$r['id'],
                'user_id'    => (int)$r['user_id'],
                'nickname'   => self::maskNick($nick),
                'avatar'     => (string)($r['avatar'] ?? ''),
                'amount'     => round((float)($r['win_amount'] ?? 0), 2),
                'claimed'    => (int)($r['claimed'] ?? 0) === 1 ? 1 : 0,
                'claimed_at' => (int)($r['claimed_at'] ?? 0),
                'createtime' => (int)($r['createtime'] ?? 0),
                'source'     => (string)($r['source'] ?? ''),
            ];
        }
        return [
            'has_activity' => true,
            'activity'     => self::publicActivityFields($act),
            'summary'      => $summary,
            'list'         => $list,
            'server_time'  => time(),
        ];
    }

    protected static function poolSummary($activityId, array $act = null)
    {
        $activityId = (int)$activityId;
        if (!$act) {
            $act = Db::name('fans_fission_activity')->where('id', $activityId)->find() ?: [];
        }
        $pool = round((float)($act['pool_amount'] ?? 0), 2);
        $claimedAmt = 0.0;
        $unclaimedAmt = 0.0;
        $claimedCount = 0;
        $unclaimedCount = 0;
        $total = 0;
        try {
            $agg = Db::name('fans_fission_qual')
                ->where('activity_id', $activityId)
                ->field('COUNT(*) AS total,'
                    . 'SUM(CASE WHEN claimed=1 THEN 1 ELSE 0 END) AS claimed_count,'
                    . 'SUM(CASE WHEN claimed=0 AND win_amount>0 THEN 1 ELSE 0 END) AS unclaimed_count,'
                    . 'SUM(CASE WHEN claimed=1 THEN win_amount ELSE 0 END) AS claimed_amount,'
                    . 'SUM(CASE WHEN claimed=0 AND win_amount>0 THEN win_amount ELSE 0 END) AS unclaimed_amount')
                ->find();
            if ($agg) {
                $total = (int)($agg['total'] ?? 0);
                $claimedCount = (int)($agg['claimed_count'] ?? 0);
                $unclaimedCount = (int)($agg['unclaimed_count'] ?? 0);
                $claimedAmt = round((float)($agg['claimed_amount'] ?? 0), 2);
                $unclaimedAmt = round((float)($agg['unclaimed_amount'] ?? 0), 2);
            }
        } catch (\Throwable $e) {
        }
        return [
            'pool_amount'      => $pool,
            'claimed_amount'   => $claimedAmt,
            'unclaimed_amount' => $unclaimedAmt,
            // 奖金池余额：总池 - 已领取
            'remain_balance'   => max(0, round($pool - $claimedAmt, 2)),
            'total'            => $total,
            'claimed_count'    => $claimedCount,
            'unclaimed_count'  => $unclaimedCount,
        ];
    }

    protected static function maskNick($nick)
    {
        $nick = (string)$nick;
        $len = function_exists('mb_strlen') ? mb_strlen($nick, 'UTF-8') : strlen($nick);
        if ($len <= 1) {
            return $nick . '*';
        }
        if ($len === 2) {
            $a = function_exists('mb_substr') ? mb_substr($nick, 0, 1, 'UTF-8') : substr($nick, 0, 1);
            return $a . '*';
        }
        $a = function_exists('mb_substr') ? mb_substr($nick, 0, 1, 'UTF-8') : substr($nick, 0, 1);
        $b = function_exists('mb_substr') ? mb_substr($nick, -1, 1, 'UTF-8') : substr($nick, -1);
        return $a . '***' . $b;
    }

    /**
     * 把尚未赋额的资格按「奖金池 / 全局上限」随机发出（二倍均值，不是均分）
     * 若未领份额仍全是旧均分金额，则一次性重拆为随机包。
     */
    public static function ensureQualPayouts($activityId)
    {
        $activityId = (int)$activityId;
        if ($activityId <= 0) {
            return 0;
        }
        try {
            Db::startTrans();
            $act = Db::name('fans_fission_activity')->where('id', $activityId)->lock(true)->find();
            if (!$act) {
                Db::commit();
                return 0;
            }
            $n = self::assignRandomPayoutsLocked($act, false);
            Db::commit();
            return $n;
        } catch (\Throwable $e) {
            try {
                Db::rollback();
            } catch (\Throwable $ignore) {
            }
            return 0;
        }
    }

    /**
     * 旧均分单价（仅用于识别历史均分数据 / 兼容展示）
     */
    protected static function unitWinAmount(array $act)
    {
        $cap = max(1, (int)($act['global_cap'] ?? 100));
        $cents = (int)round((float)($act['pool_amount'] ?? 0) * 100);
        if ($cents <= 0) {
            return 0.0;
        }
        return round(max(1, (int)floor($cents / $cap)) / 100, 2);
    }

    /**
     * 二倍均值：从剩余金额/剩余包数抽 1 包（单位：分）
     */
    protected static function randomPacketCents($remainCents, $leftSlots)
    {
        $remainCents = max(0, (int)$remainCents);
        $leftSlots = max(1, (int)$leftSlots);
        if ($remainCents <= 0) {
            return 0;
        }
        if ($leftSlots <= 1) {
            return $remainCents;
        }
        if ($remainCents < $leftSlots) {
            return 1;
        }
        $max = max(1, (int)floor(($remainCents / $leftSlots) * 2));
        $max = min($max, $remainCents - ($leftSlots - 1));
        return $max <= 1 ? 1 : random_int(1, $max);
    }

    /**
     * 活动锁下：下一份新资格的随机金额（元）
     * 按「已赋额份数」占坑，未赋额资格不重复扣坑。
     */
    protected static function nextRandomWinAmountLocked(array $act)
    {
        $cap = max(1, (int)($act['global_cap'] ?? 100));
        $poolCents = (int)round((float)($act['pool_amount'] ?? 0) * 100);
        if ($poolCents <= 0) {
            return 0.0;
        }
        $aid = (int)($act['id'] ?? 0);
        $agg = Db::name('fans_fission_qual')
            ->where('activity_id', $aid)
            ->field(
                'COUNT(*) AS cnt,'
                . 'SUM(CASE WHEN win_amount IS NOT NULL AND win_amount>0 THEN 1 ELSE 0 END) AS paid_slots,'
                . 'COALESCE(SUM(CASE WHEN win_amount IS NOT NULL AND win_amount>0 THEN win_amount ELSE 0 END),0) AS paid'
            )
            ->find();
        $paidSlots = (int)($agg['paid_slots'] ?? 0);
        $paidCents = (int)round((float)($agg['paid'] ?? 0) * 100);
        $leftSlots = max(1, $cap - $paidSlots);
        $remainCents = max(0, $poolCents - $paidCents);
        return round(self::randomPacketCents($remainCents, $leftSlots) / 100, 2);
    }

    /**
     * 活动锁下为资格赋随机金额。
     * @param bool $forceResplitUnclaimed 强制重拆所有未领（开奖时用）
     */
    protected static function assignRandomPayoutsLocked(array $act, $forceResplitUnclaimed = false)
    {
        $activityId = (int)($act['id'] ?? 0);
        $cap = max(1, (int)($act['global_cap'] ?? 100));
        $poolCents = (int)round((float)($act['pool_amount'] ?? 0) * 100);
        if ($activityId <= 0 || $poolCents <= 0) {
            return 0;
        }
        $quals = Db::name('fans_fission_qual')
            ->where('activity_id', $activityId)
            ->order('id', 'asc')
            ->lock(true)
            ->select();
        $quals = is_array($quals) ? $quals : $quals->toArray();
        if (!$quals) {
            return 0;
        }

        $unit = self::unitWinAmount($act);
        $unclaimed = [];
        foreach ($quals as $q) {
            if ((int)($q['claimed'] ?? 0) === 1) {
                continue;
            }
            $unclaimed[] = $q;
        }
        $needFill = false;
        $allEven = count($unclaimed) >= 2;
        foreach ($unclaimed as $q) {
            $w = isset($q['win_amount']) && $q['win_amount'] !== null && $q['win_amount'] !== ''
                ? round((float)$q['win_amount'], 2)
                : 0.0;
            if ($w <= 0) {
                $needFill = true;
                $allEven = false;
            } elseif ($unit > 0 && abs($w - $unit) > 0.001) {
                $allEven = false;
            }
        }
        $doResplit = $forceResplitUnclaimed || $needFill || $allEven;
        if (!$doResplit) {
            return 0;
        }

        $paidCents = 0;
        $slot = 0;
        $changed = 0;
        foreach ($quals as $q) {
            $claimed = (int)($q['claimed'] ?? 0) === 1;
            $exist = isset($q['win_amount']) && $q['win_amount'] !== null && $q['win_amount'] !== ''
                ? round((float)$q['win_amount'], 2)
                : 0.0;
            if ($claimed && $exist > 0) {
                $paidCents += (int)round($exist * 100);
                $slot++;
                continue;
            }
            // 未领：随机赋额（含旧均分重拆、空额补发）
            $leftSlots = max(1, $cap - $slot);
            $remainCents = max(0, $poolCents - $paidCents);
            $cents = self::randomPacketCents($remainCents, $leftSlots);
            $amt = round($cents / 100, 2);
            if (abs($exist - $amt) > 0.001 || $exist <= 0) {
                Db::name('fans_fission_qual')->where('id', (int)$q['id'])->update([
                    'win_amount' => $amt,
                ]);
                $changed++;
            }
            $paidCents += $cents;
            $slot++;
        }
        return $changed;
    }

    /**
     * 进入活动页：裂变资格通过「活动开始后邀请新人注册」获得（邀请人与被邀请人各 1 份），参与按钮不再发资格
     */
    public static function join($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            throw new Exception('请先登录');
        }
        self::tickExpire();
        // 兼容旧前端：不再发放 join 资格
        return self::detailPayload($userId);
    }

    /**
     * 邀请绑定成功后：邀请人 +1、被邀请人 +1（各一份）
     * 仅活动开始时间之后的新注册邀请计入
     */
    public static function onInviteBound($inviterUserId, $inviteeUserId)
    {
        $inviterUserId = (int)$inviterUserId;
        $inviteeUserId = (int)$inviteeUserId;
        if ($inviterUserId <= 0 || $inviteeUserId <= 0) {
            return;
        }
        try {
            self::tickExpire();
            $act = self::getRunningActivityRow(false);
            if (!$act) {
                return;
            }
            $now = time();
            $startTs = (int)$act['start_time'];
            // 活动尚未开始：不发资格
            if ($startTs > 0 && $now < $startTs) {
                return;
            }
            // 被邀请人须在活动开始后注册（防止老用户补绑误发）
            if ($startTs > 0) {
                $invitee = User::get($inviteeUserId);
                $regTs = 0;
                if ($invitee) {
                    $regTs = (int)($invitee->jointime ?: $invitee->createtime ?: 0);
                }
                if ($regTs > 0 && $regTs < $startTs) {
                    return;
                }
            }
            $aid = (int)$act['id'];
            // 邀请人：每成功邀请 1 位活动开始后注册的新人 → +1 份资格
            self::grantQualLocked($aid, $inviterUserId, FissionQual::SOURCE_INVITE_REWARD, $inviteeUserId, true);
            // 被邀请人：因本次被邀请注册 → 各得 1 份资格（幂等：同 activity+invitee+source）
            self::grantQualLocked($aid, $inviteeUserId, FissionQual::SOURCE_INVITEE, $inviterUserId, true);
        } catch (\Throwable $e) {
            // 不影响注册主流程
        }
    }

    /**
     * 定时：超时作废 / 满额开奖兜底 / 结束后自动再开下一轮
     */
    public static function maintain()
    {
        $expired = self::tickExpire();
        $settled = 0;
        $rows = Db::name('fans_fission_activity')
            ->where('status', FissionActivity::STATUS_RUNNING)
            ->where('global_quals', '>=', Db::raw('global_cap'))
            ->select();
        foreach ($rows as $row) {
            if (self::settleSuccess((int)$row['id'])) {
                $settled++;
            }
        }
        // settle/expire 内已尝试自动再开；此处兜底（例如上次失败或开关刚打开）
        $restarted = self::tryAutoRestart();
        return ['expired' => $expired, 'settled' => $settled, 'restarted' => $restarted];
    }

    /**
     * 开启新一轮（同时仅允许一条进行中）
     * @param array $opts title/pool_amount/global_cap/user_cap/duration_hours
     * @return int 新活动 id
     */
    public static function startRound(array $opts = [])
    {
        $pool = max(0.01, (float)($opts['pool_amount'] ?? 1000));
        $globalCap = max(1, (int)($opts['global_cap'] ?? 100));
        $userCap = max(1, (int)($opts['user_cap'] ?? 5));
        $hours = max(1, (int)($opts['duration_hours'] ?? 72));
        $title = trim((string)($opts['title'] ?? '全网裂变红宝'));
        if ($title === '') {
            $title = '全网裂变红宝';
        }

        Db::startTrans();
        try {
            $running = Db::name('fans_fission_activity')
                ->where('status', FissionActivity::STATUS_RUNNING)
                ->lock(true)
                ->find();
            if ($running) {
                Db::commit();
                throw new Exception('已有进行中的活动 #' . (int)$running['id']);
            }
            $now = time();
            $id = (int)Db::name('fans_fission_activity')->insertGetId([
                'title'          => $title,
                'pool_amount'    => round($pool, 2),
                'global_cap'     => $globalCap,
                'user_cap'       => $userCap,
                'duration_hours' => $hours,
                'global_quals'   => 0,
                'status'         => FissionActivity::STATUS_RUNNING,
                'start_time'     => $now,
                'end_time'       => $now + $hours * 3600,
                'settled_time'   => 0,
                'createtime'     => $now,
                'updatetime'     => $now,
            ]);
            Db::commit();
            return $id;
        } catch (Exception $e) {
            Db::rollback();
            throw $e;
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 上一轮开奖成功/超时后，自动再开一轮（默认 72 小时=3 天）
     * @return int 新活动 id，未开启则 0
     */
    public static function tryAutoRestart()
    {
        if (!FansHubService::config('fission_auto_restart', true)) {
            return 0;
        }
        $hours = max(1, (int)FansHubService::config('fission_auto_duration_hours', 72));
        try {
            $running = Db::name('fans_fission_activity')
                ->where('status', FissionActivity::STATUS_RUNNING)
                ->find();
            if ($running) {
                return 0;
            }
            $prev = Db::name('fans_fission_activity')
                ->where('status', 'in', [FissionActivity::STATUS_SUCCESS, FissionActivity::STATUS_EXPIRED])
                ->order('id', 'desc')
                ->find();
            if (!$prev) {
                return 0;
            }
            return self::startRound([
                'title'          => (string)($prev['title'] ?? '全网裂变红宝'),
                'pool_amount'    => (float)($prev['pool_amount'] ?? 1000),
                'global_cap'     => (int)($prev['global_cap'] ?? 100),
                'user_cap'       => (int)($prev['user_cap'] ?? 5),
                'duration_hours' => $hours,
            ]);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function tickExpire()
    {
        $now = time();
        $n = Db::name('fans_fission_activity')
            ->where('status', FissionActivity::STATUS_RUNNING)
            ->where('end_time', '>', 0)
            ->where('end_time', '<=', $now)
            ->where('global_quals', '<', Db::raw('global_cap'))
            ->update([
                'status'       => FissionActivity::STATUS_EXPIRED,
                'settled_time' => $now,
                'updatetime'   => $now,
            ]);
        $n = (int)$n;
        if ($n > 0) {
            self::tryAutoRestart();
        }
        return $n;
    }

    /**
     * 满额开奖：随机瓜分奖金池到每一份资格
     * @param bool $force 为 true 时先将进度拉满再派奖（后台一键开奖）
     */
    public static function settleSuccess($activityId, $force = false)
    {
        $activityId = (int)$activityId;
        if ($activityId <= 0) {
            return false;
        }
        Db::startTrans();
        try {
            $act = Db::name('fans_fission_activity')->where('id', $activityId)->lock(true)->find();
            if (!$act || (int)$act['status'] !== FissionActivity::STATUS_RUNNING) {
                Db::commit();
                return false;
            }
            $cap = (int)$act['global_cap'];
            $global = (int)$act['global_quals'];
            if ($global < $cap) {
                if (!$force) {
                    Db::commit();
                    return false;
                }
                // 一键开奖：进度直接拉满
                $nowFill = time();
                Db::name('fans_fission_activity')->where('id', $activityId)->update([
                    'global_quals' => $cap,
                    'updatetime'   => $nowFill,
                ]);
                $global = $cap;
                $act['global_quals'] = $cap;
            }
            $quals = Db::name('fans_fission_qual')
                ->where('activity_id', $activityId)
                ->order('id', 'asc')
                ->lock(true)
                ->select();
            $quals = is_array($quals) ? $quals : $quals->toArray();
            // 只取前 global_cap 份，防止并发超发
            $quals = array_slice($quals, 0, $cap);
            $n = count($quals);
            $now = time();
            if ($n <= 0) {
                Db::name('fans_fission_activity')->where('id', $activityId)->update([
                    'status'       => FissionActivity::STATUS_SUCCESS,
                    'settled_time' => $now,
                    'updatetime'   => $now,
                    'global_quals' => $cap,
                ]);
                Db::commit();
                self::tryAutoRestart();
                return true;
            }
            // 未赋额 / 旧均分未领 → 随机赋额（已随机的保留）
            self::assignRandomPayoutsLocked($act, false);
            Db::name('fans_fission_activity')->where('id', $activityId)->update([
                'status'       => FissionActivity::STATUS_SUCCESS,
                'settled_time' => $now,
                'updatetime'   => $now,
                'global_quals' => $cap,
            ]);
            Db::commit();
            self::tryAutoRestart();
            return true;
        } catch (\Throwable $e) {
            Db::rollback();
            return false;
        }
    }

    /**
     * 后台给指定用户加资格份数
     * - 进行中：写入资格并累加全局进度（可触发满额开奖），默认不校验单人上限
     * - 已开奖：可补发可领取份（需指定每份 win_amount），不改全局进度
     *
     * @param int        $activityId
     * @param int        $userId
     * @param int        $count
     * @param float|null $winAmount  已开奖时每份奖金；进行中传 null
     * @return array{granted:int,activity_id:int,user_id:int,status:int}
     */
    public static function adminGrantQuals($activityId, $userId, $count, $winAmount = null)
    {
        $activityId = (int)$activityId;
        $userId = (int)$userId;
        $count = max(1, min(100, (int)$count));
        if ($activityId <= 0 || $userId <= 0) {
            throw new Exception('活动或用户无效');
        }
        $user = Db::name('user')->where('id', $userId)->find();
        if (!$user) {
            throw new Exception('用户不存在');
        }
        $act = Db::name('fans_fission_activity')->where('id', $activityId)->find();
        if (!$act) {
            throw new Exception('活动不存在');
        }
        $status = (int)$act['status'];
        $granted = 0;

        if ($status === FissionActivity::STATUS_RUNNING) {
            for ($i = 0; $i < $count; $i++) {
                $ok = self::grantQualLocked(
                    $activityId,
                    $userId,
                    FissionQual::SOURCE_ADMIN,
                    0,
                    false
                );
                if (!$ok) {
                    break;
                }
                $granted++;
            }
        } elseif ($status === FissionActivity::STATUS_SUCCESS) {
            $amt = round((float)$winAmount, 2);
            if ($amt <= 0) {
                throw new Exception('已开奖活动补发须指定每份奖金');
            }
            $now = time();
            for ($i = 0; $i < $count; $i++) {
                Db::name('fans_fission_qual')->insert([
                    'activity_id' => $activityId,
                    'user_id'     => $userId,
                    'source'      => FissionQual::SOURCE_ADMIN,
                    'ref_user_id' => 0,
                    'win_amount'  => $amt,
                    'claimed'     => 0,
                    'claimed_at'  => 0,
                    'createtime'  => $now,
                ]);
                $granted++;
            }
        } else {
            throw new Exception('仅进行中或已开奖活动可加份');
        }

        if ($granted <= 0) {
            throw new Exception('未能加份（可能已满额或活动状态已变）');
        }

        return [
            'granted'     => $granted,
            'activity_id' => $activityId,
            'user_id'     => $userId,
            'status'      => $status,
        ];
    }

    /**
     * 在活动行锁下发放 1 份资格；达全局上限则触发开奖
     *
     * @param bool $respectUserCap 是否校验单人上限（join/invite 均应校验）
     */
    protected static function grantQualLocked($activityId, $userId, $source, $refUserId, $respectUserCap = true)
    {
        $activityId = (int)$activityId;
        $userId = (int)$userId;
        $source = (string)$source;
        $refUserId = (int)$refUserId;

        Db::startTrans();
        try {
            $act = Db::name('fans_fission_activity')->where('id', $activityId)->lock(true)->find();
            if (!$act || (int)$act['status'] !== FissionActivity::STATUS_RUNNING) {
                Db::commit();
                return false;
            }
            $now = time();
            if ((int)$act['start_time'] > 0 && (int)$act['start_time'] > $now) {
                Db::commit();
                return false;
            }
            if ((int)$act['end_time'] > 0 && (int)$act['end_time'] <= $now) {
                Db::name('fans_fission_activity')->where('id', $activityId)->update([
                    'status'       => FissionActivity::STATUS_EXPIRED,
                    'settled_time' => $now,
                    'updatetime'   => $now,
                ]);
                Db::commit();
                return false;
            }
            $cap = (int)$act['global_cap'];
            $global = (int)$act['global_quals'];
            if ($global >= $cap) {
                Db::commit();
                // 已满：尝试开奖（事务外）
                self::settleSuccess($activityId);
                return false;
            }

            $userCap = max(1, (int)$act['user_cap']);
            $myCount = (int)Db::name('fans_fission_qual')
                ->where('activity_id', $activityId)
                ->where('user_id', $userId)
                ->count();
            if ($respectUserCap && $myCount >= $userCap) {
                Db::commit();
                return false;
            }

            // 同 source+ref 幂等（防并发双发）
            if ($source === FissionQual::SOURCE_JOIN) {
                $dup = Db::name('fans_fission_qual')
                    ->where('activity_id', $activityId)
                    ->where('user_id', $userId)
                    ->where('source', $source)
                    ->find();
                if ($dup) {
                    Db::commit();
                    return false;
                }
            } elseif (in_array($source, [FissionQual::SOURCE_INVITE_REWARD, FissionQual::SOURCE_INVITEE], true) && $refUserId > 0) {
                $dup = Db::name('fans_fission_qual')
                    ->where('activity_id', $activityId)
                    ->where('user_id', $userId)
                    ->where('source', $source)
                    ->where('ref_user_id', $refUserId)
                    ->find();
                if ($dup) {
                    Db::commit();
                    return false;
                }
            }

            Db::name('fans_fission_qual')->insert([
                'activity_id' => $activityId,
                'user_id'     => $userId,
                'source'      => $source,
                'ref_user_id' => $refUserId,
                'win_amount'  => self::nextRandomWinAmountLocked($act),
                'claimed'     => 0,
                'claimed_at'  => 0,
                'createtime'  => $now,
            ]);
            $newGlobal = $global + 1;
            Db::name('fans_fission_activity')->where('id', $activityId)->update([
                'global_quals' => $newGlobal,
                'updatetime'   => $now,
            ]);
            Db::commit();

            if ($newGlobal >= $cap) {
                self::settleSuccess($activityId);
            }
            return true;
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    protected static function getRunningActivityRow($forJoin = false)
    {
        self::tickExpire();
        $now = time();
        $row = Db::name('fans_fission_activity')
            ->where('status', FissionActivity::STATUS_RUNNING)
            ->where('start_time', '<=', $now)
            ->order('id', 'desc')
            ->find();
        return $row ?: null;
    }

    protected static function latestVisibleActivity()
    {
        $row = Db::name('fans_fission_activity')
            ->where('status', 'in', [
                FissionActivity::STATUS_RUNNING,
                FissionActivity::STATUS_SUCCESS,
                FissionActivity::STATUS_EXPIRED,
            ])
            ->order('id', 'desc')
            ->find();
        return $row ?: null;
    }

    protected static function publicActivityFields(array $act)
    {
        return [
            'id'             => (int)$act['id'],
            'title'          => (string)$act['title'],
            'pool_amount'    => round((float)$act['pool_amount'], 2),
            'global_cap'     => (int)$act['global_cap'],
            'user_cap'       => (int)$act['user_cap'],
            'duration_hours'=> (int)$act['duration_hours'],
            'global_quals'   => (int)$act['global_quals'],
            'status'         => (int)$act['status'],
            'start_time'     => (int)$act['start_time'],
            'end_time'       => (int)$act['end_time'],
            'settled_time'   => (int)($act['settled_time'] ?? 0),
        ];
    }

    /**
     * 有资格即可领取一份红包（入账红宝），无需等人数满
     * 自动再开新一期后，仍可领取上一期未领份
     *
     * @param int $userId
     * @param int $qualId 0=自动取下一份未领（跨期，先旧后新）
     * @return array
     */
    public static function claim($userId, $qualId = 0)
    {
        $userId = (int)$userId;
        $qualId = (int)$qualId;
        if ($userId <= 0) {
            throw new Exception('请先登录');
        }
        self::tickExpire();
        // 领取前先把当前进行中活动的未赋额资格定额发出
        $running = self::getRunningActivityRow(false);
        if ($running) {
            self::ensureQualPayouts((int)$running['id']);
        }

        $q = null;
        $amt = 0.0;
        $aid = 0;
        $claimableStatus = [
            FissionActivity::STATUS_RUNNING,
            FissionActivity::STATUS_SUCCESS,
            FissionActivity::STATUS_EXPIRED,
        ];
        Db::startTrans();
        try {
            if ($qualId > 0) {
                $q = Db::name('fans_fission_qual')
                    ->where('id', $qualId)
                    ->where('user_id', $userId)
                    ->lock(true)
                    ->find();
                if (!$q) {
                    throw new Exception('没有可领取的红包');
                }
                $actRow = Db::name('fans_fission_activity')
                    ->where('id', (int)$q['activity_id'])
                    ->lock(true)
                    ->find();
                if (!$actRow || !in_array((int)$actRow['status'], $claimableStatus, true)) {
                    throw new Exception('活动不可领取');
                }
                // 补发随机金额（兼容旧未赋额数据）
                if (!(round((float)($q['win_amount'] ?? 0), 2) > 0)) {
                    $amtFill = self::nextRandomWinAmountLocked($actRow);
                    if ($amtFill > 0) {
                        Db::name('fans_fission_qual')->where('id', (int)$q['id'])->update(['win_amount' => $amtFill]);
                        $q['win_amount'] = $amtFill;
                    }
                }
            } else {
                // 跨期取最早一份未领（进行中/已开奖/已结束但已赋额均可）
                $pick = Db::name('fans_fission_qual')
                    ->alias('q')
                    ->join('fans_fission_activity a', 'a.id = q.activity_id')
                    ->where('q.user_id', $userId)
                    ->where('q.claimed', 0)
                    ->where('q.win_amount', '>', 0)
                    ->where('a.status', 'in', $claimableStatus)
                    ->field('q.id')
                    ->order('q.id', 'asc')
                    ->find();
                if (!$pick) {
                    throw new Exception('没有可领取的红包');
                }
                $q = Db::name('fans_fission_qual')
                    ->where('id', (int)$pick['id'])
                    ->where('user_id', $userId)
                    ->lock(true)
                    ->find();
                if (!$q) {
                    throw new Exception('没有可领取的红包');
                }
                $actRow = Db::name('fans_fission_activity')
                    ->where('id', (int)$q['activity_id'])
                    ->find();
                if (!$actRow || !in_array((int)$actRow['status'], $claimableStatus, true)) {
                    throw new Exception('活动不可领取');
                }
            }
            if ((int)($q['claimed'] ?? 0) === 1) {
                throw new Exception('该份资格已领取');
            }
            $amt = round((float)($q['win_amount'] ?? 0), 2);
            if ($amt <= 0) {
                throw new Exception('该份资格暂无奖金');
            }
            $aid = (int)$q['activity_id'];
            $now = time();
            $upd = Db::name('fans_fission_qual')
                ->where('id', (int)$q['id'])
                ->where('claimed', 0)
                ->update([
                    'claimed'    => 1,
                    'claimed_at' => $now,
                ]);
            if ($upd <= 0) {
                throw new Exception('领取失败，请重试');
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            if ($e instanceof Exception) {
                throw $e;
            }
            throw new Exception('领取失败');
        }

        try {
            FansHubWallet::creditBalancePublic(
                $userId,
                $amt,
                'fission_reward',
                '裂变红包开奖 #' . $aid . ' 资格' . (int)$q['id'],
                'fission'
            );
        } catch (\Throwable $ePay) {
            try {
                Db::name('fans_fission_qual')->where('id', (int)$q['id'])->update([
                    'claimed'    => 0,
                    'claimed_at' => 0,
                ]);
            } catch (\Throwable $e2) {
            }
            throw new Exception('入账失败，请稍后重试');
        }

        $detail = self::detailPayload($userId);
        return [
            'qual_id'          => (int)$q['id'],
            'amount'           => $amt,
            'remain_unclaimed' => (int)($detail['me']['unclaimed_count'] ?? 0),
            'detail'           => $detail,
        ];
    }

    /** 用户在可领期次上的待领份数（进行中/已开奖/已结束但已赋额） */
    protected static function countUserClaimable($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return 0;
        }
        try {
            $n = Db::name('fans_fission_qual')
                ->alias('q')
                ->join('fans_fission_activity a', 'a.id = q.activity_id')
                ->where('q.user_id', $userId)
                ->where('q.claimed', 0)
                ->where('q.win_amount', '>', 0)
                ->where('a.status', 'in', [
                    FissionActivity::STATUS_RUNNING,
                    FissionActivity::STATUS_SUCCESS,
                    FissionActivity::STATUS_EXPIRED,
                ])
                ->count();
            return (int)$n;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** @deprecated 兼容旧调用 */
    protected static function countUserUnclaimedOnSuccess($userId)
    {
        return self::countUserClaimable($userId);
    }

    protected static function defaultRules()
    {
        return [
            '活动开始后，每成功邀请 1 位新用户注册：邀请人和被邀请人各获得 1 份裂变资格',
            '活动开始前的老下级不计入本次活动资格',
            '获得资格后立即拆红包，无需等待人数满额',
            '点击奖金池可查看领取记录与奖金池余额',
            '超时未集齐的剩余份额留在平台，已获资格仍可领取；邀请下级关系永久保留',
        ];
    }

    /**
     * 二倍均值法拆分红包（单位：分），保证合计精确且每份至少 1 分
     */
    /**
     * 后台「编辑进度」后：把资格条数对齐到目标进度，差额用机器人已领取填充。
     * - 真人资格不动
     * - 单机器人本活动最多领取 $maxPerBot 次（默认 3）
     * - 新记录 claimed_at 沿活动时间轴生成并升序
     * - 进度下调时仅删除机器人 admin 填充记录并冲回红宝
     *
     * @param int      $activityId
     * @param int|null $targetQuals 目标进度（null 则用活动当前 global_quals）
     * @param int      $maxPerBot
     * @return array{ok:bool,added:int,removed:int,message:string}
     */
    public static function syncBotClaimsToProgress($activityId, $targetQuals = null, $maxPerBot = 3)
    {
        $activityId = (int)$activityId;
        $maxPerBot = max(1, (int)$maxPerBot);
        $act = Db::name('fans_fission_activity')->where('id', $activityId)->find();
        if (!$act) {
            return ['ok' => false, 'added' => 0, 'removed' => 0, 'message' => '活动不存在'];
        }
        $cap = max(1, (int)$act['global_cap']);
        $target = $targetQuals === null ? (int)$act['global_quals'] : (int)$targetQuals;
        $target = max(0, min($target, $cap));
        $pool = round((float)$act['pool_amount'], 2);

        $added = 0;
        $removed = 0;

        Db::startTrans();
        try {
            $existing = Db::name('fans_fission_qual')
                ->alias('q')
                ->join('fans_account a', 'a.user_id=q.user_id', 'LEFT')
                ->where('q.activity_id', $activityId)
                ->field('q.*,COALESCE(a.is_bot,0) AS is_bot')
                ->order('q.id', 'asc')
                ->lock(true)
                ->select();
            $existing = is_array($existing) ? $existing : ($existing ? $existing->toArray() : []);
            $existCnt = count($existing);
            $need = $target - $existCnt;

            // 每人在本活动已有份数（含真人；机器人填充也算）
            $claimCounts = [];
            foreach ($existing as $r) {
                $uid = (int)$r['user_id'];
                if ($uid <= 0) {
                    continue;
                }
                $claimCounts[$uid] = ($claimCounts[$uid] ?? 0) + 1;
            }

            if ($need < 0) {
                // 优先删：admin + 机器人 + 已领取
                $removable = [];
                foreach ($existing as $r) {
                    if ((int)$r['is_bot'] !== 1) {
                        continue;
                    }
                    if ((string)$r['source'] !== FissionQual::SOURCE_ADMIN) {
                        continue;
                    }
                    if ((int)$r['claimed'] !== 1) {
                        continue;
                    }
                    $removable[] = $r;
                }
                usort($removable, function ($a, $b) {
                    $ta = (int)($a['claimed_at'] ?: $a['createtime'] ?: 0);
                    $tb = (int)($b['claimed_at'] ?: $b['createtime'] ?: 0);
                    if ($ta !== $tb) {
                        return $tb <=> $ta;
                    }
                    return (int)$b['id'] <=> (int)$a['id'];
                });
                $toRemove = min(count($removable), -$need);
                for ($i = 0; $i < $toRemove; $i++) {
                    $r = $removable[$i];
                    $uid = (int)$r['user_id'];
                    $amt = round((float)$r['win_amount'], 2);
                    if ($amt > 0) {
                        FansHubService::changeAssets(
                            $uid,
                            0,
                            0,
                            'fission_reward',
                            '裂变进度下调冲回机器人领取 #' . $activityId . ' qual#' . $r['id'],
                            0,
                            'fission_bot_fill_revoke',
                            -$amt
                        );
                    }
                    Db::name('fans_fission_qual')->where('id', (int)$r['id'])->delete();
                    $removed++;
                }
                if ($existCnt - $removed > $target) {
                    throw new Exception(
                        '进度下调失败：可删除的机器人填充不足（真人资格不会删），当前仍多 '
                        . ($existCnt - $removed - $target) . ' 份'
                    );
                }
            } elseif ($need > 0) {
                $humanSum = 0.0;
                $allSum = 0.0;
                $botRows = [];
                foreach ($existing as $r) {
                    $amt = round((float)$r['win_amount'], 2);
                    $allSum = round($allSum + $amt, 2);
                    if ((int)$r['is_bot'] === 1) {
                        $botRows[] = $r;
                    } else {
                        $humanSum = round($humanSum + $amt, 2);
                    }
                }
                $remainCents = (int)round(max(0, $pool - $allSum) * 100);

                // 可选机器人：本活动领取次数 < maxPerBot
                $bots = Db::name('fans_account')
                    ->alias('a')
                    ->join('user u', 'u.id=a.user_id')
                    ->where('a.is_bot', 1)
                    ->where('a.status', 'normal')
                    ->where('u.status', 'normal')
                    ->field('a.user_id,u.nickname')
                    ->orderRaw('RAND()')
                    ->select();
                $bots = is_array($bots) ? $bots : ($bots ? $bots->toArray() : []);
                $eligible = [];
                foreach ($bots as $b) {
                    $uid = (int)$b['user_id'];
                    $used = (int)($claimCounts[$uid] ?? 0);
                    $left = $maxPerBot - $used;
                    for ($k = 0; $k < $left; $k++) {
                        $eligible[] = $b;
                    }
                }
                shuffle($eligible);
                if (count($eligible) < $need) {
                    throw new Exception(
                        '可用机器人领取次数不足（单机最多 ' . $maxPerBot . ' 次），还差 '
                        . ($need - count($eligible)) . ' 份'
                    );
                }

                $newParts = [];
                $slotsLeftToCap = max(1, $cap - $existCnt);
                $poolCents = (int)round($pool * 100);
                $humanCents = (int)round($humanSum * 100);
                $humanCnt = $existCnt - count($botRows);
                $minReserveFen = max(0, $cap - ($existCnt + $need)); // 未发出份至少 1 分
                $maxAssignable = max(0, $poolCents - $minReserveFen);
                $overspent = $remainCents < $need || ((int)round($allSum * 100) > $maxAssignable);

                if ($overspent && (count($botRows) > 0 || $need > 0)) {
                    // 池子不够或历史超额：真人不动，把「非真人份额」按总份数二倍均值重拆
                    // 例：1000/100 份 → 先拆成 100 包，已有机器人拿其中对应份，绝不是「7 份就固定 70」
                    $nonHumanSlots = max(1, $cap - $humanCnt);
                    $budget = max(0, $poolCents - $humanCents);
                    $budget = min($budget, max(0, $poolCents - $humanCents - $minReserveFen));
                    $allParts = self::splitPoolCents(max($nonHumanSlots, $budget), $nonHumanSlots);
                    $nOld = count($botRows);
                    for ($i = 0; $i < $nOld; $i++) {
                        $old = round((float)$botRows[$i]['win_amount'], 2);
                        $newAmt = round(($allParts[$i] ?? 0) / 100, 2);
                        $delta = round($newAmt - $old, 2);
                        if (abs($delta) > 1e-8) {
                            self::softAdjustBotHongbao(
                                (int)$botRows[$i]['user_id'],
                                $delta,
                                '裂变红包按总份数随机重拆 #' . $activityId . ' qual#' . $botRows[$i]['id']
                            );
                            Db::name('fans_fission_qual')->where('id', (int)$botRows[$i]['id'])->update([
                                'win_amount' => $newAmt,
                            ]);
                        }
                    }
                    for ($i = 0; $i < $need; $i++) {
                        $newParts[] = (int)($allParts[$nOld + $i] ?? 0);
                    }
                } else {
                    // 正常：剩余金额按「剩余至 cap 的份数」二倍均值随机，只取本次 need 份
                    $fullParts = self::splitPoolCents($remainCents, $slotsLeftToCap);
                    $newParts = array_slice($fullParts, 0, $need);
                }

                $startTs = (int)$act['start_time'];
                $nowTs = time();
                $actEnd = (int)$act['end_time'] ?: $nowTs;
                // 领取时间只能落在「开始 → 当前」，绝不能写到活动结束日的未来时刻
                $endTs = min($nowTs, $actEnd);
                if ($endTs <= $startTs + 60) {
                    $endTs = $nowTs;
                    $startTs = max(0, min($startTs, $nowTs - 600));
                }
                if ($endTs <= $startTs + 30) {
                    $startTs = max(0, $endTs - 600);
                }
                $times = [];
                for ($i = 0; $i < $need; $i++) {
                    $span = max(1, $endTs - $startTs);
                    $t = (int)round($startTs + $span * (($i + 0.5) / $need));
                    $t += random_int(-120, 180);
                    $t = max($startTs + 30, min($endTs - 1, $t));
                    $t = min($t, $nowTs - 1);
                    $times[] = $t;
                }
                sort($times);

                for ($i = 0; $i < $need; $i++) {
                    $bot = $eligible[$i];
                    $uid = (int)$bot['user_id'];
                    $amt = round(($newParts[$i] ?? 0) / 100, 2);
                    $claimedAt = $times[$i];
                    $ct = max($startTs + 10, $claimedAt - random_int(20, 90));
                    $qid = (int)Db::name('fans_fission_qual')->insertGetId([
                        'activity_id' => $activityId,
                        'user_id'     => $uid,
                        'source'      => FissionQual::SOURCE_ADMIN,
                        'ref_user_id' => 0,
                        'win_amount'  => $amt,
                        'claimed'     => 1,
                        'claimed_at'  => $claimedAt,
                        'createtime'  => $ct,
                    ]);
                    if ($amt > 0) {
                        FansHubService::changeAssets(
                            $uid,
                            0,
                            0,
                            'fission_reward',
                            '裂变红包机器人填充 #' . $activityId . ' qual#' . $qid,
                            0,
                            'fission_bot_fill',
                            $amt
                        );
                    }
                    $claimCounts[$uid] = ($claimCounts[$uid] ?? 0) + 1;
                    $added++;
                }
            }

            // 仅当超额占用奖池时，按「总份数二倍均值」重拆机器人（不做「进度×均价」）
            self::rebalanceBotWinAmountsLocked($activityId, $act, $target);
            // 校正误写入的未来领取时间（例如按活动 end_time 排到了明天）
            self::clampFutureClaimedAtLocked($activityId);

            Db::name('fans_fission_activity')->where('id', $activityId)->update([
                'global_quals' => $target,
                'updatetime'   => time(),
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return [
                'ok'      => false,
                'added'   => 0,
                'removed' => 0,
                'message' => $e->getMessage() ?: '机器人领取同步失败',
            ];
        }

        $msg = '进度已保存为 ' . $target;
        if ($added > 0) {
            $msg .= '，已补 ' . $added . ' 份机器人领取（单机最多 ' . $maxPerBot . ' 次）';
        }
        if ($removed > 0) {
            $msg .= '，已删 ' . $removed . ' 份机器人填充';
        }
        if ($added === 0 && $removed === 0) {
            $msg .= '（资格条数已对齐，无需增减机器人）';
        }
        return ['ok' => true, 'added' => $added, 'removed' => $removed, 'message' => $msg];
    }

    /**
     * 活动锁内：把误写入「未来」的领取时间拉回到 [活动开始, now)。
     */
    protected static function clampFutureClaimedAtLocked($activityId)
    {
        $activityId = (int)$activityId;
        $now = time();
        if ($activityId <= 0) {
            return 0;
        }
        $act = Db::name('fans_fission_activity')->where('id', $activityId)->find();
        if (!$act) {
            return 0;
        }
        $startTs = max(0, (int)$act['start_time']);
        $rows = Db::name('fans_fission_qual')
            ->where('activity_id', $activityId)
            ->where('claimed', 1)
            ->where('claimed_at', '>', $now)
            ->order('id', 'asc')
            ->lock(true)
            ->select();
        $rows = is_array($rows) ? $rows : ($rows ? $rows->toArray() : []);
        if (!$rows) {
            return 0;
        }
        $n = count($rows);
        $spanStart = max($startTs + 30, $now - max(3600, $n * 180));
        $spanStart = min($spanStart, $now - max(60, $n));
        $changed = 0;
        for ($i = 0; $i < $n; $i++) {
            $t = (int)round($spanStart + ($now - $spanStart) * (($i + 0.5) / $n));
            $t = max($spanStart, min($now - 1, $t));
            $ct = max($startTs + 10, $t - random_int(20, 90));
            Db::name('fans_fission_qual')->where('id', (int)$rows[$i]['id'])->update([
                'claimed_at' => $t,
                'createtime' => min((int)$rows[$i]['createtime'] ?: $ct, $t),
            ]);
            $changed++;
        }
        return $changed;
    }

    /**
     * 机器人金额冲正：增加照常入账；扣减时余额不足则尽量扣，不抛错。
     */
    protected static function softAdjustBotHongbao($userId, $delta, $remark)
    {
        $userId = (int)$userId;
        $delta = round((float)$delta, 2);
        if ($userId <= 0 || abs($delta) <= 1e-8) {
            return;
        }
        try {
            if ($delta > 0) {
                FansHubService::changeAssets(
                    $userId,
                    0,
                    0,
                    'fission_reward',
                    $remark,
                    0,
                    'fission_bot_fill_rebalance',
                    $delta
                );
                return;
            }
            $acc = FansHubService::getOrCreateAccount($userId);
            $have = round((float)($acc->hongbao ?? 0), 2);
            $take = round(min(abs($delta), max(0, $have)), 2);
            if ($take <= 1e-8) {
                return;
            }
            FansHubService::changeAssets(
                $userId,
                0,
                0,
                'fission_reward',
                $remark,
                0,
                'fission_bot_fill_rebalance',
                -$take
            );
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /**
     * 活动锁内：真人金额不动。
     * 仅当已分配总额超过「池子 − 未发出份保底」时，把非真人份额按 global_cap 做二倍均值随机重拆。
     * 注意：不是「当前 N 份就固定 N/cap×池子」（避免 7 份≈70 元的假随机）。
     */
    protected static function rebalanceBotWinAmountsLocked($activityId, array $act, $currentCnt = null)
    {
        $activityId = (int)$activityId;
        $cap = max(1, (int)($act['global_cap'] ?? 100));
        $pool = round((float)($act['pool_amount'] ?? 0), 2);
        $poolCents = (int)round($pool * 100);
        if ($activityId <= 0 || $poolCents <= 0) {
            return 0;
        }

        $rows = Db::name('fans_fission_qual')
            ->alias('q')
            ->join('fans_account a', 'a.user_id=q.user_id', 'LEFT')
            ->where('q.activity_id', $activityId)
            ->field('q.*,COALESCE(a.is_bot,0) AS is_bot')
            ->order('q.id', 'asc')
            ->lock(true)
            ->select();
        $rows = is_array($rows) ? $rows : ($rows ? $rows->toArray() : []);
        $existCnt = count($rows);
        if ($existCnt <= 0) {
            return 0;
        }
        $cnt = $currentCnt === null ? $existCnt : max(0, min((int)$currentCnt, $cap));
        $cnt = min($cnt, $existCnt);

        $humanSum = 0.0;
        $botRows = [];
        $allSum = 0.0;
        foreach ($rows as $r) {
            $amt = round((float)($r['win_amount'] ?? 0), 2);
            $allSum = round($allSum + $amt, 2);
            if ((int)($r['is_bot'] ?? 0) === 1) {
                $botRows[] = $r;
            } else {
                $humanSum = round($humanSum + $amt, 2);
            }
        }
        if (!$botRows) {
            return 0;
        }

        $minReserve = max(0, $cap - $cnt); // 未发出份各留至少 1 分
        $maxAssignableYuan = round(max(0, $poolCents - $minReserve) / 100, 2);
        // 未超额则不动：保留历史上二倍均值的真实随机结果
        if ($allSum <= $maxAssignableYuan + 0.009) {
            return 0;
        }

        $humanCnt = $existCnt - count($botRows);
        $humanCents = (int)round($humanSum * 100);
        $nonHumanSlots = max(1, $cap - $humanCnt);
        $budget = max(count($botRows), $poolCents - $humanCents - $minReserve);
        $budget = min($budget, max(0, $poolCents - $humanCents));
        // 按总份数拆非真人包，现有机器人只取前 N 份（其余留给未发出份）
        $allParts = self::splitPoolCents(max($nonHumanSlots, $budget), $nonHumanSlots);
        $changed = 0;
        foreach ($botRows as $i => $r) {
            $old = round((float)$r['win_amount'], 2);
            $newAmt = round(($allParts[$i] ?? 0) / 100, 2);
            $delta = round($newAmt - $old, 2);
            if (abs($delta) <= 1e-8) {
                continue;
            }
            if ((int)($r['claimed'] ?? 0) === 1) {
                self::softAdjustBotHongbao(
                    (int)$r['user_id'],
                    $delta,
                    '裂变红包按总份数随机重拆 #' . $activityId . ' qual#' . $r['id']
                );
            }
            Db::name('fans_fission_qual')->where('id', (int)$r['id'])->update([
                'win_amount' => $newAmt,
            ]);
            $changed++;
        }
        return $changed;
    }

    /**
     * 强制按「池子/总份数」二倍均值重拆机器人金额（真人不动）。
     * 用于纠正历史「进度×均价」假随机。
     */
    public static function forceResplitBotAmountsWeChat($activityId)
    {
        $activityId = (int)$activityId;
        $act = Db::name('fans_fission_activity')->where('id', $activityId)->find();
        if (!$act) {
            return ['ok' => false, 'changed' => 0, 'message' => '活动不存在'];
        }
        $changed = 0;
        Db::startTrans();
        try {
            $cap = max(1, (int)$act['global_cap']);
            $poolCents = (int)round((float)$act['pool_amount'] * 100);
            $rows = Db::name('fans_fission_qual')
                ->alias('q')
                ->join('fans_account a', 'a.user_id=q.user_id', 'LEFT')
                ->where('q.activity_id', $activityId)
                ->field('q.*,COALESCE(a.is_bot,0) AS is_bot')
                ->order('q.id', 'asc')
                ->lock(true)
                ->select();
            $rows = is_array($rows) ? $rows : ($rows ? $rows->toArray() : []);
            $humanSum = 0.0;
            $botRows = [];
            foreach ($rows as $r) {
                if ((int)($r['is_bot'] ?? 0) === 1) {
                    $botRows[] = $r;
                } else {
                    $humanSum = round($humanSum + (float)($r['win_amount'] ?? 0), 2);
                }
            }
            if (!$botRows) {
                Db::commit();
                return ['ok' => true, 'changed' => 0, 'message' => '无机器人份可重拆'];
            }
            $humanCnt = count($rows) - count($botRows);
            $humanCents = (int)round($humanSum * 100);
            $minReserve = max(0, $cap - count($rows));
            $nonHumanSlots = max(1, $cap - $humanCnt);
            $budget = max(0, $poolCents - $humanCents - $minReserve);
            $allParts = self::splitPoolCents(max($nonHumanSlots, $budget), $nonHumanSlots);
            foreach ($botRows as $i => $r) {
                $old = round((float)$r['win_amount'], 2);
                $newAmt = round(($allParts[$i] ?? 0) / 100, 2);
                $delta = round($newAmt - $old, 2);
                if (abs($delta) <= 1e-8) {
                    continue;
                }
                if ((int)($r['claimed'] ?? 0) === 1) {
                    self::softAdjustBotHongbao(
                        (int)$r['user_id'],
                        $delta,
                        '裂变强制随机重拆 #' . $activityId . ' qual#' . $r['id']
                    );
                }
                Db::name('fans_fission_qual')->where('id', (int)$r['id'])->update([
                    'win_amount' => $newAmt,
                ]);
                $changed++;
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return ['ok' => false, 'changed' => 0, 'message' => $e->getMessage() ?: '重拆失败'];
        }
        return ['ok' => true, 'changed' => $changed, 'message' => '已按总份数随机重拆 ' . $changed . ' 份机器人'];
    }

    protected static function splitPoolCents($totalCents, $n)
    {
        $totalCents = max(0, (int)$totalCents);
        $n = max(1, (int)$n);
        if ($totalCents < $n) {
            // 不足人均 1 分：前 total 份各 1 分
            $out = array_fill(0, $n, 0);
            for ($i = 0; $i < $totalCents; $i++) {
                $out[$i] = 1;
            }
            return $out;
        }
        $remain = $totalCents;
        $out = [];
        for ($i = 0; $i < $n - 1; $i++) {
            $left = $n - $i;
            $max = max(1, (int)floor(($remain / $left) * 2));
            $max = min($max, $remain - ($left - 1));
            $amt = $max <= 1 ? 1 : random_int(1, $max);
            $out[] = $amt;
            $remain -= $amt;
        }
        $out[] = $remain;
        shuffle($out);
        return $out;
    }
}
