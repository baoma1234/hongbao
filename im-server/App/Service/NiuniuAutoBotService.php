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

    /**
     * 后台「立即执行」：同步购入/领取（不走 Timer 延迟）
     * @return array{buy:int,claim:int,settle:int,skip:int,errors:string[]}
     */
    public function forceRun($taskId = 0)
    {
        $stat = ['buy' => 0, 'claim' => 0, 'settle' => 0, 'skip' => 0, 'errors' => []];
        $this->taskCache = null;
        $this->taskCacheAt = 0;
        $tasks = $this->loadTasks();
        if ((int)$taskId > 0) {
            $one = null;
            try {
                $one = Db::fetch(
                    'SELECT * FROM ' . Db::table('chat_niuniu_auto_task') . ' WHERE id=? LIMIT 1',
                    [(int)$taskId]
                );
            } catch (\Throwable $e) {
                $one = null;
            }
            if (!$one) {
                $stat['errors'][] = '任务 #' . (int)$taskId . ' 不存在';
                return $stat;
            }
            if ((string)($one['status'] ?? '') !== 'normal') {
                $stat['errors'][] = '任务 #' . (int)$taskId . ' 未启用';
                return $stat;
            }
            $tasks = [$one];
        }
        if (!$tasks) {
            $stat['errors'][] = '没有启用中的尾数牛牛自动任务';
            return $stat;
        }
        foreach ($tasks as $task) {
            $tid = (int)($task['id'] ?? 0);
            try {
                $one = $this->forceRunOne($task);
                $stat['buy'] += (int)$one['buy'];
                $stat['claim'] += (int)$one['claim'];
                $stat['settle'] += (int)$one['settle'];
                $stat['skip'] += (int)$one['skip'];
                if (!empty($one['reason'])) {
                    $stat['errors'][] = 'task#' . $tid . ': ' . $one['reason'];
                }
            } catch (\Throwable $e) {
                $msg = trim($e->getMessage()) ?: get_class($e);
                $stat['errors'][] = 'task#' . $tid . ': ' . $msg;
                $this->touchError($tid, $msg);
            }
        }
        return $stat;
    }

    protected function forceRunOne(array $task)
    {
        $out = ['buy' => 0, 'claim' => 0, 'settle' => 0, 'skip' => 0, 'reason' => ''];
        $groupId = (int)($task['group_id'] ?? 0);
        if ($groupId <= 0) {
            throw new \RuntimeException('未配置群 ID');
        }
        if (!$this->niuniu->isGroupEnabled($groupId)) {
            throw new \RuntimeException('本群未开启尾数牛牛');
        }
        $round = Db::fetch(
            'SELECT * FROM ' . Db::table('chat_niuniu_rounds')
            . ' WHERE group_id=? AND status IN (?,?) ORDER BY id DESC LIMIT 1',
            [$groupId, NiuniuService::STATUS_BUYING, NiuniuService::STATUS_CLAIMING]
        );
        if (!$round) {
            $out['skip'] = 1;
            $out['reason'] = '无进行中对局（请先在群内点开始连开）';
            return $out;
        }
        $status = (int)$round['status'];
        if ($status === NiuniuService::STATUS_BUYING && (int)($task['auto_buy'] ?? 1) === 1) {
            $out['buy'] = $this->forceBuysNow($task, $round);
            if ($out['buy'] <= 0) {
                $out['skip']++;
                $out['reason'] = $out['reason'] ?: '本局未购入（可能已购过或余额不足）';
            }
        }
        // 强制再刷一轮状态
        $round = $this->niuniu->getRound((int)$round['id']) ?: $round;
        $status = (int)$round['status'];
        if ($status === NiuniuService::STATUS_CLAIMING && (int)($task['auto_claim'] ?? 1) === 1) {
            $claimed = $this->forceClaimsNow($task, $round);
            $out['claim'] = $claimed;
            $left = Db::fetch(
                'SELECT COUNT(*) AS c FROM ' . Db::table('chat_niuniu_shares')
                . ' WHERE round_id=? AND claimed=0',
                [(int)$round['id']]
            );
            if ((int)($left['c'] ?? 0) === 0) {
                if ($this->niuniu->settle((int)$round['id'])) {
                    $out['settle'] = 1;
                }
            }
        } elseif ($status === NiuniuService::STATUS_BUYING) {
            // 仅购入阶段
        } else {
            if ($out['buy'] <= 0 && $out['claim'] <= 0) {
                $out['skip']++;
                $out['reason'] = $out['reason'] ?: '当前阶段无可执行动作';
            }
        }
        return $out;
    }

    protected function forceBuysNow(array $task, array $round)
    {
        $taskId = (int)$task['id'];
        $roundId = (int)$round['id'];
        $groupId = (int)$round['group_id'];
        $this->clearBuyPlanned($taskId, $roundId);

        $pool = $this->resolveActorUids($task);
        if (!$pool) {
            throw new \RuntimeException('无可用购入账号');
        }
        $minN = max(1, (int)($task['buyer_count_min'] ?? 3));
        $maxN = max($minN, (int)($task['buyer_count_max'] ?? 8));
        $want = min(random_int($minN, $maxN), count($pool));
        shuffle($pool);
        $buyers = array_slice($pool, 0, $want);
        $this->markBuyPlanned($taskId, $roundId, max(120, (int)$round['buy_seconds'] + 60));

        $sharesMin = max(1, (int)($task['shares_min'] ?? 1));
        $sharesMax = max($sharesMin, (int)($task['shares_max'] ?? 3));
        $bought = 0;
        foreach ($buyers as $uid) {
            $count = random_int($sharesMin, $sharesMax);
            try {
                $before = Db::fetch(
                    'SELECT COUNT(*) AS c FROM ' . Db::table('chat_niuniu_shares')
                    . ' WHERE round_id=? AND user_id=?',
                    [$roundId, (int)$uid]
                );
                $this->doBuyOnce($taskId, $roundId, $groupId, (int)$uid, $count);
                $after = Db::fetch(
                    'SELECT COUNT(*) AS c FROM ' . Db::table('chat_niuniu_shares')
                    . ' WHERE round_id=? AND user_id=?',
                    [$roundId, (int)$uid]
                );
                if ((int)($after['c'] ?? 0) > (int)($before['c'] ?? 0)) {
                    $bought++;
                }
            } catch (\Throwable $e) {
                $this->touchError($taskId, 'buy u' . $uid . ': ' . $e->getMessage());
            }
        }
        Db::exec(
            'UPDATE ' . Db::table('chat_niuniu_auto_task')
            . ' SET last_round_id=?, updatetime=? WHERE id=?',
            [$roundId, time(), $taskId]
        );
        return $bought;
    }

    protected function forceClaimsNow(array $task, array $round)
    {
        $taskId = (int)$task['id'];
        $roundId = (int)$round['id'];
        $this->clearClaimBusy($taskId, $roundId);
        $single = ((int)($round['game_mode'] ?? 0) === NiuniuService::MODE_SINGLE);

        if ($single) {
            $rows = Db::fetchAll(
                'SELECT DISTINCT user_id FROM ' . Db::table('chat_niuniu_shares')
                . ' WHERE round_id=? AND claimed=0',
                [$roundId]
            );
        } else {
            $rows = Db::fetchAll(
                'SELECT id, user_id FROM ' . Db::table('chat_niuniu_shares')
                . ' WHERE round_id=? AND claimed=0 ORDER BY id ASC',
                [$roundId]
            );
        }
        if (!$rows) {
            return 0;
        }
        $actors = $this->resolveActorUids($task);
        if (!$actors) {
            return 0;
        }
        $actorMap = [];
        foreach ($actors as $a) {
            $actorMap[(int)$a] = true;
        }
        $n = 0;
        $queue = [];
        foreach ($rows as $r) {
            $uid = (int)$r['user_id'];
            if (!isset($actorMap[$uid])) {
                continue;
            }
            if ($single) {
                $queue[] = ['share_id' => 0, 'user_id' => $uid];
            } else {
                $queue[] = ['share_id' => (int)$r['id'], 'user_id' => $uid];
            }
        }
        $queue = $this->interleaveClaimTargets($queue);
        foreach ($queue as $t) {
            $uid = (int)$t['user_id'];
            try {
                $this->niuniu->claim($uid, $roundId, (int)$t['share_id']);
                $n++;
            } catch (\Throwable $e) {
                $this->touchError($taskId, 'claim u' . $uid . ': ' . $e->getMessage());
            }
        }
        $this->markClaimBusy($taskId, $roundId, max(60, (int)$round['claim_seconds'] + 30));
        return $n;
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

        $single = ((int)($round['game_mode'] ?? 0) === NiuniuService::MODE_SINGLE);
        $rows = Db::fetchAll(
            'SELECT id, user_id FROM ' . Db::table('chat_niuniu_shares')
            . ' WHERE round_id=? AND claimed=0 ORDER BY id ASC',
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
        $seenUser = [];
        foreach ($rows as $r) {
            $uid = (int)$r['user_id'];
            if (!isset($actorMap[$uid])) {
                continue;
            }
            // 单结果：每人只排一次领取（一次领完全部包）
            if ($single) {
                if (isset($seenUser[$uid])) {
                    continue;
                }
                $seenUser[$uid] = true;
                $targets[] = ['share_id' => 0, 'user_id' => $uid];
            } else {
                $targets[] = ['share_id' => (int)$r['id'], 'user_id' => $uid];
            }
        }
        if (!$targets) {
            return;
        }

        // 多号多包：按人轮询交错（A1→B1→A2→B2…），避免同一号连领
        $targets = $this->interleaveClaimTargets($targets);

        $delayMin = max(100, (int)($task['claim_delay_min_ms'] ?? 500));
        $delayMax = max($delayMin, (int)($task['claim_delay_max_ms'] ?? 5000));
        $busySec = max(
            60,
            (int)$round['claim_seconds'] + 30,
            (int)ceil((count($targets) * $delayMax) / 1000) + 30
        );
        $this->markClaimBusy($taskId, $roundId, $busySec);
        $accMs = 0;
        foreach ($targets as $t) {
            $accMs += random_int($delayMin, $delayMax);
            $delaySec = max(0.15, $accMs / 1000.0);
            $uid = (int)$t['user_id'];
            $sid = (int)$t['share_id'];
            Timer::add($delaySec, function () use ($taskId, $roundId, $uid, $sid) {
                try {
                    $this->niuniu->claim($uid, (int)$roundId, $sid);
                } catch (\Throwable $e) {
                    $this->touchError($taskId, 'claim u' . $uid . ' s' . $sid . ': ' . $e->getMessage());
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

    /**
     * 领取队列按用户轮询交错，例如：
     * 用户A 5包、用户B 5包 → A,B,A,B… 而不是 AAAAA 再 BBBBB
     *
     * @param array<int,array{share_id:int,user_id:int}> $targets
     * @return array<int,array{share_id:int,user_id:int}>
     */
    protected function interleaveClaimTargets(array $targets)
    {
        if (count($targets) <= 1) {
            return $targets;
        }
        $byUser = [];
        foreach ($targets as $t) {
            $uid = (int)$t['user_id'];
            if (!isset($byUser[$uid])) {
                $byUser[$uid] = [];
            }
            $byUser[$uid][] = $t;
        }
        if (count($byUser) <= 1) {
            return $targets;
        }
        $uids = array_keys($byUser);
        shuffle($uids);
        $out = [];
        $guard = 0;
        while ($byUser && $guard < 10000) {
            $guard++;
            $progress = false;
            foreach ($uids as $uid) {
                if (empty($byUser[$uid])) {
                    unset($byUser[$uid]);
                    continue;
                }
                $out[] = array_shift($byUser[$uid]);
                $progress = true;
                if (empty($byUser[$uid])) {
                    unset($byUser[$uid]);
                }
            }
            if (!$progress) {
                break;
            }
            $uids = array_keys($byUser);
        }
        return $out ?: $targets;
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

    protected function clearBuyPlanned($taskId, $roundId)
    {
        try {
            RedisClient::conn()->del(RedisClient::key(self::BUY_PLANNED_PREFIX . $taskId . ':' . $roundId));
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

    protected function clearClaimBusy($taskId, $roundId)
    {
        try {
            RedisClient::conn()->del(RedisClient::key(self::CLAIM_BUSY_PREFIX . $taskId . ':' . $roundId));
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
