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
 * - 埋雷雷号每发随机 0-9；金额可抖动（不低于群最低 / 固定额）
 * - 节奏：20–23 点发包间隔减半；0–7 点翻倍（仅固定间隔模式）
 * - 接龙续发：由结算/cron 全局监听「全部群」的全部 type5，抢完后最少者名义发下一包（见 RedPacketService::trySendRobotNextRound）
 * - 抢包：仅后台配置了 auto_grab + grab_user_ids 的任务才抢
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
        foreach ($tasks as $task) {
            $taskId = (int)($task['id'] ?? 0);
            if ($taskId <= 0) {
                continue;
            }
            if (!$this->tryLock($taskId, 4)) {
                continue;
            }
            try {
                $this->runOne($task);
            } catch (\Throwable $e) {
                $this->touchError($taskId, $e->getMessage());
                error_log('[RP_AUTO] task#' . $taskId . ' ' . $e->getMessage());
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
                return ['sent' => false, 'packet_id' => 0];
            }
            $task = $gate['task'];
        } else {
            $interval = $this->effectiveIntervalSec((int)($task['interval_sec'] ?? 60));
            $last = (int)($task['last_send_time'] ?? 0);
            if ($last > 0 && ($now - $last) < $interval) {
                return ['sent' => false, 'packet_id' => 0];
            }
        }

        $maxDay = (int)($task['max_per_day'] ?? 0);
        if ($maxDay > 0 && (int)($task['today_count'] ?? 0) >= $maxDay) {
            return ['sent' => false, 'packet_id' => 0];
        }
        if ($this->countOpenPackets($groupId) > 0) {
            return ['sent' => false, 'packet_id' => 0];
        }
        // 接龙：上一包已抢完、正等「最少者」续发时，禁止机器人插队发包
        if ($this->hasPendingRelay($groupId)) {
            return ['sent' => false, 'packet_id' => 0];
        }

        $sendUid = $this->pickSendUserId($task);
        if ($sendUid <= 0) {
            throw new \RuntimeException('未配置发包用户ID');
        }
        $group = $this->groups->get($groupId) ?: [];
        $amount = $this->resolveSendAmount($task, $group);
        $count = (int)($task['total_count'] ?? 0);
        if ($amount <= 0 || $count <= 0) {
            throw new \RuntimeException('金额/个数无效');
        }

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

        error_log(sprintf(
            '[RP_AUTO] send ok task=%d group=%d type=%d uid=%d mine=%d amount=%.2f burst=%d/%d packet=%d',
            $taskId,
            $groupId,
            $packetType,
            $sendUid,
            $mineDigit,
            $amount,
            $useBurst ? $burstSent : 0,
            $useBurst ? $burstCount : 0,
            $packetId
        ));

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

    /** 发包 UID 池：send_user_ids 优先，否则 send_user_id；多选随机 */
    protected function pickSendUserId(array $task)
    {
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
     * - 抢成功/失败后写入冷却，到期前不再安排
     * - 新包额外尊重真人优先窗（8～12 秒）
     */
    protected function maybeScheduleGrab(array $task, $preferPacketId = 0)
    {
        $taskId = (int)$task['id'];
        $groupId = (int)$task['group_id'];
        $uids = $this->parseUserIds((string)($task['grab_user_ids'] ?? ''));
        if (!$uids) {
            return;
        }
        if ($this->isGrabBusy($taskId)) {
            return;
        }
        $now = microtime(true);
        $nextAt = $this->getGrabNextAt($taskId);
        if ($nextAt > $now + 0.05) {
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
            return;
        }

        $pair = $this->pickGrabPair(array_keys($packets), $uids);
        if (!$pair) {
            return;
        }

        $coolSec = $this->randomGrabDelaySec($task);
        $packetId = (int)$pair['packet_id'];
        $uid = (int)$pair['user_id'];
        $created = (int)($packets[$packetId]['createtime'] ?? 0);
        $age = $created > 0 ? max(0, time() - $created) : 0;
        $humanNeed = random_int(self::HUMAN_FIRST_MIN_SEC, self::HUMAN_FIRST_MAX_SEC);
        // 冷却已在 next_at 等待过：此处只补齐「新包真人优先」；否则短延迟触发
        $delaySec = 0.35;
        if ($age < $humanNeed) {
            $delaySec = max($delaySec, (float)($humanNeed - $age));
        }
        $delaySec = max(0.35, min(90.0, $delaySec));

        // 原子占坑：避免 Redis 异常或并发 tick 叠多个 Timer → 看起来像 1 秒连抢
        if (!$this->tryMarkGrabBusy($taskId, (int)ceil($delaySec) + 20)) {
            return;
        }
        // 先占住「下一次」：即使 Timer 异常，也不会立刻再排
        $this->setGrabNextAt($taskId, $now + $delaySec + $coolSec);

        error_log(sprintf(
            '[RP_AUTO] grab schedule task=%d packet=%d uid=%d delay=%.1fs cool=%.1fs (cfg %d-%dms)',
            $taskId,
            $packetId,
            $uid,
            $delaySec,
            $coolSec,
            (int)round($this->grabDelayBounds($task)[0]),
            (int)round($this->grabDelayBounds($task)[1])
        ));

        $taskSnapshot = $task;
        Timer::add($delaySec, function () use ($taskId, $packetId, $uid, $groupId, $taskSnapshot, $coolSec) {
            try {
                $this->doGrabOnce($taskId, $packetId, $uid, $groupId);
                // 不再立刻换号连抢；下一次由冷却 + tick 再排，保证间隔
            } catch (\Throwable $e) {
                $this->touchError($taskId, 'grab u' . $uid . ' p' . $packetId . ': ' . $e->getMessage());
            } finally {
                $cool = $coolSec > 0 ? $coolSec : $this->randomGrabDelaySec($taskSnapshot);
                $this->setGrabNextAt($taskId, microtime(true) + $cool);
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
     * 只发整数元；群 rp_fixed_amount 优先；再夹到群 rp_min/max（亦取整）。
     */
    protected function resolveSendAmount(array $task, array $group)
    {
        $groupFixed = (float)($group['rp_fixed_amount'] ?? 0);
        if ($groupFixed > 0) {
            return (float)max(1, (int)round($groupFixed));
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

        // 只发整数元
        $minInt = max(1, (int)round($min));
        $maxInt = max($minInt, (int)round($max));
        if ($minInt === $maxInt) {
            $amount = (float)$minInt;
        } else {
            $amount = (float)random_int($minInt, $maxInt);
        }

        $gMin = (float)($group['rp_min_amount'] ?? 0);
        $gMax = (float)($group['rp_max_amount'] ?? 0);
        if ($gMin > 0) {
            $gMinInt = max(1, (int)round($gMin));
            if ($amount < $gMinInt) {
                $amount = (float)$gMinInt;
            }
        }
        if ($gMax > 0) {
            $gMaxInt = max(1, (int)round($gMax));
            if ($amount > $gMaxInt) {
                $amount = (float)$gMaxInt;
            }
        }
        return (float)max(1, (int)round($amount));
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
                return false;
            }
            if ($this->isSoftGrabError($msg)) {
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
        error_log(sprintf(
            '[RP_AUTO] grab ok task=%d packet=%d uid=%d frozen=%.2f',
            $taskId,
            $packetId,
            $uid,
            $frozen
        ));
        return true;
    }

    /**
     * 每个待领包：从未领过且红宝够赔付/冻结的 grab_user_ids 里随机选 1 个去抢。
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
            $candidates = [];
            foreach ($uids as $uid) {
                if ($uid <= 0) {
                    continue;
                }
                $exists = Db::fetch(
                    'SELECT id FROM ' . Db::table('chat_red_packet_records')
                    . ' WHERE packet_id=? AND user_id=? LIMIT 1',
                    [$packetId, $uid]
                );
                if ($exists) {
                    continue;
                }
                // 与真人同一套：余额不够潜在赔付则不选该 UID
                if (!$this->redPackets->canAffordGrabCompensate($uid, $packet)) {
                    continue;
                }
                $candidates[] = $uid;
            }
            if (!$candidates) {
                continue;
            }
            $pick = $candidates[random_int(0, count($candidates) - 1)];
            return ['packet_id' => $packetId, 'user_id' => $pick];
        }
        return null;
    }

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
     * 群内最新一笔已结算接龙包，是否仍在等待「抢最少」用户续发。
     */
    protected function hasPendingRelay($groupId)
    {
        $groupId = (int)$groupId;
        if ($groupId <= 0) {
            return false;
        }
        try {
            $row = Db::fetch(
                'SELECT r.compensate_status AS cs FROM ' . Db::table('chat_red_packets') . ' p'
                . ' INNER JOIN ' . Db::table('chat_red_packet_records') . ' r'
                . ' ON r.packet_id=p.id AND r.is_worst=1'
                . ' WHERE p.group_id=? AND p.scope_type=2 AND p.packet_type=5 AND p.status=5'
                . ' ORDER BY p.id DESC LIMIT 1',
                [$groupId]
            );
            if ($row && (int)($row['cs'] ?? 0) === 1) {
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
    }
}
