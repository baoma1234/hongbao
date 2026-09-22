<?php

namespace Im\Service;

use Im\Support\CatchLog;
use Im\Support\Db;
use Im\Support\PushBus;
use Im\Support\RedPacketUpdateBus;
use Im\Support\RedisClient;
use Workerman\Timer;

/**
 * 红宝雨：到分钟发 N 个普通/随机红宝；每个机器人本轮有抢包上限；超时后领完剩余。
 */
class RpRainBotService
{
    const TASK_LOCK_PREFIX = 'rp_rain:lock:';
    const GRAB_BUSY_PREFIX = 'rp_rain:grab:';
    /** 某包超时全领已排程，避免 tick 重复排导致同一秒连抢 */
    const SWEEP_SCHED_PREFIX = 'rp_rain:sweep_sched:';

    /** @var RedPacketService */
    protected $redPackets;
    /** @var GroupService */
    protected $groups;
    /** @var array<int,array>|null */
    protected $taskCache;
    /** @var int */
    protected $taskCacheAt = 0;
    /** @var array<string,int> */
    protected $pending = [];
    /** @var int[]|null */
    protected $botIds;
    /** @var float */
    protected $botIdsAt = 0.0;

    public function __construct(RedPacketService $redPackets, GroupService $groups)
    {
        $this->redPackets = $redPackets;
        $this->groups = $groups;
    }

    public function tick()
    {
        foreach ($this->loadTasks() as $task) {
            $taskId = (int)($task['id'] ?? 0);
            if ($taskId <= 0) {
                continue;
            }
            if (!$this->tryLock($taskId, 8)) {
                continue;
            }
            try {
                $fresh = $this->reload($taskId);
                if ($fresh) {
                    $this->runOne($fresh);
                }
            } catch (\Throwable $e) {
                $this->touchError($taskId, $e->getMessage());
                error_log('[CRON][RP_RAIN] task ' . $taskId . ' ' . $e->getMessage());
            } finally {
                $this->unlock($taskId);
            }
        }
    }

    protected function runOne(array $task)
    {
        $started = $this->maybeStart($task);
        if ($started) {
            $task = $this->reload((int)$task['id']) ?: $task;
        }
        if ((int)($task['auto_grab'] ?? 0) === 1) {
            $this->maybeGrab($task);
        }
    }

    protected function maybeStart(array $task)
    {
        $taskId = (int)$task['id'];
        $force = (int)($task['force_send'] ?? 0) === 1;
        $autoSend = (int)($task['auto_send'] ?? 0) === 1;
        $scheduleMode = ((int)($task['schedule_mode'] ?? 1) === 2) ? 2 : 1;

        $roundTarget = (int)($task['round_target'] ?? 0);
        $roundSent = (int)($task['round_sent'] ?? 0);
        // 本轮还在进行：上一包抢完再发下一包（不要求再撞开启时间）
        if ($roundTarget > 0 && $roundSent < $roundTarget) {
            if ($force) {
                Db::exec(
                    'UPDATE ' . Db::table('chat_rp_rain_task') . ' SET force_send=0, updatetime=? WHERE id=?',
                    [time(), $taskId]
                );
            }
            return $this->continueRound($task);
        }

        $count = 0;
        $markKey = '';
        if ($scheduleMode === 2) {
            $intervalMin = max(1, min(1440, (int)($task['interval_minutes'] ?? 5)));
            $intervalCount = max(1, min(100, (int)($task['interval_count'] ?? 1)));
            $bucketSec = (int)(floor(time() / ($intervalMin * 60)) * ($intervalMin * 60));
            $slotKey = '每' . $intervalMin . '分 ' . date('Y-m-d H:i', $bucketSec);
            $lastStart = (int)($task['last_round_start'] ?? 0);
            // 本时间桶已开过一轮（含手动）则不再自动叠开
            if ($autoSend && (string)($task['last_slot_key'] ?? '') !== $slotKey && $lastStart < $bucketSec) {
                $count = $intervalCount;
                $markKey = $slotKey;
            } elseif ($force) {
                $count = $intervalCount;
                $markKey = '手动 ' . date('Y-m-d H:i:s');
            }
        } else {
            $slots = $this->slots($task);
            $hm = date('H:i');
            $slotKey = date('Y-m-d') . ' ' . $hm;
            $matched = null;
            foreach ($slots as $slot) {
                if (($slot['time'] ?? '') === $hm) {
                    $matched = $slot;
                    break;
                }
            }
            if ($autoSend && $matched && (string)($task['last_slot_key'] ?? '') !== $slotKey) {
                $count = (int)$matched['count'];
                $markKey = $slotKey;
            } elseif ($force) {
                $use = $matched ?: ($slots[0] ?? null);
                $count = (int)($use['count'] ?? 1);
                $markKey = '手动 ' . date('Y-m-d H:i:s');
            }
        }
        if ($count <= 0) {
            if ($force) {
                Db::exec(
                    'UPDATE ' . Db::table('chat_rp_rain_task') . ' SET force_send=0, updatetime=? WHERE id=?',
                    [time(), $taskId]
                );
            }
            return false;
        }

        return $this->beginRound($task, min(100, $count), $markKey);
    }

    /**
     * 开启新一轮：只发第 1 包，其余等抢完再发。
     */
    protected function beginRound(array $task, $target, $markKey)
    {
        $taskId = (int)$task['id'];
        $target = max(1, min(100, (int)$target));
        $roundKey = $markKey !== '' ? $markKey : ('手动 ' . date('Y-m-d H:i:s'));
        $now = time();
        $err = '';
        $pid = 0;
        try {
            $pid = $this->sendOne($task, $roundKey);
        } catch (\Throwable $e) {
            $err = $e->getMessage();
        }
        if ($pid <= 0) {
            Db::exec(
                'UPDATE ' . Db::table('chat_rp_rain_task')
                . ' SET force_send=0, last_error=?, updatetime=? WHERE id=?',
                [mb_substr($err !== '' ? $err : '未发出红包', 0, 250), $now, $taskId]
            );
            return false;
        }

        Db::exec(
            'UPDATE ' . Db::table('chat_rp_rain_task')
            . ' SET force_send=0, last_slot_key=?, last_round_start=?, round_packet_ids=?, round_target=?, round_sent=1,'
            . ' last_packet_id=?, last_error=?, updatetime=? WHERE id=?',
            [
                $markKey,
                $now,
                (string)$pid,
                $target,
                $pid,
                '',
                $now,
                $taskId,
            ]
        );
        $task['round_target'] = $target;
        $this->pushRound($task, $roundKey, [$pid]);
        error_log('[CRON][RP_RAIN] task ' . $taskId . ' begin round target=' . $target . ' first=' . $pid . ' key=' . $markKey);
        return true;
    }

    /**
     * 本轮未发满：仅当上一包已抢完才发下一包。
     */
    protected function continueRound(array $task)
    {
        $taskId = (int)$task['id'];
        $target = (int)($task['round_target'] ?? 0);
        $sent = (int)($task['round_sent'] ?? 0);
        if ($target <= 0 || $sent >= $target) {
            return false;
        }
        $ids = $this->parseUserIds((string)($task['round_packet_ids'] ?? ''));
        $lastId = $ids ? (int)$ids[count($ids) - 1] : (int)($task['last_packet_id'] ?? 0);
        if ($lastId > 0 && !$this->isPacketFinished($lastId)) {
            return false;
        }

        $roundKey = (string)($task['last_slot_key'] ?? '');
        if ($roundKey === '') {
            $roundKey = 'round-' . $taskId;
        }
        $err = '';
        $pid = 0;
        try {
            $pid = $this->sendOne($task, $roundKey);
        } catch (\Throwable $e) {
            $err = $e->getMessage();
        }
        $now = time();
        if ($pid <= 0) {
            Db::exec(
                'UPDATE ' . Db::table('chat_rp_rain_task') . ' SET last_error=?, updatetime=? WHERE id=?',
                [mb_substr($err !== '' ? $err : '续发包失败', 0, 250), $now, $taskId]
            );
            return false;
        }

        $ids[] = $pid;
        $sent++;
        Db::exec(
            'UPDATE ' . Db::table('chat_rp_rain_task')
            . ' SET round_packet_ids=?, round_sent=?, last_packet_id=?, last_error=?, updatetime=? WHERE id=?',
            [implode(',', $ids), $sent, $pid, '', $now, $taskId]
        );
        $task['round_target'] = $target;
        $this->pushRound($task, $roundKey, $ids);
        error_log('[CRON][RP_RAIN] task ' . $taskId . ' continue ' . $sent . '/' . $target . ' packet=' . $pid);
        return true;
    }

    protected function isPacketFinished($packetId)
    {
        $packetId = (int)$packetId;
        if ($packetId <= 0) {
            return true;
        }
        try {
            $row = Db::fetch(
                'SELECT status, remain_count FROM ' . Db::table('chat_red_packets') . ' WHERE id=? LIMIT 1',
                [$packetId]
            );
        } catch (\Throwable $e) {
            return false;
        }
        if (!$row) {
            return true;
        }
        $remain = (int)($row['remain_count'] ?? 0);
        $status = (int)($row['status'] ?? 0);
        if ($remain <= 0) {
            return true;
        }
        // 1=可抢；其它状态视为已结束
        return $status !== 1 && $status !== 0;
    }

    protected function pushRound(array $task, $roundKey, array $ids)
    {
        $groupId = (int)($task['group_id'] ?? 0);
        $taskId = (int)($task['id'] ?? 0);
        if ($groupId <= 0 || !$ids) {
            return;
        }
        try {
            PushBus::toGroup($groupId, 'rp_rain.round', [
                'group_id'     => $groupId,
                'rain_task_id' => $taskId,
                'rain_round'   => (string)$roundKey,
                'packet_ids'   => array_values(array_map('intval', $ids)),
                'round_target' => (int)($task['round_target'] ?? 0),
                'round_sent'   => count($ids),
            ]);
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.RpRainBotService');
        }
    }

    protected function sendOne(array $task, $roundKey = '')
    {
        $groupId = (int)$task['group_id'];
        $uid = $this->pickUid($task, 'send');
        if ($uid <= 0) {
            throw new \RuntimeException('未配置发包用户');
        }
        $this->ensureSender($groupId, $uid);
        $packetType = (int)($task['packet_type'] ?? 1);
        if (!in_array($packetType, [1, 4], true)) {
            $packetType = 1;
        }
        $amount = $this->pickAmount($task);
        $count = max(1, min(100, (int)($task['total_count'] ?? 1)));
        $blessing = trim((string)($task['blessing'] ?? ''));
        if ($blessing === '') {
            $blessing = '恭喜发财';
        }
        $result = $this->redPackets->send([
            'from_user_id'  => $uid,
            'scope_type'    => 2,
            'group_id'      => $groupId,
            'packet_type'   => $packetType,
            'total_amount'  => $amount,
            'total_count'   => $count,
            'blessing'      => $blessing,
            'mine_digit'    => 0,
            'robot_send'    => true,
            'trusted_robot' => true,
            'rain'          => 1,
            'rain_task_id'  => (int)($task['id'] ?? 0),
            'rain_round'    => (string)$roundKey,
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
        if ($packetId <= 0) {
            throw new \RuntimeException('发包无包ID');
        }
        if (is_array($msg)) {
            try {
                PushBus::toGroup($groupId, 'group.message', ['message' => $msg]);
            } catch (\Throwable $e) {
                CatchLog::quiet($e, 'Service.RpRainBotService');
            }
        }
        return $packetId;
    }

    protected function maybeGrab(array $task)
    {
        $taskId = (int)$task['id'];
        $groupId = (int)$task['group_id'];
        $packetIds = $this->parseUserIds((string)($task['round_packet_ids'] ?? ''));
        if (!$packetIds || (int)($task['last_round_start'] ?? 0) <= 0) {
            return;
        }
        $open = $this->openPackets($packetIds);
        if (!$open) {
            return;
        }
        $uids = $this->actorUids($task, 'grab');
        if (!$uids) {
            $this->touchError($taskId, '没有可抢包的机器人');
            return;
        }

        $pct = (int)($task['bot_grab_pct'] ?? 80);
        if ($pct < 0) {
            $pct = 0;
        }
        if ($pct > 100) {
            $pct = 100;
        }
        $cap = (int)($task['bot_grab_cap'] ?? 1);
        $sweepMin = max(1, (int)($task['sweep_minutes'] ?? 3));
        $sweepSec = $sweepMin * 60;
        $now = time();
        $botUidSet = [];
        foreach ($uids as $u) {
            $botUidSet[(int)$u] = 1;
        }

        $botTakenByPacket = $this->botGrabShareCounts($packetIds, $botUidSet);
        $counts = $this->grabCounts($packetIds);
        $taken = $this->takenMap($packetIds);
        $scheduled = 0;

        foreach ($open as $packet) {
            $pid = (int)$packet['id'];
            $created = (int)($packet['createtime'] ?? 0);
            if ($created <= 0) {
                $created = (int)($task['last_round_start'] ?? $now);
            }
            // 超时按该包发出时间
            $packetSweep = ($now - $created) >= $sweepSec;
            $remain = max(0, (int)($packet['remain_count'] ?? 0));
            if ($remain <= 0) {
                continue;
            }
            $total = max(1, (int)($packet['total_count'] ?? $remain));
            $botTaken = (int)($botTakenByPacket[$pid] ?? 0);
            // 比例按「每个红包的份数」：80%、20 份 → 超时前机器人最多抢 16 份，留 4 份给真人
            $botShareMax = (int)floor($total * $pct / 100);
            if ($pct <= 0) {
                $botShareMax = 0;
            }
            if (!$packetSweep) {
                $left = $botShareMax - $botTaken;
                if ($left <= 0) {
                    continue;
                }
                // 超时前：每次 tick 只排 1 份
                $need = 1;
                $delayList = null;
            } else {
                // 超时全领：本包剩余一次排完，延迟互不撞秒（避免插库同一 createtime）
                if ($this->isSweepScheduled($taskId, $pid)) {
                    continue;
                }
                $need = $remain;
                $delayList = $this->sweepDelayMsList($task, $need);
                if (!$this->tryMarkSweepScheduled($taskId, $pid, $task)) {
                    continue;
                }
            }

            $have = $taken[$pid] ?? [];
            $planned = 0;
            for ($n = 0; $n < $need; $n++) {
                $uid = $this->pickGrabber($uids, $have, $counts, $cap, $packetSweep);
                if ($uid <= 0) {
                    break;
                }
                $key = $taskId . ':' . $pid . ':' . $uid;
                if (isset($this->pending[$key]) || $this->isGrabBusy($taskId, $pid, $uid)) {
                    $have[$uid] = 1;
                    continue;
                }
                if (is_array($delayList) && isset($delayList[$planned])) {
                    $delayMs = (int)$delayList[$planned];
                } else {
                    $delayMs = $this->delayMs($task, $packetSweep);
                }
                if ($delayMs < 1000) {
                    $delayMs = 1000;
                }
                if (!$this->tryMarkGrabBusy($taskId, $pid, $uid, (int)ceil($delayMs / 1000) + 30)) {
                    continue;
                }
                $this->pending[$key] = 1;
                $have[$uid] = 1;
                $counts[$uid] = (int)($counts[$uid] ?? 0) + 1;
                $botTakenByPacket[$pid] = $botTaken + 1;
                $scheduled++;
                $planned++;
                $svc = $this;
                Timer::add($delayMs / 1000, function () use ($svc, $taskId, $groupId, $pid, $uid, $key) {
                    $svc->finishGrab($key, $taskId, $groupId, $pid, $uid);
                }, [], false);
                if (!$packetSweep && $scheduled >= 20) {
                    return;
                }
            }
        }
    }

    /**
     * 本轮各红包中，机器人已抢份数。
     *
     * @param int[] $packetIds
     * @param array<int,int> $botUidSet
     * @return array<int,int> packet_id => count
     */
    protected function botGrabShareCounts(array $packetIds, array $botUidSet)
    {
        $packetIds = array_values(array_unique(array_filter(array_map('intval', $packetIds))));
        if (!$packetIds || !$botUidSet) {
            return [];
        }
        $in = implode(',', $packetIds);
        $rows = Db::fetchAll(
            'SELECT packet_id, user_id FROM ' . Db::table('chat_red_packet_records')
            . " WHERE packet_id IN ({$in})"
        ) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $pid = (int)($row['packet_id'] ?? 0);
            $uid = (int)($row['user_id'] ?? 0);
            if ($pid > 0 && $uid > 0 && isset($botUidSet[$uid])) {
                $out[$pid] = (int)($out[$pid] ?? 0) + 1;
            }
        }
        return $out;
    }

    public function finishGrab($key, $taskId, $groupId, $packetId, $userId)
    {
        unset($this->pending[$key]);
        try {
            $this->ensureGrabber($groupId, $userId);
            $result = $this->redPackets->grab($packetId, $userId, ['robot_send' => true, 'trusted_robot' => true]);
            $packet = is_array($result) ? ($result['packet'] ?? null) : null;
            if (is_array($packet)) {
                try {
                    RedPacketUpdateBus::publish(
                        [
                            'packet_id'  => (int)$packetId,
                            'grab'       => $result,
                            'by_user_id' => (int)$userId,
                        ],
                        ['group_id' => (int)($packet['group_id'] ?? $groupId)]
                    );
                } catch (\Throwable $e) {
                    CatchLog::quiet($e, 'Service.RpRainBotService');
                }
            }
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if ($msg !== '' && strpos($msg, 'packet closed') === false && strpos($msg, 'already') === false) {
                $this->touchError($taskId, $msg);
            }
        }
    }

    /**
     * @param int[] $uids
     * @param array<int,int> $have
     * @param array<int,int> $counts
     */
    protected function pickGrabber(array $uids, array $have, array $counts, $cap, $sweep)
    {
        $pool = $uids;
        shuffle($pool);
        foreach ($pool as $uid) {
            $uid = (int)$uid;
            if ($uid <= 0 || isset($have[$uid])) {
                continue;
            }
            if (!$sweep && $cap > 0 && (int)($counts[$uid] ?? 0) >= $cap) {
                continue;
            }
            return $uid;
        }
        return 0;
    }

    protected function delayMs(array $task, $sweep)
    {
        $min = max(1000, (int)($task['grab_delay_min_ms'] ?? 5000));
        $max = max($min, (int)($task['grab_delay_max_ms'] ?? 15000));
        if (!$sweep) {
            return random_int($min, $max);
        }
        $range = $this->sweepDelayRange($task);
        return random_int($range['lo'], $range['hi']);
    }

    /**
     * 超时全领延迟区间：[发包延迟中间, 超时ms + 发包延迟中间]
     * @return array{lo:int,hi:int,mid:int,timeout_ms:int}
     */
    protected function sweepDelayRange(array $task)
    {
        $min = max(1000, (int)($task['grab_delay_min_ms'] ?? 5000));
        $max = max($min, (int)($task['grab_delay_max_ms'] ?? 15000));
        $mid = (int)floor(($min + $max) / 2);
        if ($mid < 1000) {
            $mid = 1000;
        }
        $sweepMin = max(1, (int)($task['sweep_minutes'] ?? 3));
        $timeoutMs = $sweepMin * 60 * 1000;
        $lo = $mid;
        $hi = $timeoutMs + $mid;
        if ($hi < $lo) {
            $hi = $lo;
        }
        return ['lo' => $lo, 'hi' => $hi, 'mid' => $mid, 'timeout_ms' => $timeoutMs];
    }

    /**
     * 为本包剩余份数生成互不撞秒的延迟列表（已排序，单位 ms）。
     * @return int[]
     */
    protected function sweepDelayMsList(array $task, $count)
    {
        $count = max(1, (int)$count);
        $range = $this->sweepDelayRange($task);
        $lo = (int)$range['lo'];
        $hi = (int)$range['hi'];
        // 连续领取至少隔 1 秒，避免 createtime 同一秒
        $minGap = 1000 + random_int(200, 1800);
        $needSpan = ($count - 1) * $minGap;
        if ($hi - $lo < $needSpan) {
            $hi = $lo + $needSpan;
        }
        $delays = [];
        for ($i = 0; $i < $count; $i++) {
            $delays[] = random_int($lo, $hi);
        }
        sort($delays);
        for ($i = 1; $i < $count; $i++) {
            $floor = $delays[$i - 1] + $minGap;
            if ($delays[$i] < $floor) {
                $delays[$i] = $floor + random_int(0, 900);
            }
        }
        return $delays;
    }

    protected function isSweepScheduled($taskId, $packetId)
    {
        $taskId = (int)$taskId;
        $packetId = (int)$packetId;
        if ($taskId <= 0 || $packetId <= 0) {
            return false;
        }
        try {
            $raw = RedisClient::conn()->get(RedisClient::key(self::SWEEP_SCHED_PREFIX . $taskId . ':' . $packetId));
            return $raw !== false && $raw !== null && $raw !== '';
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function tryMarkSweepScheduled($taskId, $packetId, array $task)
    {
        $taskId = (int)$taskId;
        $packetId = (int)$packetId;
        if ($taskId <= 0 || $packetId <= 0) {
            return false;
        }
        $range = $this->sweepDelayRange($task);
        // TTL 覆盖最长延迟 + 缓冲
        $ttl = (int)ceil(((int)$range['hi'] + 60000) / 1000);
        if ($ttl < 120) {
            $ttl = 120;
        }
        try {
            $ok = RedisClient::conn()->set(
                RedisClient::key(self::SWEEP_SCHED_PREFIX . $taskId . ':' . $packetId),
                '1',
                ['nx', 'ex' => $ttl]
            );
            return (bool)$ok;
        } catch (\Throwable $e) {
            // Redis 异常时仍允许排一次，靠 pending/busy 兜底
            return true;
        }
    }

    protected function pickAmount(array $task)
    {
        $min = (int)round((float)($task['amount_min'] ?? 0));
        $max = (int)round((float)($task['amount_max'] ?? 0));
        if ($max < $min) {
            $max = $min;
        }
        $min = (int)(floor($min / 10) * 10);
        $max = (int)(floor($max / 10) * 10);
        if ($min < 10) {
            $min = 10;
        }
        if ($max < $min) {
            $max = $min;
        }
        if ($min === $max) {
            return $min;
        }
        $steps = (int)(($max - $min) / 10);
        return $min + random_int(0, max(0, $steps)) * 10;
    }

    protected function pickUid(array $task, $which)
    {
        $uids = $this->actorUids($task, $which);
        if (!$uids && $which === 'send') {
            $one = (int)($task['send_user_id'] ?? 0);
            return $one > 0 ? $one : 0;
        }
        if (!$uids) {
            return 0;
        }
        // 发包：固定用配置的第一个 UID（可后台编辑），不随机
        if ($which === 'send') {
            return (int)$uids[0];
        }
        return (int)$uids[random_int(0, count($uids) - 1)];
    }

    protected function actorUids(array $task, $which)
    {
        // 发包永远走配置的 send_user_ids，不用机器人账户随机发
        if ($which === 'send') {
            $ids = $this->parseUserIds((string)($task['send_user_ids'] ?? ''));
            if ($ids) {
                return $ids;
            }
            $one = (int)($task['send_user_id'] ?? 0);
            return $one > 0 ? [$one] : [];
        }
        if ((int)($task['actor_mode'] ?? 1) === 2) {
            return $this->listBotUserIds();
        }
        return $this->parseUserIds((string)($task['grab_user_ids'] ?? ''));
    }

    protected function slots(array $task)
    {
        $arr = json_decode((string)($task['time_slots'] ?? ''), true);
        if (!is_array($arr)) {
            return [];
        }
        $out = [];
        foreach ($arr as $row) {
            if (!is_array($row)) {
                continue;
            }
            $time = (string)($row['time'] ?? '');
            $count = (int)($row['count'] ?? 0);
            if (!preg_match('/^\d{2}:\d{2}$/', $time) || $count < 1) {
                continue;
            }
            $out[] = ['time' => $time, 'count' => min(100, $count)];
        }
        return $out;
    }

    /** @return array<int,array> */
    protected function openPackets(array $packetIds)
    {
        $packetIds = array_values(array_unique(array_filter(array_map('intval', $packetIds))));
        if (!$packetIds) {
            return [];
        }
        $in = implode(',', $packetIds);
        $rows = Db::fetchAll(
            'SELECT id, remain_count, total_count, status, createtime FROM ' . Db::table('chat_red_packets')
            . " WHERE id IN ({$in}) AND status=1 AND remain_count>0"
        );
        return is_array($rows) ? $rows : [];
    }

    /** @return array<int,int> */
    protected function grabCounts(array $packetIds)
    {
        $in = implode(',', array_map('intval', $packetIds));
        if ($in === '') {
            return [];
        }
        $rows = Db::fetchAll(
            'SELECT user_id, COUNT(*) AS c FROM ' . Db::table('chat_red_packet_records')
            . " WHERE packet_id IN ({$in}) GROUP BY user_id"
        ) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $uid = (int)($row['user_id'] ?? 0);
            if ($uid > 0) {
                $out[$uid] = (int)($row['c'] ?? 0);
            }
        }
        return $out;
    }

    /** @return array<int,array<int,int>> */
    protected function takenMap(array $packetIds)
    {
        $in = implode(',', array_map('intval', $packetIds));
        if ($in === '') {
            return [];
        }
        $rows = Db::fetchAll(
            'SELECT packet_id, user_id FROM ' . Db::table('chat_red_packet_records')
            . " WHERE packet_id IN ({$in})"
        ) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $pid = (int)($row['packet_id'] ?? 0);
            $uid = (int)($row['user_id'] ?? 0);
            if ($pid > 0 && $uid > 0) {
                $out[$pid][$uid] = 1;
            }
        }
        return $out;
    }

    protected function ensureSender($groupId, $userId)
    {
        $groupId = (int)$groupId;
        $userId = (int)$userId;
        if (!$this->groups->get($groupId)) {
            throw new \RuntimeException('群不存在: #' . $groupId);
        }
        if (!$this->groups->isMember($groupId, $userId) || $this->groups->memberRole($groupId, $userId) < 2) {
            $this->groups->addMembers($groupId, [$userId], 2);
        }
    }

    public function ensureGrabber($groupId, $userId)
    {
        $groupId = (int)$groupId;
        $userId = (int)$userId;
        if ($groupId <= 0 || $userId <= 0) {
            return;
        }
        if (!$this->groups->isMember($groupId, $userId)) {
            $this->groups->addMembers($groupId, [$userId], 1);
        }
    }

    protected function listBotUserIds()
    {
        $now = microtime(true);
        if (is_array($this->botIds) && ($now - $this->botIdsAt) < 8.0) {
            return $this->botIds;
        }
        $rows = Db::fetchAll(
            'SELECT user_id FROM ' . Db::table('fans_account')
            . " WHERE IFNULL(is_bot,0)=1 AND status='normal' ORDER BY id ASC LIMIT 300"
        ) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $uid = (int)($row['user_id'] ?? 0);
            if ($uid > 0) {
                $out[] = $uid;
            }
        }
        $this->botIds = $out;
        $this->botIdsAt = $now;
        return $out;
    }

    protected function parseUserIds($raw)
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', (string)$raw, -1, PREG_SPLIT_NO_EMPTY) as $p) {
            $id = (int)$p;
            if ($id > 0) {
                $out[$id] = $id;
            }
        }
        return array_values($out);
    }

    protected function loadTasks()
    {
        $now = time();
        if ($this->taskCache !== null && ($now - $this->taskCacheAt) < 2) {
            return $this->taskCache;
        }
        try {
            $rows = Db::fetchAll(
                'SELECT * FROM ' . Db::table('chat_rp_rain_task')
                . " WHERE status='normal' OR force_send=1 ORDER BY id ASC LIMIT 100"
            );
        } catch (\Throwable $e) {
            $rows = [];
        }
        $this->taskCache = is_array($rows) ? $rows : [];
        $this->taskCacheAt = $now;
        return $this->taskCache;
    }

    protected function reload($taskId)
    {
        $row = Db::fetch(
            'SELECT * FROM ' . Db::table('chat_rp_rain_task') . ' WHERE id=? LIMIT 1',
            [(int)$taskId]
        );
        return is_array($row) ? $row : null;
    }

    public function touchError($taskId, $msg)
    {
        $msg = mb_substr(trim((string)$msg), 0, 250);
        if ($msg === '') {
            return;
        }
        try {
            Db::exec(
                'UPDATE ' . Db::table('chat_rp_rain_task') . ' SET last_error=?, updatetime=? WHERE id=?',
                [$msg, time(), (int)$taskId]
            );
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.RpRainBotService');
        }
    }

    protected function tryLock($taskId, $ttl)
    {
        try {
            return (bool)RedisClient::conn()->set(
                RedisClient::key(self::TASK_LOCK_PREFIX . (int)$taskId),
                (string)time(),
                ['nx', 'ex' => max(2, (int)$ttl)]
            );
        } catch (\Throwable $e) {
            return true;
        }
    }

    protected function unlock($taskId)
    {
        try {
            RedisClient::conn()->del(RedisClient::key(self::TASK_LOCK_PREFIX . (int)$taskId));
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.RpRainBotService');
        }
    }

    protected function isGrabBusy($taskId, $packetId, $uid)
    {
        try {
            $v = RedisClient::conn()->get(RedisClient::key(self::GRAB_BUSY_PREFIX . (int)$taskId . ':' . (int)$packetId . ':' . (int)$uid));
            return $v !== false && $v !== null && $v !== '';
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function tryMarkGrabBusy($taskId, $packetId, $uid, $ttl)
    {
        try {
            return (bool)RedisClient::conn()->set(
                RedisClient::key(self::GRAB_BUSY_PREFIX . (int)$taskId . ':' . (int)$packetId . ':' . (int)$uid),
                '1',
                ['nx', 'ex' => max(3, (int)$ttl)]
            );
        } catch (\Throwable $e) {
            return true;
        }
    }
}
