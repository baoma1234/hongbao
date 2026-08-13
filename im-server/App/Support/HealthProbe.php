<?php

namespace Im\Support;

/**
 * IM 深度健康探针：DB / Redis / WS Worker / Cron / 推送积压 / 待结算。
 * 供 HTTP /health/deep 与 CLI scripts/im_health_probe.php 共用。
 */
class HealthProbe
{
    /** Cron 心跳 Redis key（无前缀；RedisClient::key 会加 im:） */
    const CRON_ALIVE_KEY = 'cron:alive';
    const CRON_ALIVE_TTL = 30;

    /**
     * @return array{ok:bool,checks:array,metrics:array,ts:int}
     */
    public static function run(array $cfg = [])
    {
        $checks = [];
        $metrics = [];
        $ok = true;

        // ---- MySQL ----
        $dbOk = false;
        $dbErr = '';
        $t0 = microtime(true);
        try {
            $row = Db::fetch('SELECT 1 AS ok');
            $dbOk = ((int)($row['ok'] ?? 0) === 1);
            if (!$dbOk) {
                $dbErr = 'ping returned empty';
            }
        } catch (\Throwable $e) {
            $dbErr = $e->getMessage();
        }
        $metrics['db_ms'] = round((microtime(true) - $t0) * 1000, 2);
        $checks['db'] = [
            'ok'    => $dbOk,
            'error' => $dbErr,
        ];
        if (!$dbOk) {
            $ok = false;
        }

        // ---- Redis ----
        $redisOk = false;
        $redisErr = '';
        $t1 = microtime(true);
        try {
            $r = RedisClient::conn();
            $pong = $r->ping();
            $redisOk = ($pong === true || $pong === '+PONG' || $pong === 'PONG');
            if (!$redisOk) {
                $redisErr = 'unexpected ping: ' . var_export($pong, true);
            }
        } catch (\Throwable $e) {
            $redisErr = $e->getMessage();
        }
        $metrics['redis_ms'] = round((microtime(true) - $t1) * 1000, 2);
        $checks['redis'] = [
            'ok'    => $redisOk,
            'error' => $redisErr,
        ];
        if (!$redisOk) {
            $ok = false;
        }

        // ---- WS workers (PushBus alive keys) ----
        $workersAlive = [];
        $workersDead = [];
        $pushDepth = [];
        $pushTotal = 0;
        if ($redisOk) {
            try {
                $r = RedisClient::conn();
                $members = $r->sMembers(RedisClient::key('workers')) ?: [];
                foreach ($members as $wid) {
                    $wid = (string)$wid;
                    $alive = (bool)$r->exists(RedisClient::key('worker:' . $wid . ':alive'));
                    $len = (int)$r->lLen(RedisClient::key('w:' . $wid . ':push'));
                    $pushDepth[$wid] = $len;
                    $pushTotal += $len;
                    if ($alive) {
                        $workersAlive[] = $wid;
                    } else {
                        $workersDead[] = $wid;
                    }
                }
                sort($workersAlive, SORT_NUMERIC);
                sort($workersDead, SORT_NUMERIC);
            } catch (\Throwable $e) {
                $checks['workers'] = ['ok' => false, 'error' => $e->getMessage()];
                $ok = false;
            }
        }
        $wsExpected = max(1, (int)($cfg['websocket']['count'] ?? 1));
        $workerOk = $redisOk && count($workersAlive) > 0;
        // Windows 单进程时至少 1；Linux 允许多于预期（重启残留会 prune）
        if ($workerOk && count($workersAlive) < 1) {
            $workerOk = false;
        }
        $checks['workers'] = [
            'ok'            => $workerOk,
            'alive'         => $workersAlive,
            'dead'          => $workersDead,
            'expected_hint' => $wsExpected,
            'error'         => $workerOk ? '' : 'no alive WS workers in Redis',
        ];
        if (!$workerOk) {
            $ok = false;
        }
        $metrics['push_queue_total'] = $pushTotal;
        $metrics['push_queue_by_worker'] = $pushDepth;
        // 积压告警但不直接判死（短暂尖峰可接受）
        $checks['push_queue'] = [
            'ok'    => $pushTotal < 5000,
            'total' => $pushTotal,
            'error' => $pushTotal >= 5000 ? 'push backlog high' : '',
        ];
        if ($pushTotal >= 5000) {
            $ok = false;
        }

        // ---- Cron heartbeat ----
        $cronOk = false;
        $cronAge = null;
        if ($redisOk) {
            try {
                $r = RedisClient::conn();
                $raw = $r->get(RedisClient::key(self::CRON_ALIVE_KEY));
                if ($raw !== false && $raw !== null && $raw !== '') {
                    $ts = (int)$raw;
                    $cronAge = max(0, time() - $ts);
                    $cronOk = $cronAge <= self::CRON_ALIVE_TTL;
                }
            } catch (\Throwable $e) {
                $checks['cron'] = ['ok' => false, 'error' => $e->getMessage()];
            }
        }
        $checks['cron'] = [
            'ok'       => $cronOk,
            'age_sec'  => $cronAge,
            'ttl_hint' => self::CRON_ALIVE_TTL,
            'error'    => $cronOk ? '' : 'cron heartbeat missing or stale (start_cron.php?)',
        ];
        if (!$cronOk) {
            $ok = false;
        }

        // ---- Ports (best-effort TCP) ----
        $wsListen = (string)($cfg['websocket']['listen'] ?? 'websocket://0.0.0.0:17272');
        $httpListen = (string)($cfg['http_api']['listen'] ?? 'http://0.0.0.0:17273');
        $wsPort = self::portFromListen($wsListen, 17272);
        $httpPort = self::portFromListen($httpListen, 17273);
        $checks['port_ws'] = self::tcpCheck('127.0.0.1', $wsPort);
        $checks['port_http'] = self::tcpCheck('127.0.0.1', $httpPort);
        if (empty($checks['port_ws']['ok']) || empty($checks['port_http']['ok'])) {
            $ok = false;
        }

        // ---- Business backlog (DB) ----
        if ($dbOk) {
            try {
                $pendingSettle = Db::fetch(
                    'SELECT COUNT(*) AS c FROM ' . Db::table('chat_red_packets')
                    . ' WHERE status=2 AND remain_count<=0 AND packet_type IN (2,3,5)'
                );
                $pendingFreeze = Db::fetch(
                    'SELECT COUNT(*) AS c FROM ' . Db::table('chat_red_packet_records')
                    . ' WHERE freeze_status=1'
                );
                $metrics['pending_settle'] = (int)($pendingSettle['c'] ?? 0);
                $metrics['open_freezes'] = (int)($pendingFreeze['c'] ?? 0);
                $checks['pending_settle'] = [
                    'ok'    => $metrics['pending_settle'] < 200,
                    'count' => $metrics['pending_settle'],
                    'error' => $metrics['pending_settle'] >= 200 ? 'too many pending settlements' : '',
                ];
                if ($metrics['pending_settle'] >= 200) {
                    $ok = false;
                }
            } catch (\Throwable $e) {
                $checks['pending_settle'] = ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        // ---- Settle queue + DLQ ----
        if ($redisOk) {
            $sq = SettleQueue::depth();
            $metrics['settle_queue'] = $sq;
            $checks['settle_queue'] = [
                'ok'    => $sq >= 0 && $sq < 2000,
                'len'   => $sq,
                'error' => ($sq >= 2000) ? 'settle queue backlog' : (($sq < 0) ? 'settle queue read fail' : ''),
            ];
            if ($sq >= 2000 || $sq < 0) {
                $ok = false;
            }
        }
        if ($dbOk) {
            try {
                SettleQueue::ensureTable();
                $dlq = SettleQueue::openDlqCount();
                $metrics['settle_dlq_open'] = $dlq;
                $checks['settle_dlq'] = [
                    'ok'    => $dlq >= 0 && $dlq < 50,
                    'count' => $dlq,
                    'error' => ($dlq >= 50) ? 'too many settle DLQ rows' : (($dlq < 0) ? 'dlq read fail' : ''),
                ];
                if ($dlq >= 50 || $dlq < 0) {
                    $ok = false;
                }
            } catch (\Throwable $e) {
                $checks['settle_dlq'] = ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        if ($redisOk) {
            try {
                $r = RedisClient::conn();
                $nq = (int)$r->lLen(RedisClient::key('notify_queue'));
                $metrics['notify_queue'] = $nq;
                $checks['notify_queue'] = [
                    'ok'    => $nq < 2000,
                    'len'   => $nq,
                    'error' => $nq >= 2000 ? 'notify queue backlog' : '',
                ];
                if ($nq >= 2000) {
                    $ok = false;
                }
            } catch (\Throwable $e) {
                // optional queue
            }
        }

        return [
            'ok'      => $ok,
            'checks'  => $checks,
            'metrics' => $metrics,
            'ts'      => time(),
        ];
    }

    public static function touchCronAlive()
    {
        try {
            RedisClient::conn()->setex(
                RedisClient::key(self::CRON_ALIVE_KEY),
                self::CRON_ALIVE_TTL,
                (string)time()
            );
        } catch (\Throwable $e) {
        }
    }

    protected static function portFromListen($listen, $fallback)
    {
        if (preg_match('/:(\d+)\s*$/', (string)$listen, $m)) {
            return (int)$m[1];
        }
        return (int)$fallback;
    }

    /**
     * @return array{ok:bool,port:int,error:string}
     */
    protected static function tcpCheck($host, $port)
    {
        $port = (int)$port;
        $err = '';
        $errno = 0;
        $fp = @fsockopen($host, $port, $errno, $err, 1.5);
        if (is_resource($fp)) {
            fclose($fp);
            return ['ok' => true, 'port' => $port, 'error' => ''];
        }
        return ['ok' => false, 'port' => $port, 'error' => $err ?: ('errno ' . $errno)];
    }
}
