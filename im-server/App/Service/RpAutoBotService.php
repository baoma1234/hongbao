<?php

namespace Im\Service;

use Im\Support\CatchLog;

use Im\Support\Db;
use Im\Support\PushBus;
use Im\Support\RedPacketUpdateBus;
use Im\Support\RedisClient;
use Workerman\Timer;

/**
 * 红包自动发/抢（Workerman worker0 内跑，替代 php think redpacket:auto）
 *
 * 规则：
 * - 发包：群内无待领取包、且无接龙待续发时才发；多 send_user_ids 随机选一个
 * - 时间窗突发：burst_window_sec>0 时窗内最多 burst_count 包、随机节奏；否则用 interval_sec
 * - 埋雷：雷号每发随机 0-9；个数每发随机 5/7/9；金额须为 10 的整数倍
 * - 节奏：20–23 点发包间隔减半；0–7 点翻倍（仅固定间隔模式）
 * - 接龙续发：由结算/cron 全局监听「全部群」的全部 type5，抢完后最少者名义发下一包（见 RedPacketService::trySendRobotNextRound）
 * - 抢包：仅后台配置了 auto_grab 的任务才抢（mode1 需 grab_user_ids；mode2 用机器人账户）
 * - 抢包延迟：对每个待领包独立随机 delay，多包可同时排 Timer 并行抢（不串行等上一包）
 * - actor_mode：1=发包/抢包 UID 池；2=从 is_bot=1 机器人账户随机发/抢
 * - 多 UID：每个包随机选一个尚未领过该包的 UID 去抢（含拼手气/埋雷/接龙）
 */
class RpAutoBotService
{
    const HEARTBEAT_KEY = 'rp_auto:ws_active';
    const TASK_LOCK_PREFIX = 'rp_auto:lock:';
    /** 单包抢包占坑：rp_auto:grab_busy:{taskId}:{packetId} */
    const GRAB_BUSY_PREFIX = 'rp_auto:grab_busy:';
    const TASK_CACHE_TTL = 8;
    /** 真人优先：新包发出后机器人至少等待秒数 */
    const HUMAN_FIRST_MIN_SEC = 8;
    const HUMAN_FIRST_MAX_SEC = 12;
    /** 后台未配或配错时的硬底（秒）；允许最短约 1s */
    const GRAB_DELAY_FLOOR_SEC = 1;

    /** @var RedPacketService */
    protected $redPackets;
    /** @var GroupService */
    protected $groups;
    /** @var array<int,array>|null */
    protected $taskCache;
    /** @var int */
    protected $taskCacheAt = 0;

    /** @var array<string,int> 跳过类日志节流：key → lastTs */
    protected $skipLogAt = [];

    public function __construct(RedPacketService $redPackets, GroupService $groups)
    {
        $this->redPackets = $redPackets;
        $this->groups = $groups;
    }

    /** 供 CLI/后台探测：WS 机器人是否在跑 */
    public static function isWsActive()
    {
        try {
            $v = RedisClient::conn()->get(RedisClient::key(self::HEARTBEAT_KEY));
            return $v !== false && $v !== null && $v !== '';
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function heartbeat()
    {
        try {
            RedisClient::conn()->setex(RedisClient::key(self::HEARTBEAT_KEY), 30, (string)time());
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.RpAutoBotService');
        }
    }

    public function tick()
    {
        $this->heartbeat();
        $tasks = $this->loadTasks();
        // 常规 tick 不刷盘；仅任务数为 0 时提示（每分钟一次）
        if (!$tasks) {
            $this->taskLogThrottled(0, 'idle', 'info', 'tick: no enabled tasks', [], 60);
        }
        foreach ($tasks as $task) {
            $taskId = (int)($task['id'] ?? 0);
            if ($taskId <= 0) {
                continue;
            }
            if (!$this->tryLock($taskId, 4)) {
                $this->taskLogThrottled($taskId, 'lock', 'skip', 'lock busy, skip this tick', [], 30);
                continue;
            }
            try {
                $this->runOne($task);
            } catch (\Throwable $e) {
                $this->touchError($taskId, $e->getMessage());
                $this->taskLog($taskId, 'error', 'runOne exception: ' . $e->getMessage(), [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            } finally {
                $this->unlock($taskId);
            }
        }
    }

    protected function runOne(array $task)
    {
        $taskId = (int)$task['id'];
        $groupId = (int)$task['group_id'];
        if ($groupId <= 0) {
            $this->taskLog($taskId, 'skip', 'invalid group_id');
            return;
        }

        $today = date('Y-m-d');
        if ((string)($task['today_date'] ?? '') !== $today) {
            Db::exec(
                'UPDATE ' . Db::table('chat_rp_auto_task')
                . ' SET today_date=?, today_count=0, updatetime=? WHERE id=?',
                [$today, time(), $taskId]
            );
            $task['today_count'] = 0;
            $task['today_date'] = $today;
            $this->taskLog($taskId, 'info', 'reset today_count for ' . $today);
        }

        $packetId = 0;
        $sendUid = 0;
        if ((int)($task['auto_send'] ?? 0) === 1) {
            $sent = $this->maybeSend($task);
            if (!empty($sent['sent'])) {
                $packetId = (int)$sent['packet_id'];
                $sendUid = (int)($sent['send_uid'] ?? 0);
            }
        }

        if ((int)($task['auto_grab'] ?? 0) === 1) {
            // 机器人刚发出的包：发包人立刻领自己的一份（余额不够冻总额时走 robot_self_grab）
            if ($packetId > 0 && $sendUid > 0) {
                $this->trySenderSelfGrab($taskId, $packetId, $sendUid, $groupId, 0);
            }
            $this->maybeScheduleGrab($task, $packetId);
        }
    }

    /**
     * 发包机器人立刻自领；埋雷等哈希未就绪时短间隔重试。
     */
    protected function trySenderSelfGrab($taskId, $packetId, $sendUid, $groupId, $attempt = 0)
    {
        $taskId = (int)$taskId;
        $packetId = (int)$packetId;
        $sendUid = (int)$sendUid;
        $groupId = (int)$groupId;
        $attempt = (int)$attempt;
        if ($packetId <= 0 || $sendUid <= 0) {
            return;
        }
        try {
            $ok = $this->doGrabOnce($taskId, $packetId, $sendUid, $groupId, ['robot_self_grab' => true]);
            $this->taskLog($taskId, $ok ? 'ok' : 'skip', 'sender self-grab', [
                'packet_id' => $packetId,
                'uid'       => $sendUid,
                'attempt'   => $attempt,
            ]);
            if ($ok) {
                return;
            }
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $this->taskLog($taskId, 'error', 'sender self-grab: ' . $msg, [
                'packet_id' => $packetId,
                'uid'       => $sendUid,
                'attempt'   => $attempt,
            ]);
            // 非软错误不重试
            if (!$this->isSoftGrabError($msg)) {
                return;
            }
        }

        // 已领 / 包已空：不再重试
        $exists = Db::fetch(
            'SELECT id FROM ' . Db::table('chat_red_packet_records')
            . ' WHERE packet_id=? AND user_id=? LIMIT 1',
            [$packetId, $sendUid]
        );
        if ($exists) {
            return;
        }
        $open = Db::fetch(
            'SELECT id, status, remain_count, packet_type, tron_status FROM ' . Db::table('chat_red_packets')
            . ' WHERE id=? LIMIT 1',
            [$packetId]
        );
        if (!$open || (int)$open['status'] !== 1 || (int)$open['remain_count'] <= 0) {
            return;
        }
        if ($attempt >= 15) {
            return;
        }
        // 埋雷等哈希 / 瞬时失败：1s 后重试
        Timer::add(1.0, function () use ($taskId, $packetId, $sendUid, $groupId, $attempt) {
            try {
                $this->trySenderSelfGrab($taskId, $packetId, $sendUid, $groupId, $attempt + 1);
            } catch (\Throwable $e) {
                CatchLog::quiet($e, 'Service.RpAutoBotService');
            }
        }, [], false);
    }

    /**
     * 仅当群内没有待领取包时发包（拼手气/埋雷/接龙通用）
     * - 多发包 UID：随机选一个
     * - 时间窗突发：burst_window_sec>0 时，窗内最多 burst_count 包、随机节奏
     */
    protected function maybeSend(array $task)
    {
        $taskId = (int)$task['id'];
        $groupId = (int)$task['group_id'];
        $now = time();

        $burstWindow = (int)($task['burst_window_sec'] ?? 0);
        $burstCount = max(1, (int)($task['burst_count'] ?? 1));
        $useBurst = ($burstWindow > 0 && $burstCount > 0);

        if ($useBurst) {
            $gate = $this->burstGate($task, $now);
            if (!$gate['ok']) {
                $this->taskLogThrottled($taskId, 'burst', 'skip', 'burst gate closed', [
                    'burst_sent'    => (int)($gate['task']['burst_sent'] ?? 0),
                    'burst_count'   => $burstCount,
                    'burst_next_at' => (int)($gate['task']['burst_next_at'] ?? 0),
                    'window_sec'    => $burstWindow,
                ], 30);
                return ['sent' => false, 'packet_id' => 0];
            }
            $task = $gate['task'];
        } else {
            $interval = $this->effectiveIntervalSec(
                (int)($task['interval_sec'] ?? 60),
                $task['interval_windows'] ?? null
            );
            $last = (int)($task['last_send_time'] ?? 0);
            if ($last > 0 && ($now - $last) < $interval) {
                $this->taskLogThrottled($taskId, 'interval', 'skip', 'interval not reached', [
                    'last_send' => $last,
                    'wait_sec'  => $interval - ($now - $last),
                    'interval'  => $interval,
                ], 45);
                return ['sent' => false, 'packet_id' => 0];
            }
        }

        $maxDay = (int)($task['max_per_day'] ?? 0);
        if ($maxDay > 0 && (int)($task['today_count'] ?? 0) >= $maxDay) {
            $this->taskLog($taskId, 'skip', 'max_per_day reached', [
                'today_count' => (int)$task['today_count'],
                'max_per_day' => $maxDay,
            ]);
            return ['sent' => false, 'packet_id' => 0];
        }

        $group = $this->groups->get($groupId) ?: [];
        // 1普通 2拼手气 3扫雷 5接龙（接龙群常见仅开放 type=5）
        $packetType = (int)($task['packet_type'] ?? 2);
        if (!in_array($packetType, [1, 2, 3, 5], true)) {
            $packetType = 2;
        }
        // 任务类型与群允许玩法不一致时：若群仅开放一种玩法则自动对齐
        $enabled = array_values(array_filter(array_map(
            'intval',
            explode(',', (string)($group['rp_enabled_types'] ?? ''))
        )));
        if ($enabled && !in_array($packetType, $enabled, true)) {
            if (count($enabled) === 1) {
                $packetType = (int)$enabled[0];
            } else {
                throw new \RuntimeException(
                    '任务玩法 type=' . (int)($task['packet_type'] ?? 0)
                    . ' 不在本群允许类型 [' . implode(',', $enabled) . '] 内'
                );
            }
        }

        $isRelay = ($packetType === 5);
        // 持续发：有未领完也可发；接龙群/接龙任务强制保持原样（有忙包或待续发则不发）
        $continuous = !$isRelay && (int)($task['continuous_send'] ?? 0) === 1;
        if (!$continuous) {
            $openCnt = $this->countBusyPackets($groupId);
            if ($openCnt > 0) {
                $this->taskLogThrottled($taskId, 'open', 'skip', 'group has open/settling packets', [
                    'group_id' => $groupId,
                    'open'     => $openCnt,
                    'relay'    => $isRelay ? 1 : 0,
                ], 30);
                return ['sent' => false, 'packet_id' => 0];
            }
        }
        // 接龙：上一包已抢完、正等「最少者」续发时，禁止机器人插队发包
        if ($isRelay && $this->hasPendingRelay($groupId)) {
            $this->taskLogThrottled($taskId, 'relay', 'skip', 'pending relay next round', [
                'group_id' => $groupId,
            ], 30);
            return ['sent' => false, 'packet_id' => 0];
        }

        $sendUid = $this->pickSendUserId($task);
        if ($sendUid <= 0) {
            throw new \RuntimeException('未配置发包用户ID');
        }
        $amountPlan = $this->resolveSendAmountPlan($task, $group, $packetType);
        $amount = (float)($amountPlan['amount'] ?? 0);
        $count = $this->resolveSendCount($task, $group, $packetType);
        if ($amount <= 0 || $count <= 0) {
            throw new \RuntimeException('金额/个数无效');
        }
        // 埋雷：每发随机 0-9，不读后台固定雷号
        $mineDigit = ($packetType === 3) ? random_int(0, 9) : 0;

        $this->ensureSenderInGroup($groupId, $sendUid);

        $result = $this->redPackets->send([
            'from_user_id' => $sendUid,
            'scope_type'   => 2,
            'group_id'     => $groupId,
            'packet_type'  => $packetType,
            'total_amount' => $amount,
            'total_count'  => $count,
            'blessing'     => (string)(($task['blessing'] ?? '') !== '' ? $task['blessing'] : '恭喜发财'),
            'mine_digit'   => $mineDigit,
            'robot_send'   => true,
            'trusted_robot'=> true,
        ]);

        $msg = $result['message'] ?? null;
        $packetId = (int)($result['packet_id'] ?? ($result['packet']['id'] ?? 0));
        if ($packetId <= 0 && is_array($msg) && !empty($msg['extra'])) {
            $extra = $msg['extra'];
            if (is_string($extra)) {
                $extra = json_decode($extra, true) ?: [];
            }
            $packetId = (int)($extra['packet_id'] ?? 0);
        }

        if (is_array($msg)) {
            try {
                PushBus::toGroup($groupId, 'group.message', ['message' => $msg]);
            } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.RpAutoBotService');
        }
        }

        $burstSent = (int)($task['burst_sent'] ?? 0) + 1;
        $burstNext = 0;
        if ($useBurst) {
            $burstNext = $this->planNextBurstAt($task, $now, $burstSent);
        }

        $mode2Count = (int)($amountPlan['next_mode2_count'] ?? (int)($task['amount_mode2_count'] ?? 0));
        $mode2Target = (int)($amountPlan['next_mode2_target'] ?? (int)($task['amount_mode2_target'] ?? 0));

        Db::exec(
            'UPDATE ' . Db::table('chat_rp_auto_task')
            . ' SET last_send_time=?, last_packet_id=?, today_count=today_count+1,'
            . ' burst_window_start=?, burst_sent=?, burst_next_at=?,'
            . ' amount_mode2_count=?, amount_mode2_target=?,'
            . ' last_error=?, updatetime=? WHERE id=?',
            [
                $now,
                $packetId,
                (int)($task['burst_window_start'] ?? ($useBurst ? $now : 0)),
                $useBurst ? $burstSent : 0,
                $burstNext,
                $mode2Count,
                $mode2Target,
                '',
                $now,
                $taskId,
            ]
        );
        $this->bustTaskCache();

        $this->taskLog($taskId, 'ok', 'send ok', [
            'group_id'    => $groupId,
            'packet_type' => $packetType,
            'uid'         => $sendUid,
            'mine'        => $mineDigit,
            'amount'      => $amount,
            'amount_mode' => (int)($amountPlan['amount_mode'] ?? 1),
            'jackpot'     => !empty($amountPlan['jackpot']) ? 1 : 0,
            'count'       => $count,
            'burst'       => $useBurst ? ($burstSent . '/' . $burstCount) : '0',
            'packet_id'   => $packetId,
        ]);

        return ['sent' => true, 'packet_id' => $packetId, 'send_uid' => $sendUid];
    }

    /**
     * 时间窗突发门控：到期开新窗；窗内达上限则等下一窗；未到计划时刻则跳过。
     * @return array{ok:bool,task:array}
     */
    protected function burstGate(array $task, $now)
    {
        $taskId = (int)$task['id'];
        $window = max(30, (int)($task['burst_window_sec'] ?? 0));
        $limit = max(1, (int)($task['burst_count'] ?? 1));
        $start = (int)($task['burst_window_start'] ?? 0);
        $sent = (int)($task['burst_sent'] ?? 0);
        $nextAt = (int)($task['burst_next_at'] ?? 0);

        if ($start <= 0 || ($now - $start) >= $window) {
            $start = $now;
            $sent = 0;
            // 第一包：在窗前半段随机一个起点，避免整点齐发
            $firstDelay = random_int(0, max(1, (int)floor($window / max(2, $limit))));
            $nextAt = $now + $firstDelay;
            Db::exec(
                'UPDATE ' . Db::table('chat_rp_auto_task')
                . ' SET burst_window_start=?, burst_sent=0, burst_next_at=?, updatetime=? WHERE id=?',
                [$start, $nextAt, $now, $taskId]
            );
            $task['burst_window_start'] = $start;
            $task['burst_sent'] = 0;
            $task['burst_next_at'] = $nextAt;
            $this->bustTaskCache();
        }

        if ($sent >= $limit) {
            return ['ok' => false, 'task' => $task];
        }
        if ($nextAt > 0 && $now < $nextAt) {
            return ['ok' => false, 'task' => $task];
        }

        $task['burst_window_start'] = $start;
        $task['burst_sent'] = $sent;
        $task['burst_next_at'] = $nextAt;
        return ['ok' => true, 'task' => $task];
    }

    /** 规划窗内下一包时刻（剩余包均匀摊到剩余时间并抖动） */
    protected function planNextBurstAt(array $task, $now, $burstSent)
    {
        $window = max(30, (int)($task['burst_window_sec'] ?? 0));
        $limit = max(1, (int)($task['burst_count'] ?? 1));
        $start = (int)($task['burst_window_start'] ?? $now);
        if ($burstSent >= $limit) {
            return $start + $window; // 窗结束前不再发
        }
        $end = $start + $window;
        $leftSec = max(1, $end - $now);
        $remain = max(1, $limit - $burstSent);
        $slot = max(1, (int)floor($leftSec / $remain));
        $delay = random_int(max(1, (int)floor($slot * 0.35)), $slot);
        return $now + $delay;
    }

    /** 发包 UID：mode1=池；mode2=机器人账户随机 */
    protected function pickSendUserId(array $task)
    {
        if ($this->actorMode($task) === 2) {
            $bots = $this->listBotUserIds();
            if (!$bots) {
                return 0;
            }
            return (int)$bots[random_int(0, count($bots) - 1)];
        }
        $uids = $this->parseUserIds((string)($task['send_user_ids'] ?? ''));
        if (!$uids) {
            $one = (int)($task['send_user_id'] ?? 0);
            return $one > 0 ? $one : 0;
        }
        if (count($uids) === 1) {
            return (int)$uids[0];
        }
        return (int)$uids[random_int(0, count($uids) - 1)];
    }

    /**
     * 每个待领包独立延迟抢：delay 按包随机；多包可并行 Timer，互不串行等待。
     * 新包额外尊重真人优先窗（8～12 秒）。
     */
    protected function maybeScheduleGrab(array $task, $preferPacketId = 0)
    {
        $taskId = (int)$task['id'];
        $groupId = (int)$task['group_id'];
        if ($this->actorMode($task) === 2) {
            $uids = $this->listBotUserIds();
            if (!$uids) {
                $this->taskLog($taskId, 'skip', 'grab: no bot accounts');
                return;
            }
        } else {
            $uids = $this->parseUserIds((string)($task['grab_user_ids'] ?? ''));
            if (!$uids) {
                $this->taskLog($taskId, 'skip', 'grab: no grab_user_ids');
                return;
            }
        }

        $packets = [];
        if ((int)$preferPacketId > 0) {
            $meta = $this->packetMeta((int)$preferPacketId);
            if ($meta) {
                $packets[(int)$preferPacketId] = $meta;
            }
        }
        foreach ($this->listOpenPackets($groupId, 15) as $row) {
            $pid = (int)($row['id'] ?? 0);
            if ($pid > 0 && !isset($packets[$pid])) {
                $packets[$pid] = $row;
            }
        }
        if (!$packets) {
            $this->taskLogThrottled($taskId, 'grab_nopkt', 'skip', 'grab: no open packets', [
                'group_id' => $groupId,
            ], 30);
            return;
        }

        $scheduled = 0;
        $skippedBusy = 0;
        $skippedUid = 0;
        foreach ($packets as $packetId => $meta) {
            $packetId = (int)$packetId;
            if ($packetId <= 0) {
                continue;
            }
            $remain = max(1, (int)($meta['remain_count'] ?? 1));
            // 同一包可并行排多个机器人（各用独立 delay），最多跟剩余份数对齐
            $maxSlots = min($remain, 8);
            $pickedThisRound = [];
            for ($slot = 0; $slot < $maxSlots; $slot++) {
                $uid = $this->pickGrabUidForPacket($packetId, $uids, $pickedThisRound);
                if ($uid <= 0) {
                    $skippedUid++;
                    break;
                }
                if ($this->isGrabBusy($taskId, $packetId, $uid)) {
                    $skippedBusy++;
                    continue;
                }

                // 每包独立随机 delay；新包可再拉长，但不超过后台配置的最大延迟
                $coolSec = $this->randomGrabDelaySec($task);
                list($minMs, $maxMs) = $this->grabDelayBounds($task);
                $maxSec = max((float)self::GRAB_DELAY_FLOOR_SEC, $maxMs / 1000.0);
                $created = (int)($meta['createtime'] ?? 0);
                $age = $created > 0 ? max(0, time() - $created) : 0;
                $humanNeed = (float)random_int(self::HUMAN_FIRST_MIN_SEC, self::HUMAN_FIRST_MAX_SEC);
                $humanNeed = min($humanNeed, $maxSec);
                $delaySec = $coolSec;
                if ($age < $humanNeed) {
                    $delaySec = max($delaySec, (float)($humanNeed - $age));
                }
                $delaySec = max((float)self::GRAB_DELAY_FLOOR_SEC, min(120.0, $delaySec));
                // 最终仍夹在后台配置区间内（避免真人优先窗抬过 max）
                $minSec = max((float)self::GRAB_DELAY_FLOOR_SEC, $minMs / 1000.0);
                $delaySec = max($minSec, min($maxSec, $delaySec));

                if (!$this->tryMarkGrabBusy($taskId, $packetId, $uid, (int)ceil($delaySec) + 20)) {
                    $skippedBusy++;
                    continue;
                }
                $pickedThisRound[$uid] = 1;

                $this->taskLog($taskId, 'info', 'grab scheduled', [
                    'packet_id' => $packetId,
                    'uid'       => $uid,
                    'delay_sec' => round($delaySec, 1),
                    'cfg_ms'    => $this->grabDelayBounds($task),
                    'parallel'  => 1,
                    'slot'      => $slot + 1,
                ]);
                $scheduled++;

                Timer::add($delaySec, function () use ($taskId, $packetId, $uid, $groupId) {
                    try {
                        $this->doGrabOnce($taskId, $packetId, $uid, $groupId);
                    } catch (\Throwable $e) {
                        $this->touchError($taskId, 'grab u' . $uid . ' p' . $packetId . ': ' . $e->getMessage());
                        $this->taskLog($taskId, 'error', 'grab timer: ' . $e->getMessage(), [
                            'packet_id' => $packetId,
                            'uid'       => $uid,
                        ]);
                    } finally {
                        $this->clearGrabBusy($taskId, $packetId, $uid);
                    }
                }, [], false);
            }
        }

        if ($scheduled <= 0 && ($skippedBusy > 0 || $skippedUid > 0)) {
            $this->taskLogThrottled($taskId, 'grab_none', 'skip', 'grab: nothing new to schedule', [
                'busy' => $skippedBusy,
                'nouid'=> $skippedUid,
                'open' => count($packets),
            ], 25);
        }
    }

    /**
     * @return array{0:int,1:int} [minMs, maxMs]
     */
    protected function grabDelayBounds(array $task)
    {
        $minMs = (int)($task['grab_delay_min_ms'] ?? 5000);
        $maxMs = (int)($task['grab_delay_max_ms'] ?? 15000);
        // 误填成秒（如 5～15）时自动按秒换算
        if ($maxMs > 0 && $maxMs <= 120 && $minMs <= 120) {
            $minMs *= 1000;
            $maxMs *= 1000;
        }
        $floorMs = self::GRAB_DELAY_FLOOR_SEC * 1000;
        if ($minMs < $floorMs) {
            $minMs = $floorMs;
        }
        if ($maxMs < $minMs) {
            $maxMs = $minMs;
        }
        if ($maxMs < $floorMs) {
            $minMs = $floorMs;
            $maxMs = max($floorMs, 5000);
        }
        $maxMs = min(120000, $maxMs);
        return [$minMs, $maxMs];
    }

    protected function randomGrabDelaySec(array $task)
    {
        list($minMs, $maxMs) = $this->grabDelayBounds($task);
        $ms = $minMs + ($maxMs > $minMs ? random_int(0, $maxMs - $minMs) : 0);
        return max((float)self::GRAB_DELAY_FLOOR_SEC, $ms / 1000.0);
    }

    /**
     * 发包间隔：优先匹配「时段固定间隔」；未命中用 interval_sec。
     * 默认窗：20–23 → 30s，0–7 → 120s（与 base=60 时旧倍率一致）。
     * interval_windows 显式 [] 表示关闭时段规则，全天用 base。
     *
     * @param mixed $windowsJson
     */
    protected function effectiveIntervalSec($baseSec, $windowsJson = null)
    {
        $base = max(5, (int)$baseSec);
        $hour = (int)date('G');
        $windows = $this->parseIntervalWindows($windowsJson);
        foreach ($windows as $w) {
            $start = (int)($w['start_hour'] ?? -1);
            $end = (int)($w['end_hour'] ?? -1);
            $iv = max(5, (int)($w['interval_sec'] ?? 0));
            if ($start < 0 || $start > 23 || $end < 0 || $end > 23 || $iv < 5) {
                continue;
            }
            if ($this->hourInWindow($hour, $start, $end)) {
                return $iv;
            }
        }
        return $base;
    }

    /**
     * @param mixed $raw
     * @return array<int,array{start_hour:int,end_hour:int,interval_sec:int}>
     */
    protected function parseIntervalWindows($raw)
    {
        if ($raw === null) {
            return $this->defaultIntervalWindows();
        }
        if (is_array($raw)) {
            $arr = $raw;
        } else {
            $trim = trim((string)$raw);
            if ($trim === '') {
                return $this->defaultIntervalWindows();
            }
            if ($trim === '[]') {
                return [];
            }
            $arr = json_decode($trim, true);
            if (!is_array($arr)) {
                return $this->defaultIntervalWindows();
            }
        }
        $out = [];
        foreach ($arr as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'start_hour'   => (int)($row['start_hour'] ?? $row['start'] ?? -1),
                'end_hour'     => (int)($row['end_hour'] ?? $row['end'] ?? -1),
                'interval_sec' => (int)($row['interval_sec'] ?? $row['interval'] ?? 0),
            ];
        }
        return $out;
    }

    protected function defaultIntervalWindows()
    {
        return [
            ['start_hour' => 20, 'end_hour' => 23, 'interval_sec' => 30],
            ['start_hour' => 0, 'end_hour' => 7, 'interval_sec' => 120],
        ];
    }

    protected function hourInWindow($hour, $start, $end)
    {
        $hour = (int)$hour;
        $start = (int)$start;
        $end = (int)$end;
        if ($start <= $end) {
            return $hour >= $start && $hour <= $end;
        }
        // 跨午夜：如 22–6
        return $hour >= $start || $hour <= $end;
    }

    /**
     * 任务金额计划。
     * - 接龙(type=5)：始终走模式一（固定/区间），忽略 amount_mode=2
     * - 模式一：amount_min/amount_max（相等=固定，否则区间随机，步长 10）
     * - 模式二：常态用 amount_min/max；发满 every_min～every_max 个小额包后插发 jackpot_min～jackpot_max
     * 群 rp_fixed_amount 仅对模式一生效。
     *
     * @return array{amount:float,amount_mode:int,jackpot:bool,next_mode2_count:int,next_mode2_target:int}
     */
    protected function resolveSendAmountPlan(array $task, array $group, $packetType)
    {
        $packetType = (int)$packetType;
        $mode = ((int)($task['amount_mode'] ?? 1) === 2) ? 2 : 1;
        if ($packetType === 5) {
            $mode = 1;
        }

        if ($mode === 2) {
            $cfg = $this->mode2AmountConfig($task);
            $streak = max(0, (int)($task['amount_mode2_count'] ?? 0));
            $target = (int)($task['amount_mode2_target'] ?? 0);
            if ($target < $cfg['every_min'] || $target > $cfg['every_max']) {
                $target = random_int($cfg['every_min'], $cfg['every_max']);
            }
            $jackpot = ($streak >= $target);
            if ($jackpot) {
                $amount = $this->randomAmountByTen($cfg['jackpot_min'], $cfg['jackpot_max']);
                return [
                    'amount'             => (float)$amount,
                    'amount_mode'        => 2,
                    'jackpot'            => true,
                    'next_mode2_count'   => 0,
                    'next_mode2_target'  => random_int($cfg['every_min'], $cfg['every_max']),
                ];
            }
            $amount = $this->randomAmountByTen($cfg['normal_min'], $cfg['normal_max']);
            return [
                'amount'             => (float)$amount,
                'amount_mode'        => 2,
                'jackpot'            => false,
                'next_mode2_count'   => $streak + 1,
                'next_mode2_target'  => $target,
            ];
        }

        $amount = $this->resolveSendAmountMode1($task, $group);
        return [
            'amount'             => (float)$amount,
            'amount_mode'        => 1,
            'jackpot'            => false,
            'next_mode2_count'   => (int)($task['amount_mode2_count'] ?? 0),
            'next_mode2_target'  => (int)($task['amount_mode2_target'] ?? 0),
        ];
    }

    /**
     * @return array{normal_min:int,normal_max:int,jackpot_min:int,jackpot_max:int,every_min:int,every_max:int}
     */
    protected function mode2AmountConfig(array $task)
    {
        $normalMin = (int)round((float)($task['amount_min'] ?? 0));
        $normalMax = (int)round((float)($task['amount_max'] ?? 0));
        if ($normalMin <= 0) {
            $normalMin = 10;
        }
        if ($normalMax <= 0) {
            $normalMax = 100;
        }
        if ($normalMax < $normalMin) {
            $tmp = $normalMin;
            $normalMin = $normalMax;
            $normalMax = $tmp;
        }
        $jackpotMin = (int)round((float)($task['amount_mode2_jackpot_min'] ?? 200));
        $jackpotMax = (int)round((float)($task['amount_mode2_jackpot_max'] ?? 300));
        if ($jackpotMin <= 0) {
            $jackpotMin = 200;
        }
        if ($jackpotMax <= 0) {
            $jackpotMax = 300;
        }
        if ($jackpotMax < $jackpotMin) {
            $tmp = $jackpotMin;
            $jackpotMin = $jackpotMax;
            $jackpotMax = $tmp;
        }
        $everyMin = max(1, (int)($task['amount_mode2_every_min'] ?? 10));
        $everyMax = max($everyMin, (int)($task['amount_mode2_every_max'] ?? 20));
        return [
            'normal_min'  => $this->roundToTen(max(10, $normalMin)),
            'normal_max'  => $this->roundToTen(max(10, $normalMax)),
            'jackpot_min' => $this->roundToTen(max(10, $jackpotMin)),
            'jackpot_max' => $this->roundToTen(max(10, $jackpotMax)),
            'every_min'   => $everyMin,
            'every_max'   => $everyMax,
        ];
    }

    /** 兼容旧调用 */
    protected function resolveSendAmount(array $task, array $group)
    {
        return $this->resolveSendAmountMode1($task, $group);
    }

    /**
     * 模式一：amount_min/amount_max（相等=固定，否则区间随机）
     * 必须是 10 的整数倍；群 rp_fixed_amount 优先；再夹到群 rp_min/max（亦取整到 10 的倍数）。
     */
    protected function resolveSendAmountMode1(array $task, array $group)
    {
        $groupFixed = (float)($group['rp_fixed_amount'] ?? 0);
        if ($groupFixed > 0) {
            return (float)$this->roundToTen((int)round($groupFixed));
        }

        $min = (float)($task['amount_min'] ?? 0);
        $max = (float)($task['amount_max'] ?? 0);
        $legacy = (float)($task['total_amount'] ?? 0);
        if ($min <= 0 && $legacy > 0) {
            $min = $legacy;
        }
        if ($max <= 0 && $legacy > 0) {
            $max = $legacy;
        }
        if ($min <= 0 && $max > 0) {
            $min = $max;
        }
        if ($max <= 0 && $min > 0) {
            $max = $min;
        }
        if ($max < $min) {
            $tmp = $min;
            $min = $max;
            $max = $tmp;
        }

        if ($min <= 0) {
            return 0.0;
        }

        $minInt = $this->roundToTen(max(10, (int)round($min)));
        $maxInt = $this->roundToTen(max($minInt, (int)round($max)));
        $amount = $this->randomAmountByTen($minInt, $maxInt);

        $gMin = (float)($group['rp_min_amount'] ?? 0);
        $gMax = (float)($group['rp_max_amount'] ?? 0);
        if ($gMin > 0) {
            $gMinInt = $this->roundToTen(max(10, (int)round($gMin)));
            if ($amount < $gMinInt) {
                $amount = $gMinInt;
            }
        }
        if ($gMax > 0) {
            $gMaxInt = $this->roundToTen(max(10, (int)round($gMax)));
            if ($amount > $gMaxInt) {
                $amount = $gMaxInt;
            }
        }
        return (float)$this->roundToTen(max(10, (int)$amount));
    }

    /** [min,max] 内按 10 步进随机（含端点） */
    protected function randomAmountByTen($min, $max)
    {
        $minInt = $this->roundToTen(max(10, (int)round($min)));
        $maxInt = $this->roundToTen(max($minInt, (int)round($max)));
        if ($minInt === $maxInt) {
            return $minInt;
        }
        $steps = (int)(($maxInt - $minInt) / 10);
        return $minInt + random_int(0, max(0, $steps)) * 10;
    }

    /** 向上取整到 ≥10 的 10 的倍数（已是则不变；0~9 → 10） */
    protected function roundToTen($n)
    {
        $n = (int)$n;
        if ($n <= 0) {
            return 10;
        }
        $r = (int)(ceil($n / 10) * 10);
        return max(10, $r);
    }

    /**
     * 发包个数：埋雷固定从 5/7/9 随机（受群 min/max 裁剪）；其它类型用任务 total_count。
     */
    protected function resolveSendCount(array $task, array $group, $packetType)
    {
        $packetType = (int)$packetType;
        if ($packetType === 3) {
            $gMin = (int)($group['rp_min_count'] ?? 0);
            $gMax = (int)($group['rp_max_count'] ?? 0);
            if ($gMin <= 0) {
                $gMin = 5;
            }
            if ($gMax <= 0) {
                $gMax = 10;
            }
            if ($gMax < $gMin) {
                $gMax = $gMin;
            }
            $opts = array_values(array_filter([5, 7, 9], function ($n) use ($gMin, $gMax) {
                return $n >= $gMin && $n <= $gMax;
            }));
            if (!$opts) {
                $opts = [5, 7, 9];
            }
            return (int)$opts[array_rand($opts)];
        }
        return max(1, (int)($task['total_count'] ?? 5));
    }

    protected function packetMeta($packetId)
    {
        $packetId = (int)$packetId;
        if ($packetId <= 0) {
            return null;
        }
        $row = Db::fetch(
            'SELECT id, createtime, remain_count, status, packet_type, total_amount, total_count, scope_type, group_id'
            . ' FROM ' . Db::table('chat_red_packets')
            . ' WHERE id=? LIMIT 1',
            [$packetId]
        );
        if (!$row || (int)($row['status'] ?? 0) !== 1 || (int)($row['remain_count'] ?? 0) <= 0) {
            return null;
        }
        // 拼手气(2) / 埋雷(3) / 接龙(5) 可自动抢；普通包(1)仍不自动抢
        if (!in_array((int)($row['packet_type'] ?? 0), [2, 3, 5], true)) {
            return null;
        }
        return $row;
    }

    protected function doGrabOnce($taskId, $packetId, $uid, $groupId, array $opts = [])
    {
        $packetId = (int)$packetId;
        $uid = (int)$uid;
        $groupId = (int)$groupId;
        $robotSelfGrab = !empty($opts['robot_self_grab']);
        if ($packetId <= 0 || $uid <= 0) {
            return false;
        }
        $exists = Db::fetch(
            'SELECT id FROM ' . Db::table('chat_red_packet_records')
            . ' WHERE packet_id=? AND user_id=? LIMIT 1',
            [$packetId, $uid]
        );
        if ($exists) {
            return false;
        }
        $open = Db::fetch(
            'SELECT id, remain_count, status, group_id, packet_type, total_amount, total_count, scope_type, from_user_id'
            . ' FROM ' . Db::table('chat_red_packets')
            . ' WHERE id=? LIMIT 1',
            [$packetId]
        );
        if (!$open || (int)$open['status'] !== 1 || (int)$open['remain_count'] <= 0) {
            return false;
        }
        if ($robotSelfGrab && $uid !== (int)($open['from_user_id'] ?? 0)) {
            $robotSelfGrab = false;
            $opts['robot_self_grab'] = false;
        }
        // 领前再验一次：余额不够赔付则不抢（与真人同一闸门）
        // 机器人自领拼手气/接龙：跳过（grab 内只入账不冻）；埋雷仍验资
        $skipPrecheck = $robotSelfGrab && in_array((int)($open['packet_type'] ?? 0), [2, 5], true);
        if (!$skipPrecheck && !$this->redPackets->canAffordGrabCompensate($uid, $open)) {
            $need = $this->redPackets->potentialCompensateNeed($open);
            error_log(sprintf(
                '[RP_AUTO] grab precheck reject task=%d packet=%d uid=%d need=%.2f',
                $taskId,
                $packetId,
                $uid,
                $need
            ));
            $this->touchError($taskId, 'grab precheck balance uid=' . $uid . ' need=' . sprintf('%.2f', $need));
            $this->taskLog($taskId, 'skip', 'grab precheck balance', [
                'packet_id' => $packetId,
                'uid'       => $uid,
                'need'      => $need,
            ]);
            return false;
        }
        $gid = (int)($open['group_id'] ?? $groupId);
        if ($gid > 0) {
            // 抢包号未入群时自动拉进群，避免 not in group
            try {
                $this->ensureSenderInGroup($gid, $uid);
            } catch (\Throwable $e) {
                throw new \RuntimeException('grab uid not in group: ' . $e->getMessage());
            }
        }

        try {
            $result = $this->redPackets->grab($packetId, $uid, $opts);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            // 余额不够赔付：与真人同一套闸门，软跳过换下一个 UID
            if (stripos($msg, 'balance_not_enough_for_compensate') !== false
                || stripos($msg, 'balance_below_mine_min') !== false
                || stripos($msg, 'insufficient balance') !== false
            ) {
                error_log(sprintf(
                    '[RP_AUTO] grab balance reject task=%d packet=%d uid=%d err=%s',
                    $taskId,
                    $packetId,
                    $uid,
                    $msg
                ));
                $this->touchError($taskId, 'grab balance: uid=' . $uid . ' ' . $msg);
                $this->taskLog($taskId, 'skip', 'grab balance reject', [
                    'packet_id' => $packetId,
                    'uid'       => $uid,
                    'err'       => $msg,
                ]);
                return false;
            }
            if ($this->isSoftGrabError($msg)) {
                $this->taskLog($taskId, 'skip', 'grab soft error', [
                    'packet_id' => $packetId,
                    'uid'       => $uid,
                    'err'       => $msg,
                ]);
                return false;
            }
            throw $e;
        }

        $packet = $result['packet'] ?? null;
        if (is_array($packet)) {
            try {
                $event = [
                    'packet_id'  => $packetId,
                    'grab'       => $result,
                    'by_user_id' => $uid,
                ];
                $gid = (int)($packet['group_id'] ?? $groupId);
                RedPacketUpdateBus::publish($event, ['group_id' => $gid]);
            } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.RpAutoBotService');
        }
        }
        $frozen = round((float)($result['frozen_amount'] ?? 0), 2);
        $this->taskLog($taskId, 'ok', 'grab ok', [
            'packet_id' => $packetId,
            'uid'       => $uid,
            'frozen'    => $frozen,
            'amount'    => $result['amount'] ?? null,
        ]);
        return true;
    }

    /**
     * 每个待领包：从未领过且红宝够赔付/冻结的 grab_user_ids 里随机选 1 个去抢。
     * @param int[] $excludeUids 本轮已排程 UID
     * @return int user_id，无可抢 UID 时返回 0
     */
    protected function pickGrabUidForPacket($packetId, array $uids, array $excludeUids = [])
    {
        $uids = array_values(array_filter(array_map('intval', $uids)));
        $packetId = (int)$packetId;
        if (!$uids || $packetId <= 0) {
            return 0;
        }
        $excludeMap = [];
        foreach ($excludeUids as $k => $v) {
            $id = is_int($k) && $v === 1 ? (int)$k : (int)$v;
            if ($id > 0) {
                $excludeMap[$id] = 1;
            }
        }
        $packet = Db::fetch(
            'SELECT id, packet_type, total_amount, total_count, scope_type, group_id, status, remain_count'
            . ' FROM ' . Db::table('chat_red_packets') . ' WHERE id=? LIMIT 1',
            [$packetId]
        );
        if (!$packet || (int)($packet['status'] ?? 0) !== 1 || (int)($packet['remain_count'] ?? 0) <= 0) {
            return 0;
        }
        $grabbed = [];
        try {
            $rows = Db::fetchAll(
                'SELECT user_id FROM ' . Db::table('chat_red_packet_records')
                . ' WHERE packet_id=? AND user_id IN (' . implode(',', $uids) . ')',
                [$packetId]
            );
            foreach ($rows ?: [] as $r) {
                $grabbed[(int)$r['user_id']] = 1;
            }
        } catch (\Throwable $e) {
            $grabbed = [];
        }
        $pool = [];
        foreach ($uids as $uid) {
            if ($uid > 0 && empty($grabbed[$uid]) && empty($excludeMap[$uid])) {
                $pool[] = $uid;
            }
        }
        if (!$pool) {
            return 0;
        }
        $candidates = $this->redPackets->filterUidsCanAffordGrab($pool, $packet);
        if (!$candidates) {
            return 0;
        }
        return (int)$candidates[random_int(0, count($candidates) - 1)];
    }

    /** 群内待领包数量（仅 status=1） */
    protected function countOpenPackets($groupId)
    {
        $row = Db::fetch(
            'SELECT COUNT(*) AS c FROM ' . Db::table('chat_red_packets')
            . ' WHERE group_id=? AND scope_type=2 AND status=1 AND remain_count>0',
            [(int)$groupId]
        );
        return (int)($row['c'] ?? 0);
    }

    /**
     * 群内「进行中」红包：待领(status=1) 或 待结算(status=2)。
     * 拼手气/埋雷/接龙抢完瞬间 status=2 时禁止自动任务再发，避免插队叠包。
     */
    protected function countBusyPackets($groupId)
    {
        $row = Db::fetch(
            'SELECT COUNT(*) AS c FROM ' . Db::table('chat_red_packets')
            . ' WHERE group_id=? AND scope_type=2 AND packet_type IN (2,3,5)'
            . ' AND (status=2 OR (status=1 AND remain_count>0))',
            [(int)$groupId]
        );
        return (int)($row['c'] ?? 0);
    }

    /**
     * 群内接龙是否仍占用「续发权」（禁止自动任务插队发包）。
     * - status=2：已抢完待结算
     * - status=5 + 最差 compensate_status IN (1,3)：待续发 / 续发进行中
     */
    protected function hasPendingRelay($groupId)
    {
        $groupId = (int)$groupId;
        if ($groupId <= 0) {
            return false;
        }
        try {
            $row = Db::fetch(
                'SELECT p.id FROM ' . Db::table('chat_red_packets') . ' p'
                . ' LEFT JOIN ' . Db::table('chat_red_packet_records') . ' r'
                . ' ON r.packet_id=p.id AND r.is_worst=1'
                . ' WHERE p.group_id=? AND p.scope_type=2 AND p.packet_type=5'
                . ' AND ('
                . '   p.status=2'
                . '   OR (p.status=5 AND r.compensate_status IN (1,3))'
                . ' )'
                . ' ORDER BY p.id DESC LIMIT 1',
                [$groupId]
            );
            if ($row) {
                return true;
            }
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.RpAutoBotService');
        }
        return false;
    }

    /** @return array[] */
    protected function listOpenPackets($groupId, $limit = 8)
    {
        $limit = max(1, min(20, (int)$limit));
        $rows = Db::fetchAll(
            'SELECT id, createtime, remain_count, status, packet_type FROM ' . Db::table('chat_red_packets')
            . ' WHERE group_id=? AND scope_type=2 AND status=1 AND remain_count>0'
            . ' AND packet_type IN (2,3,5)'
            . ' ORDER BY id DESC LIMIT ' . $limit,
            [(int)$groupId]
        );
        return is_array($rows) ? $rows : [];
    }

    /** @return int[] */
    protected function listOpenPacketIds($groupId, $limit = 8)
    {
        $out = [];
        foreach ($this->listOpenPackets($groupId, $limit) as $r) {
            $id = (int)($r['id'] ?? 0);
            if ($id > 0) {
                $out[] = $id;
            }
        }
        return $out;
    }

    protected function ensureSenderInGroup($groupId, $userId)
    {
        $groupId = (int)$groupId;
        $userId = (int)$userId;
        $group = $this->groups->get($groupId);
        if (!$group) {
            throw new \RuntimeException('群不存在: #' . $groupId);
        }
        if (!$this->groups->isMember($groupId, $userId)) {
            $this->groups->addMembers($groupId, [$userId], 2);
            return;
        }
        if ($this->groups->memberRole($groupId, $userId) < 2) {
            $this->groups->addMembers($groupId, [$userId], 2);
        }
    }

    protected function loadTasks()
    {
        $now = time();
        if ($this->taskCache !== null && ($now - $this->taskCacheAt) < self::TASK_CACHE_TTL) {
            return $this->taskCache;
        }
        try {
            $rows = Db::fetchAll(
                'SELECT * FROM ' . Db::table('chat_rp_auto_task')
                . " WHERE status='normal' ORDER BY id ASC LIMIT 200"
            );
        } catch (\Throwable $e) {
            $rows = [];
        }
        $this->taskCache = is_array($rows) ? $rows : [];
        $this->taskCacheAt = $now;
        return $this->taskCache;
    }

    protected function bustTaskCache()
    {
        $this->taskCache = null;
        $this->taskCacheAt = 0;
    }

    protected function tryLock($taskId, $ttl = 4)
    {
        try {
            $r = RedisClient::conn();
            $key = RedisClient::key(self::TASK_LOCK_PREFIX . (int)$taskId);
            return (bool)$r->set($key, (string)time(), ['nx', 'ex' => max(2, (int)$ttl)]);
        } catch (\Throwable $e) {
            return true;
        }
    }

    protected function unlock($taskId)
    {
        try {
            RedisClient::conn()->del(RedisClient::key(self::TASK_LOCK_PREFIX . (int)$taskId));
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.RpAutoBotService');
        }
    }

    protected function grabBusyKey($taskId, $packetId, $uid = 0)
    {
        $uid = (int)$uid;
        $base = self::GRAB_BUSY_PREFIX . (int)$taskId . ':' . (int)$packetId;
        if ($uid > 0) {
            $base .= ':' . $uid;
        }
        return RedisClient::key($base);
    }

    protected function isGrabBusy($taskId, $packetId, $uid = 0)
    {
        try {
            $v = RedisClient::conn()->get($this->grabBusyKey($taskId, $packetId, $uid));
            return $v !== false && $v !== null && $v !== '';
        } catch (\Throwable $e) {
            // 失败时当作忙碌，避免无 Redis 时叠 Timer 连抢
            return true;
        }
    }

    /** @return bool 是否成功占到该包+UID 的坑 */
    protected function tryMarkGrabBusy($taskId, $packetId, $uid, $ttl)
    {
        try {
            $r = RedisClient::conn();
            return (bool)$r->set(
                $this->grabBusyKey($taskId, $packetId, $uid),
                (string)microtime(true),
                ['nx', 'ex' => max(5, (int)$ttl)]
            );
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function clearGrabBusy($taskId, $packetId, $uid = 0)
    {
        try {
            RedisClient::conn()->del($this->grabBusyKey($taskId, $packetId, $uid));
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.RpAutoBotService');
        }
    }

    protected function parseUserIds($raw)
    {
        $raw = str_replace(["\xef\xbc\x8c", '、', '|', "\n", "\r"], ',', (string)$raw); // 中文逗号等
        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $p) {
            $id = (int)$p;
            if ($id > 0) {
                $out[$id] = $id;
            }
        }
        return array_values($out);
    }

    /** 1=UID池 2=机器人账户随机 */
    protected function actorMode(array $task)
    {
        return ((int)($task['actor_mode'] ?? 1) === 2) ? 2 : 1;
    }

    /**
     * 机器人账户 UID 列表（fa_fans_account.is_bot=1），短缓存
     * @return int[]
     */
    protected function listBotUserIds($limit = 300)
    {
        static $mem = null;
        static $memAt = 0.0;
        $now = microtime(true);
        if (is_array($mem) && ($now - $memAt) < 8.0) {
            return $mem;
        }
        $limit = max(1, min(500, (int)$limit));
        try {
            $key = RedisClient::key('rp_auto:bot_uids');
            $raw = RedisClient::conn()->get($key);
            if ($raw !== false && $raw !== null && $raw !== '') {
                $decoded = json_decode((string)$raw, true);
                if (is_array($decoded) && $decoded) {
                    $mem = array_values(array_filter(array_map('intval', $decoded)));
                    $memAt = $now;
                    return $mem;
                }
            }
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.RpAutoBotService');
        }
        $rows = Db::fetchAll(
            'SELECT user_id FROM ' . Db::table('fans_account')
            . " WHERE IFNULL(is_bot,0)=1 AND status='normal' ORDER BY id ASC LIMIT {$limit}"
        ) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $uid = (int)($row['user_id'] ?? 0);
            if ($uid > 0) {
                $out[] = $uid;
            }
        }
        try {
            RedisClient::conn()->setex(
                RedisClient::key('rp_auto:bot_uids'),
                30,
                json_encode($out, JSON_UNESCAPED_UNICODE)
            );
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.RpAutoBotService');
        }
        $mem = $out;
        $memAt = $now;
        return $out;
    }

    protected function isSoftGrabError($msg)
    {
        $msg = (string)$msg;
        foreach (['already', 'empty', 'closed', 'balance', 'grabbed', 'pending', 'mine_hash'] as $kw) {
            if (stripos($msg, $kw) !== false) {
                return true;
            }
        }
        return false;
    }

    protected function touchError($taskId, $msg)
    {
        $taskId = (int)$taskId;
        if ($taskId <= 0) {
            return;
        }
        try {
            Db::exec(
                'UPDATE ' . Db::table('chat_rp_auto_task')
                . ' SET last_error=?, updatetime=? WHERE id=?',
                [mb_substr((string)$msg, 0, 250), time(), $taskId]
            );
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.RpAutoBotService');
        }
        $this->taskLog($taskId, 'error', (string)$msg);
    }

    /**
     * 每个任务单独写日志：im-server/runtime/log/rp_auto/task_{id}_YYYYMMDD.log
     * taskId=0 写到 tick_YYYYMMDD.log
     *
     * @param int    $taskId
     * @param string $level  info|ok|skip|error
     * @param string $msg
     * @param array  $ctx
     */
    protected function taskLog($taskId, $level, $msg, array $ctx = [])
    {
        $taskId = (int)$taskId;
        $level = (string)$level;
        $msg = (string)$msg;
        $prefix = $taskId > 0 ? ('[RP_AUTO][t' . $taskId . ']') : '[RP_AUTO][tick]';
        $lineMsg = $prefix . ' [' . $level . '] ' . $msg;
        if ($ctx) {
            $json = json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json !== false) {
                $lineMsg .= ' ' . $json;
            }
        }
        // ok/error 进 php error_log；常规 skip 只写任务文件，避免刷屏
        if ($level === 'ok' || $level === 'error' || $level === 'info') {
            error_log($lineMsg);
        }

        try {
            $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR
                . 'log' . DIRECTORY_SEPARATOR . 'rp_auto';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $file = $dir . DIRECTORY_SEPARATOR
                . ($taskId > 0 ? ('task_' . $taskId . '_') : 'tick_')
                . date('Ymd') . '.log';
            $line = date('Y-m-d H:i:s') . ' [' . strtoupper($level) . '] ' . $msg;
            if ($ctx) {
                $json = json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($json !== false) {
                    $line .= ' ' . $json;
                }
            }
            @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.RpAutoBotService');
        }
    }

    /** 同类 skip 节流，避免 2s tick 刷盘 */
    protected function taskLogThrottled($taskId, $reasonKey, $level, $msg, array $ctx = [], $ttlSec = 30)
    {
        $key = ((int)$taskId) . ':' . (string)$reasonKey;
        $now = time();
        if (isset($this->skipLogAt[$key]) && ($now - $this->skipLogAt[$key]) < max(5, (int)$ttlSec)) {
            return;
        }
        $this->skipLogAt[$key] = $now;
        $this->taskLog($taskId, $level, $msg, $ctx);
    }
}
