<?php

namespace Im\Service;

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
 * - actor_mode：1=发包/抢包 UID 池；2=从 is_bot=1 机器人账户随机发/抢
 * - 多 UID：每次随机选一个尚未领过该包的 UID 去抢（含拼手气/埋雷/接龙）
 */
class RpAutoBotService
{
    const HEARTBEAT_KEY = 'rp_auto:ws_active';
    const TASK_LOCK_PREFIX = 'rp_auto:lock:';
    const GRAB_BUSY_PREFIX = 'rp_auto:grab_busy:';
    /** 下次允许安排抢包的时间戳（微秒浮点存字符串） */
    const GRAB_NEXT_AT_PREFIX = 'rp_auto:grab_next:';
    const TASK_CACHE_TTL = 8;
    /** 真人优先：新包发出后机器人至少等待秒数 */
    const HUMAN_FIRST_MIN_SEC = 8;
    const HUMAN_FIRST_MAX_SEC = 12;
    /** 后台未配或配错时的硬底（秒） */
    const GRAB_DELAY_FLOOR_SEC = 5;

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
        if ((int)($task['auto_send'] ?? 0) === 1) {
            $sent = $this->maybeSend($task);
            if (!empty($sent['sent'])) {
                $packetId = (int)$sent['packet_id'];
            }
        }

        if ((int)($task['auto_grab'] ?? 0) === 1) {
            $this->maybeScheduleGrab($task, $packetId);
        }
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
            $interval = $this->effectiveIntervalSec((int)($task['interval_sec'] ?? 60));
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
        $openCnt = $this->countBusyPackets($groupId);
        if ($openCnt > 0) {
            $this->taskLogThrottled($taskId, 'open', 'skip', 'group has open/settling packets', [
                'group_id' => $groupId,
                'open'     => $openCnt,
            ], 30);
            return ['sent' => false, 'packet_id' => 0];
        }
        // 接龙：上一包已抢完、正等「最少者」续发时，禁止机器人插队发包
        if ($this->hasPendingRelay($groupId)) {
            $this->taskLogThrottled($taskId, 'relay', 'skip', 'pending relay next round', [
                'group_id' => $groupId,
            ], 30);
            return ['sent' => false, 'packet_id' => 0];
        }

        $sendUid = $this->pickSendUserId($task);
        if ($sendUid <= 0) {
            throw new \RuntimeException('未配置发包用户ID');
        }
        $group = $this->groups->get($groupId) ?: [];
        $amount = $this->resolveSendAmount($task, $group);
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
            }
        }

        $burstSent = (int)($task['burst_sent'] ?? 0) + 1;
        $burstNext = 0;
        if ($useBurst) {
            $burstNext = $this->planNextBurstAt($task, $now, $burstSent);
        }

        Db::exec(
            'UPDATE ' . Db::table('chat_rp_auto_task')
            . ' SET last_send_time=?, last_packet_id=?, today_count=today_count+1,'
            . ' burst_window_start=?, burst_sent=?, burst_next_at=?, last_error=?, updatetime=? WHERE id=?',
            [
                $now,
                $packetId,
                (int)($task['burst_window_start'] ?? ($useBurst ? $now : 0)),
                $useBurst ? $burstSent : 0,
                $burstNext,
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
            'count'       => $count,
            'burst'       => $useBurst ? ($burstSent . '/' . $burstCount) : '0',
            'packet_id'   => $packetId,
        ]);

        return ['sent' => true, 'packet_id' => $packetId];
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
     * 每任务同时最多 1 次待执行抢包。
     * 间隔以后台 grab_delay_min/max_ms 为准（默认 5～15 秒）：
     * - 每次安排抢包前先按该间隔 Timer 等待，再执行
     * - 新包额外尊重真人优先窗（8～12 秒）
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
        if ($this->isGrabBusy($taskId)) {
            $this->taskLogThrottled($taskId, 'grab_busy', 'skip', 'grab: busy', [], 20);
            return;
        }
        $now = microtime(true);
        $nextAt = $this->getGrabNextAt($taskId);
        if ($nextAt > $now + 0.05) {
            $this->taskLogThrottled($taskId, 'grab_cool', 'skip', 'grab: cooling', [
                'wait_sec' => round($nextAt - $now, 2),
            ], 20);
            return;
        }

        $packets = [];
        if ((int)$preferPacketId > 0) {
            $meta = $this->packetMeta((int)$preferPacketId);
            if ($meta) {
                $packets[(int)$preferPacketId] = $meta;
            }
        }
        foreach ($this->listOpenPackets($groupId, 8) as $row) {
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

        $pair = $this->pickGrabPair(array_keys($packets), $uids);
        if (!$pair) {
            $this->taskLogThrottled($taskId, 'grab_nouid', 'skip', 'grab: no uid left for open packets', [
                'packets' => array_keys($packets),
                'uids'    => $uids,
            ], 30);
            return;
        }

        // 抢前等待 = 后台 grab_delay（默认 5～15 秒）；新包再拉长到真人优先窗
        $coolSec = $this->randomGrabDelaySec($task);
        $packetId = (int)$pair['packet_id'];
        $uid = (int)$pair['user_id'];
        $created = (int)($packets[$packetId]['createtime'] ?? 0);
        $age = $created > 0 ? max(0, time() - $created) : 0;
        $humanNeed = random_int(self::HUMAN_FIRST_MIN_SEC, self::HUMAN_FIRST_MAX_SEC);
        $delaySec = $coolSec;
        if ($age < $humanNeed) {
            $delaySec = max($delaySec, (float)($humanNeed - $age));
        }
        $delaySec = max((float)self::GRAB_DELAY_FLOOR_SEC, min(120.0, $delaySec));

        // 原子占坑：避免 Redis 异常或并发 tick 叠多个 Timer → 看起来像 1 秒连抢
        if (!$this->tryMarkGrabBusy($taskId, (int)ceil($delaySec) + 20)) {
            $this->taskLog($taskId, 'skip', 'grab: mark busy failed');
            return;
        }
        // 占住冷却窗：Timer 触发前不会再排下一枪
        $this->setGrabNextAt($taskId, $now + $delaySec);

        $this->taskLog($taskId, 'info', 'grab scheduled', [
            'packet_id' => $packetId,
            'uid'       => $uid,
            'delay_sec' => round($delaySec, 1),
            'cfg_ms'    => $this->grabDelayBounds($task),
        ]);

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
                // 短缓冲后由下次 schedule 再按后台间隔 delay，避免抢完立刻叠 Timer
                $this->setGrabNextAt($taskId, microtime(true) + 0.5);
                $this->clearGrabBusy($taskId);
            }
        }, [], false);
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
            $maxMs = 15000;
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

    /** 20–23 点加密；0–7 点放缓 */
    protected function effectiveIntervalSec($baseSec)
    {
        $base = max(5, (int)$baseSec);
        $hour = (int)date('G');
        if ($hour >= 20 && $hour <= 23) {
            return max(15, (int)round($base * 0.5));
        }
        if ($hour >= 0 && $hour < 8) {
            return max($base * 2, (int)round($base * 2.0));
        }
        return $base;
    }

    /**
     * 任务金额：amount_min/amount_max（相等=固定，否则区间随机）
     * 必须是 10 的整数倍；群 rp_fixed_amount 优先；再夹到群 rp_min/max（亦取整到 10 的倍数）。
     */
    protected function resolveSendAmount(array $task, array $group)
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
        if ($minInt === $maxInt) {
            $amount = $minInt;
        } else {
            $steps = (int)(($maxInt - $minInt) / 10);
            $amount = $minInt + random_int(0, max(0, $steps)) * 10;
        }

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

    protected function doGrabOnce($taskId, $packetId, $uid, $groupId)
    {
        $packetId = (int)$packetId;
        $uid = (int)$uid;
        $groupId = (int)$groupId;
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
            'SELECT id, remain_count, status, group_id, packet_type, total_amount, total_count, scope_type'
            . ' FROM ' . Db::table('chat_red_packets')
            . ' WHERE id=? LIMIT 1',
            [$packetId]
        );
        if (!$open || (int)$open['status'] !== 1 || (int)$open['remain_count'] <= 0) {
            return false;
        }
        // 领前再验一次：余额不够赔付则不抢（与真人同一闸门）
        if (!$this->redPackets->canAffordGrabCompensate($uid, $open)) {
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
            $result = $this->redPackets->grab($packetId, $uid);
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
     * 批量查已领 UID + 批量验资，减少 N+1。
     */
    protected function pickGrabPair(array $packetIds, array $uids)
    {
        $uids = array_values(array_filter(array_map('intval', $uids)));
        if (!$uids || !$packetIds) {
            return null;
        }
        foreach ($packetIds as $packetId) {
            $packetId = (int)$packetId;
            if ($packetId <= 0) {
                continue;
            }
            $packet = Db::fetch(
                'SELECT id, packet_type, total_amount, total_count, scope_type, group_id, status, remain_count'
                . ' FROM ' . Db::table('chat_red_packets') . ' WHERE id=? LIMIT 1',
                [$packetId]
            );
            if (!$packet || (int)($packet['status'] ?? 0) !== 1 || (int)($packet['remain_count'] ?? 0) <= 0) {
                continue;
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
                if ($uid > 0 && empty($grabbed[$uid])) {
                    $pool[] = $uid;
                }
            }
            if (!$pool) {
                continue;
            }
            $candidates = $this->redPackets->filterUidsCanAffordGrab($pool, $packet);
            if (!$candidates) {
                continue;
            }
            $pick = $candidates[random_int(0, count($candidates) - 1)];
            return ['packet_id' => $packetId, 'user_id' => $pick];
        }
        return null;
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
        }
    }

    protected function isGrabBusy($taskId)
    {
        try {
            $v = RedisClient::conn()->get(RedisClient::key(self::GRAB_BUSY_PREFIX . (int)$taskId));
            return $v !== false && $v !== null && $v !== '';
        } catch (\Throwable $e) {
            // 失败时当作忙碌，避免无 Redis 时叠 Timer 连抢
            return true;
        }
    }

    /** @return bool 是否成功占到坑 */
    protected function tryMarkGrabBusy($taskId, $ttl)
    {
        try {
            $r = RedisClient::conn();
            $key = RedisClient::key(self::GRAB_BUSY_PREFIX . (int)$taskId);
            return (bool)$r->set($key, (string)microtime(true), ['nx', 'ex' => max(5, (int)$ttl)]);
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function markGrabBusy($taskId, $ttl)
    {
        $this->tryMarkGrabBusy($taskId, $ttl);
    }

    protected function clearGrabBusy($taskId)
    {
        try {
            RedisClient::conn()->del(RedisClient::key(self::GRAB_BUSY_PREFIX . (int)$taskId));
        } catch (\Throwable $e) {
        }
    }

    protected function getGrabNextAt($taskId)
    {
        try {
            $v = RedisClient::conn()->get(RedisClient::key(self::GRAB_NEXT_AT_PREFIX . (int)$taskId));
            if ($v === false || $v === null || $v === '') {
                return 0.0;
            }
            return (float)$v;
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    protected function setGrabNextAt($taskId, $ts)
    {
        try {
            $ttl = max(30, (int)ceil(max(0.0, (float)$ts - microtime(true)) + 30));
            RedisClient::conn()->setex(
                RedisClient::key(self::GRAB_NEXT_AT_PREFIX . (int)$taskId),
                $ttl,
                sprintf('%.3f', (float)$ts)
            );
        } catch (\Throwable $e) {
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
