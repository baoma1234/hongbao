<?php

namespace Im\Service;

use Im\Support\CatchLog;
use Im\Support\Db;
use Im\Support\RedisClient;
use Workerman\Timer;

/**
 * 红宝雨：到分钟发 N 个普通/随机红宝；每个机器人本轮有抢包上限；超时后领完剩余。
 */
class RpRainBotService
{
    const TASK_LOCK_PREFIX = 'rp_rain:lock:';
    const GRAB_BUSY_PREFIX = 'rp_rain:grab:';

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

        $count = 0;
        $markKey = '';
        if ($autoSend && $matched && (string)($task['last_slot_key'] ?? '') !== $slotKey) {
            $count = (int)$matched['count'];
            $markKey = $slotKey;
        } elseif ($force) {
            $use = $matched ?: ($slots[0] ?? null);
            $count = (int)($use['count'] ?? 1);
            $markKey = 'force ' . date('Y-m-d H:i:s');
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

        $ids = [];
        $err = '';
        $count = min(100, $count);
        for ($i = 0; $i < $count; $i++) {
            try {
                $pid = $this->sendOne($task);
                if ($pid > 0) {
                    $ids[] = $pid;
                }
            } catch (\Throwable $e) {
                $err = $e->getMessage();
                break;
            }
        }

        $now = time();
        if ($ids) {
            Db::exec(
                'UPDATE ' . Db::table('chat_rp_rain_task')
                . ' SET force_send=0, last_slot_key=?, last_round_start=?, round_packet_ids=?, last_packet_id=?, last_error=?, updatetime=? WHERE id=?',
                [
                    $markKey,
                    $now,
                    implode(',', $ids),
                    (int)$ids[count($ids) - 1],
                    $err !== '' ? mb_substr($err, 0, 250) : '',
                    $now,
                    $taskId,
                ]
            );
            error_log('[CRON][RP_RAIN] task ' . $taskId . ' sent ' . count($ids) . ' key=' . $markKey);
            return true;
        }

        Db::exec(
            'UPDATE ' . Db::table('chat_rp_rain_task')
            . ' SET force_send=0, last_error=?, updatetime=? WHERE id=?',
            [mb_substr($err !== '' ? $err : '未发出红包', 0, 250), $now, $taskId]
        );
        return false;
    }

    protected function sendOne(array $task)
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
        ]);
        $packetId = (int)($result['packet_id'] ?? ($result['packet']['id'] ?? 0));
        if ($packetId <= 0 && is_array($result['message'] ?? null)) {
            $extra = $result['message']['extra'] ?? [];
            if (is_string($extra)) {
                $extra = json_decode($extra, true) ?: [];
            }
            $packetId = (int)($extra['packet_id'] ?? 0);
        }
        if ($packetId <= 0) {
            throw new \RuntimeException('发包无包ID');
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
        $start = (int)$task['last_round_start'];
        $sweepMin = max(1, (int)($task['sweep_minutes'] ?? 3));
        $sweep = (time() - $start) >= ($sweepMin * 60);
        $cap = (int)($task['bot_grab_cap'] ?? 1);
        $counts = $this->grabCounts($packetIds);
        $taken = $this->takenMap($packetIds);
        $scheduled = 0;

        foreach ($open as $packet) {
            $pid = (int)$packet['id'];
            $remain = max(0, (int)($packet['remain_count'] ?? 0));
            $have = $taken[$pid] ?? [];
            $need = $remain;
            for ($n = 0; $n < $need; $n++) {
                $uid = $this->pickGrabber($uids, $have, $counts, $cap, $sweep);
                if ($uid <= 0) {
                    break;
                }
                $key = $taskId . ':' . $pid . ':' . $uid;
                if (isset($this->pending[$key]) || $this->isGrabBusy($taskId, $pid, $uid)) {
                    $have[$uid] = 1;
                    continue;
                }
                $delayMs = $this->delayMs($task, $sweep);
                if (!$this->tryMarkGrabBusy($taskId, $pid, $uid, (int)ceil($delayMs / 1000) + 20)) {
                    continue;
                }
                $this->pending[$key] = 1;
                $have[$uid] = 1;
                $counts[$uid] = (int)($counts[$uid] ?? 0) + 1;
                $scheduled++;
                $svc = $this;
                Timer::add($delayMs / 1000, function () use ($svc, $taskId, $groupId, $pid, $uid, $key) {
                    $svc->finishGrab($key, $taskId, $groupId, $pid, $uid);
                }, [], false);
                if ($scheduled >= 40) {
                    return;
                }
            }
        }
    }

    public function finishGrab($key, $taskId, $groupId, $packetId, $userId)
    {
        unset($this->pending[$key]);
        try {
            $this->ensureGrabber($groupId, $userId);
            $this->redPackets->grab($packetId, $userId, ['robot_send' => true, 'trusted_robot' => true]);
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
        if ($sweep) {
            return random_int(200, 900);
        }
        $min = max(1000, (int)($task['grab_delay_min_ms'] ?? 5000));
        $max = max($min, (int)($task['grab_delay_max_ms'] ?? 15000));
        return random_int($min, $max);
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
        return (int)$uids[random_int(0, count($uids) - 1)];
    }

    protected function actorUids(array $task, $which)
    {
        if ((int)($task['actor_mode'] ?? 1) === 2) {
            return $this->listBotUserIds();
        }
        $field = $which === 'send' ? 'send_user_ids' : 'grab_user_ids';
        return $this->parseUserIds((string)($task[$field] ?? ''));
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
            'SELECT id, remain_count, status FROM ' . Db::table('chat_red_packets')
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
