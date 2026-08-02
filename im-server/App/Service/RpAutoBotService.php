<?php

namespace Im\Service;

use Im\Support\Db;
use Im\Support\PushBus;
use Im\Support\RedisClient;
use Workerman\Timer;

/**
 * 红包自动发/抢（Workerman worker0 内跑，替代 php think redpacket:auto）
 *
 * 规则：
 * - 发包：群内无待领取包时才发；埋雷雷号每发随机 0-9
 * - 续发：拼手气抢完结算后由 RedPacketService::trySendRobotNextRound（扣最差→发包人→续发）
 * - 抢包：每轮只安排 1 次，延迟 5～15 秒（任务可配置），避免连抢
 */
class RpAutoBotService
{
    const HEARTBEAT_KEY = 'rp_auto:ws_active';
    const TASK_LOCK_PREFIX = 'rp_auto:lock:';
    const GRAB_BUSY_PREFIX = 'rp_auto:grab_busy:';
    const TASK_CACHE_TTL = 8;

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
     * 仅当群内没有待领取包时发包（拼手气/埋雷通用）
     */
    protected function maybeSend(array $task)
    {
        $taskId = (int)$task['id'];
        $groupId = (int)$task['group_id'];
        $interval = max(5, (int)($task['interval_sec'] ?? 60));
        $last = (int)($task['last_send_time'] ?? 0);
        if ($last > 0 && (time() - $last) < $interval) {
            return ['sent' => false, 'packet_id' => 0];
        }
        $maxDay = (int)($task['max_per_day'] ?? 0);
        if ($maxDay > 0 && (int)($task['today_count'] ?? 0) >= $maxDay) {
            return ['sent' => false, 'packet_id' => 0];
        }
        if ($this->countOpenPackets($groupId) > 0) {
            return ['sent' => false, 'packet_id' => 0];
        }

        $sendUid = (int)($task['send_user_id'] ?? 0);
        if ($sendUid <= 0) {
            throw new \RuntimeException('未配置发包用户ID');
        }
        $amount = round((float)($task['total_amount'] ?? 0), 2);
        $count = (int)($task['total_count'] ?? 0);
        if ($amount <= 0 || $count <= 0) {
            throw new \RuntimeException('金额/个数无效');
        }

        $packetType = (int)($task['packet_type'] ?? 2);
        if (!in_array($packetType, [1, 2, 3], true)) {
            $packetType = 2;
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
                $uids = $this->groups->onlineMemberIds($groupId);
                if ($uids) {
                    PushBus::toUsers($uids, 'group.message', ['message' => $msg]);
                }
            } catch (\Throwable $e) {
            }
        }

        Db::exec(
            'UPDATE ' . Db::table('chat_rp_auto_task')
            . ' SET last_send_time=?, last_packet_id=?, today_count=today_count+1, last_error=?, updatetime=? WHERE id=?',
            [time(), $packetId, '', time(), $taskId]
        );
        $this->bustTaskCache();

        error_log(sprintf(
            '[RP_AUTO] send ok task=%d group=%d type=%d mine=%d packet=%d',
            $taskId,
            $groupId,
            $packetType,
            $mineDigit,
            $packetId
        ));

        return ['sent' => true, 'packet_id' => $packetId];
    }

    /**
     * 每任务同时最多 1 次待执行抢包；间隔 5～15 秒（可配置）
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

        $packetIds = [];
        if ((int)$preferPacketId > 0) {
            $packetIds[] = (int)$preferPacketId;
        }
        foreach ($this->listOpenPacketIds($groupId, 8) as $pid) {
            if (!in_array($pid, $packetIds, true)) {
                $packetIds[] = $pid;
            }
        }
        if (!$packetIds) {
            return;
        }

        $pair = $this->pickGrabPair($packetIds, $uids);
        if (!$pair) {
            return;
        }

        $minMs = (int)($task['grab_delay_min_ms'] ?? 5000);
        $maxMs = (int)($task['grab_delay_max_ms'] ?? 15000);
        if ($minMs < 1000) {
            $minMs = 5000;
        }
        if ($maxMs < $minMs) {
            $maxMs = $minMs;
        }
        // 默认 / 过短配置抬到 5～15 秒，避免连抢
        if ($maxMs < 5000) {
            $minMs = 5000;
            $maxMs = 15000;
        }
        $delaySec = ($minMs + ($maxMs > $minMs ? random_int(0, $maxMs - $minMs) : 0)) / 1000.0;
        $delaySec = max(1.0, min(60.0, $delaySec));

        $this->markGrabBusy($taskId, (int)ceil($delaySec) + 20);
        $packetId = $pair['packet_id'];
        $uid = $pair['user_id'];

        Timer::add($delaySec, function () use ($taskId, $packetId, $uid, $groupId) {
            try {
                $this->doGrabOnce($taskId, $packetId, $uid, $groupId);
            } catch (\Throwable $e) {
                $this->touchError($taskId, 'grab u' . $uid . ' p' . $packetId . ': ' . $e->getMessage());
            } finally {
                $this->clearGrabBusy($taskId);
            }
        }, [], false);
    }

    protected function doGrabOnce($taskId, $packetId, $uid, $groupId)
    {
        $packetId = (int)$packetId;
        $uid = (int)$uid;
        if ($packetId <= 0 || $uid <= 0) {
            return;
        }
        $exists = Db::fetch(
            'SELECT id FROM ' . Db::table('chat_red_packet_records')
            . ' WHERE packet_id=? AND user_id=? LIMIT 1',
            [$packetId, $uid]
        );
        if ($exists) {
            return;
        }
        $open = Db::fetch(
            'SELECT id, remain_count, status FROM ' . Db::table('chat_red_packets')
            . ' WHERE id=? LIMIT 1',
            [$packetId]
        );
        if (!$open || (int)$open['status'] !== 1 || (int)$open['remain_count'] <= 0) {
            return;
        }

        try {
            $result = $this->redPackets->grab($packetId, $uid);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if ($this->isSoftGrabError($msg)) {
                return;
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
                $uids = $this->groups->onlineMemberIds((int)($packet['group_id'] ?? $groupId));
                if ($uids) {
                    PushBus::toUsers($uids, 'redpacket.update', $event);
                }
            } catch (\Throwable $e) {
            }
        }
        error_log(sprintf('[RP_AUTO] grab ok task=%d packet=%d uid=%d', $taskId, $packetId, $uid));
    }

    protected function pickGrabPair(array $packetIds, array $uids)
    {
        shuffle($uids);
        foreach ($packetIds as $packetId) {
            $packetId = (int)$packetId;
            if ($packetId <= 0) {
                continue;
            }
            foreach ($uids as $uid) {
                $uid = (int)$uid;
                $exists = Db::fetch(
                    'SELECT id FROM ' . Db::table('chat_red_packet_records')
                    . ' WHERE packet_id=? AND user_id=? LIMIT 1',
                    [$packetId, $uid]
                );
                if ($exists) {
                    continue;
                }
                return ['packet_id' => $packetId, 'user_id' => $uid];
            }
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

    /** @return int[] */
    protected function listOpenPacketIds($groupId, $limit = 8)
    {
        $limit = max(1, min(20, (int)$limit));
        $rows = Db::fetchAll(
            'SELECT id FROM ' . Db::table('chat_red_packets')
            . ' WHERE group_id=? AND scope_type=2 AND status=1 AND remain_count>0'
            . ' ORDER BY id DESC LIMIT ' . $limit,
            [(int)$groupId]
        );
        $out = [];
        foreach ($rows ?: [] as $r) {
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
            return false;
        }
    }

    protected function markGrabBusy($taskId, $ttl)
    {
        try {
            RedisClient::conn()->setex(
                RedisClient::key(self::GRAB_BUSY_PREFIX . (int)$taskId),
                max(5, (int)$ttl),
                (string)time()
            );
        } catch (\Throwable $e) {
        }
    }

    protected function clearGrabBusy($taskId)
    {
        try {
            RedisClient::conn()->del(RedisClient::key(self::GRAB_BUSY_PREFIX . (int)$taskId));
        } catch (\Throwable $e) {
        }
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
