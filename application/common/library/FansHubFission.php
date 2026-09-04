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
        $summary = self::poolSummary($aid, $act);
        $rows = Db::name('fans_fission_qual')
            ->alias('q')
            ->join('user u', 'u.id = q.user_id', 'LEFT')
            ->where('q.activity_id', $aid)
            ->field('q.id,q.user_id,q.win_amount,q.claimed,q.claimed_at,q.createtime,q.source,u.nickname,u.avatar')
            ->order('q.claimed desc,q.claimed_at desc,q.id asc')
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
     * 把尚未赋额的资格按「奖金池 / 全局上限」定额发出（无人领取也可见记录）
     */
    public static function ensureQualPayouts($activityId)
    {
        $activityId = (int)$activityId;
        if ($activityId <= 0) {
            return 0;
        }
        $act = Db::name('fans_fission_activity')->where('id', $activityId)->find();
        if (!$act) {
            return 0;
        }
        $unit = self::unitWinAmount($act);
        if ($unit <= 0) {
            return 0;
        }
        try {
            $n1 = (int)Db::name('fans_fission_qual')
                ->where('activity_id', $activityId)
                ->whereNull('win_amount')
                ->update(['win_amount' => $unit]);
            $n2 = (int)Db::name('fans_fission_qual')
                ->where('activity_id', $activityId)
                ->where('win_amount', '<=', 0)
                ->update(['win_amount' => $unit]);
            return $n1 + $n2;
        } catch (\Throwable $e) {
            return 0;
        }
    }

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
            // 已提前赋额的资格保留原金额；仅给尚未赋额的补定额（兼容旧数据）
            $unit = self::unitWinAmount($act);
            foreach ($quals as $q) {
                $exist = isset($q['win_amount']) && $q['win_amount'] !== null && $q['win_amount'] !== ''
                    ? round((float)$q['win_amount'], 2)
                    : 0.0;
                if ($exist > 0) {
                    continue;
                }
                Db::name('fans_fission_qual')->where('id', (int)$q['id'])->update([
                    'win_amount' => $unit,
                    'claimed'    => 0,
                    'claimed_at' => 0,
                ]);
            }
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
                'win_amount'  => self::unitWinAmount($act),
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
                // 补发定额（兼容旧未赋额数据）
                if (!(round((float)($q['win_amount'] ?? 0), 2) > 0)) {
                    $unit = self::unitWinAmount($actRow);
                    if ($unit > 0) {
                        Db::name('fans_fission_qual')->where('id', (int)$q['id'])->update(['win_amount' => $unit]);
                        $q['win_amount'] = $unit;
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
