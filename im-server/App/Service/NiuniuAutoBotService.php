<?php

namespace Im\Service;

use Im\Support\Db;
use Im\Support\RedisClient;
use Workerman\Timer;

/**
 * 尾数牛牛自动购入 / 领取（cron 内跑）
 * - 随机人数购入、每人随机份数
 * - 开奖阶段自动领取；全部领完可提前结算
 */
class NiuniuAutoBotService
{
    const TASK_LOCK_PREFIX = 'nn_auto:lock:';
    const BUY_PLANNED_PREFIX = 'nn_auto:buy_planned:';
    const CLAIM_BUSY_PREFIX = 'nn_auto:claim_busy:';
    const TASK_CACHE_TTL = 8;

    /** @var NiuniuService */
    protected $niuniu;
    /** @var GroupService */
    protected $groups;
    /** @var array<int,array>|null */
    protected $taskCache;
    /** @var int */
    protected $taskCacheAt = 0;
    /** @var array|null */
    protected $botUidCache;
    /** @var float */
    protected $botUidCacheAt = 0.0;

    public function __construct(NiuniuService $niuniu, GroupService $groups)
    {
        $this->niuniu = $niuniu;
        $this->groups = $groups;
    }

    public function tick()
    {
        foreach ($this->loadTasks() as $task) {
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
                error_log('[NN_AUTO][task' . $taskId . '] ' . $e->getMessage());
            } finally {
                $this->unlock($taskId);
            }
        }
    }

    protected function runOne(array $task)
    {
        $groupId = (int)($task['group_id'] ?? 0);
        if ($groupId <= 0 || !$this->niuniu->isGroupEnabled($groupId)) {
            return;
        }

        $round = Db::fetch(
            'SELECT * FROM ' . Db::table('chat_niuniu_rounds')
            . ' WHERE group_id=? AND status IN (?,?) ORDER BY id DESC LIMIT 1',
            [$groupId, NiuniuService::STATUS_BUYING, NiuniuService::STATUS_CLAIMING]
        );
        if (!$round) {
            return;
        }

        $status = (int)$round['status'];
        if ($status === NiuniuService::STATUS_BUYING && (int)($task['auto_buy'] ?? 1) === 1) {
            $this->maybeScheduleBuys($task, $round);
        }
        if ($status === NiuniuService::STATUS_CLAIMING && (int)($task['auto_claim'] ?? 1) === 1) {
            $this->maybeScheduleClaims($task, $round);
        }
    }

    protected function maybeScheduleBuys(array $task, array $round)
    {
        $taskId = (int)$task['id'];
        $roundId = (int)$round['id'];
        $groupId = (int)$round['group_id'];
        if ($this->isBuyPlanned($taskId, $roundId)) {
            return;
        }
        // 购入快结束时不再塞人，避免刚买就被 close
        if (time() >= ((int)$round['buy_end_at'] - 3)) {
            return;
        }

        $pool = $this->resolveActorUids($task);
        if (!$pool) {
            $this->touchError($taskId, '无可用购入账号');
            return;
        }

        $minN = max(1, (int)($task['buyer_count_min'] ?? 3));
        $maxN = max($minN, (int)($task['buyer_count_max'] ?? 8));
        $want = random_int($minN, $maxN);
        $want = min($want, count($pool));

        shuffle($pool);
        $buyers = array_slice($pool, 0, $want);
        $this->markBuyPlanned($taskId, $roundId, max(120, (int)$round['buy_seconds'] + 60));

        $delayMin = max(200, (int)($task['buy_delay_min_ms'] ?? 800));
        $delayMax = max($delayMin, (int)($task['buy_delay_max_ms'] ?? 8000));
        $sharesMin = max(1, (int)($task['shares_min'] ?? 1));
        $sharesMax = max($sharesMin, (int)($task['shares_max'] ?? 3));

        $accMs = 0;
        foreach ($buyers as $uid) {
            $accMs += random_int($delayMin, $delayMax);
            $count = random_int($sharesMin, $sharesMax);
            $delaySec = max(0.2, $accMs / 1000.0);
            $uid = (int)$uid;
            Timer::add($delaySec, function () use ($taskId, $roundId, $groupId, $uid, $count) {
                try {
                    $this->doBuyOnce($taskId, $roundId, $groupId, $uid, $count);
                } catch (\Throwable $e) {
                    $this->touchError($taskId, 'buy u' . $uid . ': ' . $e->getMessage());
                }
            }, [], false);
        }

        Db::exec(
            'UPDATE ' . Db::table('chat_niuniu_auto_task')
            . ' SET last_round_id=?, updatetime=? WHERE id=?',
            [$roundId, time(), $taskId]
        );
    }

    protected function doBuyOnce($taskId, $roundId, $groupId, $uid, $count)
    {
        $round = $this->niuniu->getRound((int)$roundId);
        if (!$round || (int)$round['status'] !== NiuniuService::STATUS_BUYING) {
            return;
        }
        if (time() >= (int)$round['buy_end_at']) {
            return;
        }
        $this->ensureInGroup((int)$groupId, (int)$uid);
        // 已购入过则跳过（同一人只买一次）
        $exist = Db::fetch(
            'SELECT id FROM ' . Db::table('chat_niuniu_shares')
            . ' WHERE round_id=? AND user_id=? LIMIT 1',
            [(int)$roundId, (int)$uid]
        );
        if ($exist) {
            return;
        }
        $this->niuniu->buy((int)$uid, (int)$roundId, (int)$count);
    }

    protected function maybeScheduleClaims(array $task, array $round)
    {
        $taskId = (int)$task['id'];
        $roundId = (int)$round['id'];
        if ($this->isClaimBusy($taskId, $roundId)) {
            return;
        }

        $rows = Db::fetchAll(
            'SELECT DISTINCT user_id FROM ' . Db::table('chat_niuniu_shares')
            . ' WHERE round_id=? AND claimed=0',
            [$roundId]
        );
        if (!$rows) {
            // 全领完 → 提前结算开奖
            try {
                $this->niuniu->settle($roundId);
            } catch (\Throwable $e) {
            }
            return;
        }

        $actors = $this->resolveActorUids($task);
        if (!$actors) {
            return;
        }
        $actorMap = [];
        foreach ($actors as $a) {
            $actorMap[(int)$a] = true;
        }

        $targets = [];
        foreach ($rows as $r) {
            $uid = (int)$r['user_id'];
            if (isset($actorMap[$uid])) {
                $targets[] = $uid;
            }
        }
        if (!$targets) {
            return;
        }

        $this->markClaimBusy($taskId, $roundId, max(60, (int)$round['claim_seconds'] + 30));
        $delayMin = max(100, (int)($task['claim_delay_min_ms'] ?? 500));
        $delayMax = max($delayMin, (int)($task['claim_delay_max_ms'] ?? 5000));
        $accMs = 0;
        foreach ($targets as $uid) {
            $accMs += random_int($delayMin, $delayMax);
            $delaySec = max(0.15, $accMs / 1000.0);
            Timer::add($delaySec, function () use ($taskId, $roundId, $uid) {
                try {
                    $this->niuniu->claim((int)$uid, (int)$roundId);
                } catch (\Throwable $e) {
                    $this->touchError($taskId, 'claim u' . $uid . ': ' . $e->getMessage());
                }
                // 领完尝试结算
                try {
                    $left = Db::fetch(
                        'SELECT COUNT(*) AS c FROM ' . Db::table('chat_niuniu_shares')
                        . ' WHERE round_id=? AND claimed=0',
                        [(int)$roundId]
                    );
                    if ((int)($left['c'] ?? 0) === 0) {
                        $this->niuniu->settle((int)$roundId);
                    }
                } catch (\Throwable $e) {
                }
            }, [], false);
        }
    }

    /** @return int[] */
    protected function resolveActorUids(array $task)
    {
        $mode = ((int)($task['actor_mode'] ?? 2) === 1) ? 1 : 2;
        if ($mode === 1) {
            return $this->parseUserIds((string)($task['buy_user_ids'] ?? ''));
        }
        return $this->listBotUserIds();
    }

    protected function ensureInGroup($groupId, $userId)
    {
        $groupId = (int)$groupId;
        $userId = (int)$userId;
        if ($userId <= 0 || $groupId <= 0) {
            return;
        }
        if (!$this->groups->isMember($groupId, $userId)) {
            $this->groups->addMembers($groupId, [$userId], 1);
        }
    }

    /** @return int[] */
    protected function listBotUserIds($limit = 300)
    {
        $now = microtime(true);
        if (is_array($this->botUidCache) && ($now - $this->botUidCacheAt) < 8.0) {
            return $this->botUidCache;
        }
        $limit = max(1, min(500, (int)$limit));
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
        $this->botUidCache = $out;
        $this->botUidCacheAt = $now;
        return $out;
    }

    protected function parseUserIds($raw)
    {
        $raw = str_replace(["\xef\xbc\x8c", '、', '|', "\n", "\r"], ',', (string)$raw);
        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $p) {
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
        if ($this->taskCache !== null && ($now - $this->taskCacheAt) < self::TASK_CACHE_TTL) {
            return $this->taskCache;
        }
        try {
            $rows = Db::fetchAll(
                'SELECT * FROM ' . Db::table('chat_niuniu_auto_task')
                . " WHERE status='normal' ORDER BY id ASC LIMIT 200"
            );
        } catch (\Throwable $e) {
            $rows = [];
        }
        $this->taskCache = is_array($rows) ? $rows : [];
        $this->taskCacheAt = $now;
        return $this->taskCache;
    }

    protected function tryLock($taskId, $ttl = 4)
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
        }
    }

    protected function isBuyPlanned($taskId, $roundId)
    {
        try {
            $v = RedisClient::conn()->get(RedisClient::key(self::BUY_PLANNED_PREFIX . $taskId . ':' . $roundId));
            return $v !== false && $v !== null && $v !== '';
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function markBuyPlanned($taskId, $roundId, $ttl)
    {
        try {
            RedisClient::conn()->setex(
                RedisClient::key(self::BUY_PLANNED_PREFIX . $taskId . ':' . $roundId),
                max(30, (int)$ttl),
                '1'
            );
        } catch (\Throwable $e) {
        }
    }

    protected function isClaimBusy($taskId, $roundId)
    {
        try {
            $v = RedisClient::conn()->get(RedisClient::key(self::CLAIM_BUSY_PREFIX . $taskId . ':' . $roundId));
            return $v !== false && $v !== null && $v !== '';
        } catch (\Throwable $e) {
            return true;
        }
    }

    protected function markClaimBusy($taskId, $roundId, $ttl)
    {
        try {
            RedisClient::conn()->setex(
                RedisClient::key(self::CLAIM_BUSY_PREFIX . $taskId . ':' . $roundId),
                max(30, (int)$ttl),
                '1'
            );
        } catch (\Throwable $e) {
        }
    }

    protected function touchError($taskId, $msg)
    {
        $msg = mb_substr(trim((string)$msg), 0, 240);
        try {
            Db::exec(
                'UPDATE ' . Db::table('chat_niuniu_auto_task')
                . ' SET last_error=?, updatetime=? WHERE id=?',
                [$msg, time(), (int)$taskId]
            );
        } catch (\Throwable $e) {
        }
    }
}
