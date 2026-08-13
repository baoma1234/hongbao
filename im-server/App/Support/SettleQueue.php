<?php

namespace Im\Support;

/**
 * 红包结算持久队列 + DLQ
 *
 * - Redis LIST im:settle_queue：待结算 packet_id（崩溃后仍可由 cron 消费）
 * - 表 fa_chat_settle_dlq：多次失败后落库，便于运维排查（非硬删资金）
 *
 * 与现有 Timer(0.05s) + status=2 扫库并存；幂等结算保证重复消费安全。
 */
class SettleQueue
{
    const QUEUE_KEY = 'settle_queue';
    const ATTEMPT_PREFIX = 'settle:attempt:';
    const ATTEMPT_TTL = 86400;
    const MAX_ATTEMPTS = 8;
    const DLQ_TABLE = 'chat_settle_dlq';

    /** @var bool|null */
    protected static $tableReady = null;

    public static function enqueue($packetId, $reason = 'finish')
    {
        $packetId = (int)$packetId;
        if ($packetId <= 0) {
            return false;
        }
        try {
            $r = RedisClient::conn();
            // 去重：同 id 已在队尾附近时仍允许少量重复，消费侧幂等
            $r->lPush(RedisClient::key(self::QUEUE_KEY), (string)$packetId);
            // 防止无限膨胀（异常堆积时保留最近 2 万）
            $r->lTrim(RedisClient::key(self::QUEUE_KEY), 0, 19999);
            return true;
        } catch (\Throwable $e) {
            error_log('[SETTLE_Q] enqueue fail packet=' . $packetId . ' ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @return int[] packet ids（最多 $limit 个）
     */
    public static function pop($limit = 30)
    {
        $limit = max(1, min(100, (int)$limit));
        $ids = [];
        try {
            $r = RedisClient::conn();
            $key = RedisClient::key(self::QUEUE_KEY);
            for ($i = 0; $i < $limit; $i++) {
                $raw = $r->rPop($key);
                if ($raw === false || $raw === null || $raw === '') {
                    break;
                }
                $id = (int)$raw;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        } catch (\Throwable $e) {
            error_log('[SETTLE_Q] pop fail ' . $e->getMessage());
        }
        return array_values($ids);
    }

    public static function depth()
    {
        try {
            return (int)RedisClient::conn()->lLen(RedisClient::key(self::QUEUE_KEY));
        } catch (\Throwable $e) {
            return -1;
        }
    }

    public static function clearAttempts($packetId)
    {
        $packetId = (int)$packetId;
        if ($packetId <= 0) {
            return;
        }
        try {
            RedisClient::conn()->del(RedisClient::key(self::ATTEMPT_PREFIX . $packetId));
        } catch (\Throwable $e) {
        }
        self::resolveDlq($packetId);
    }

    /**
     * 记录失败并写入/更新 DLQ；默认不回队（靠 status=2 扫库 + 下次 enqueue 兜底，避免风暴）。
     *
     * @return array{attempts:int,dlq:bool,dead:bool}
     */
    public static function recordFailure($packetId, $error = '')
    {
        $packetId = (int)$packetId;
        $out = ['attempts' => 0, 'dlq' => false, 'dead' => false];
        if ($packetId <= 0) {
            return $out;
        }
        $error = mb_substr(trim((string)$error), 0, 500);
        $attempts = 1;
        try {
            $r = RedisClient::conn();
            $ak = RedisClient::key(self::ATTEMPT_PREFIX . $packetId);
            $attempts = (int)$r->incr($ak);
            $r->expire($ak, self::ATTEMPT_TTL);
        } catch (\Throwable $e) {
            $attempts = self::MAX_ATTEMPTS;
        }
        $out['attempts'] = $attempts;
        self::upsertDlq($packetId, $attempts, $error);
        $out['dlq'] = true;
        $out['dead'] = ($attempts >= self::MAX_ATTEMPTS);
        if ($out['dead']) {
            error_log('[SETTLE_Q] DLQ dead packet=' . $packetId . ' attempts=' . $attempts . ' err=' . $error);
        }
        return $out;
    }

    public static function openDlqCount()
    {
        self::ensureTable();
        try {
            $row = Db::fetch(
                'SELECT COUNT(*) AS c FROM ' . Db::table(self::DLQ_TABLE) . ' WHERE status=0'
            );
            return (int)($row['c'] ?? 0);
        } catch (\Throwable $e) {
            return -1;
        }
    }

    protected static function resolveDlq($packetId)
    {
        self::ensureTable();
        try {
            Db::exec(
                'UPDATE ' . Db::table(self::DLQ_TABLE)
                . ' SET status=1, updatetime=? WHERE packet_id=? AND status=0',
                [time(), (int)$packetId]
            );
        } catch (\Throwable $e) {
        }
    }

    protected static function upsertDlq($packetId, $attempts, $error)
    {
        self::ensureTable();
        $now = time();
        try {
            $table = Db::table(self::DLQ_TABLE);
            $exists = Db::fetch(
                'SELECT id FROM ' . $table . ' WHERE packet_id=? LIMIT 1',
                [$packetId]
            );
            if ($exists) {
                Db::exec(
                    'UPDATE ' . $table
                    . ' SET attempts=?, last_error=?, status=0, updatetime=? WHERE packet_id=?',
                    [$attempts, $error, $now, $packetId]
                );
            } else {
                Db::exec(
                    'INSERT INTO ' . $table
                    . ' (packet_id,attempts,last_error,status,createtime,updatetime)'
                    . ' VALUES (?,?,?,?,?,?)',
                    [$packetId, $attempts, $error, 0, $now, $now]
                );
            }
        } catch (\Throwable $e) {
            error_log('[SETTLE_Q] dlq upsert fail packet=' . $packetId . ' ' . $e->getMessage());
        }
    }

    public static function ensureTable()
    {
        if (self::$tableReady === true) {
            return true;
        }
        if (self::$tableReady === false) {
            return false;
        }
        try {
            $table = Db::table(self::DLQ_TABLE);
            Db::exec(
                "CREATE TABLE IF NOT EXISTS {$table} (
                  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                  `packet_id` int unsigned NOT NULL,
                  `attempts` int unsigned NOT NULL DEFAULT 0,
                  `last_error` varchar(500) NOT NULL DEFAULT '',
                  `status` tinyint NOT NULL DEFAULT 0 COMMENT '0=open 1=resolved 2=ignored',
                  `createtime` int unsigned NOT NULL DEFAULT 0,
                  `updatetime` int unsigned NOT NULL DEFAULT 0,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uk_packet` (`packet_id`),
                  KEY `idx_status_time` (`status`,`updatetime`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='redpacket settle DLQ'"
            );
            self::$tableReady = true;
            return true;
        } catch (\Throwable $e) {
            self::$tableReady = false;
            error_log('[SETTLE_Q] ensureTable fail ' . $e->getMessage());
            return false;
        }
    }
}
