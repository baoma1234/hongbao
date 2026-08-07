<?php
/**
 * 清理 N 天前的 IM 聊天 / 红宝（已结束）数据 —— 供 CentOS cron 调用
 *
 * 安全规则：
 * - 不删资金流水 fa_fans_ledger
 * - 不删仍可抢的红包 status=1
 * - 只删已抢完/已过期退回/已关闭的红包及其领取记录
 * - 聊天消息按 createtime 删除；红包卡片仅在对应红包已结束（或主单已不存在）时再删
 *
 * 用法：
 *   php scripts/cleanup_chat_older_than_days.php --days=7
 *   php scripts/cleanup_chat_older_than_days.php --days=7 --execute
 *   php scripts/cleanup_chat_older_than_days.php --days=7 --execute --batch=2000
 *
 * cron（每天 04:10）：
 *   10 4 * * * cd /www/wwwroot/你的项目/im-server && bash scripts/cleanup_chat_cron.sh
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$cfg = require $root . '/config/app.php';
Im\Support\Db::init($cfg['db']);

$opts = [
    'days' => 7,
    'batch' => 1000,
    'execute' => false,
    'sleep_ms' => 50,
];
foreach ($argv as $i => $arg) {
    if ($i === 0) {
        continue;
    }
    if ($arg === '--execute') {
        $opts['execute'] = true;
        continue;
    }
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) {
        $opts['days'] = max(1, (int)$m[1]);
        continue;
    }
    if (preg_match('/^--batch=(\d+)$/', $arg, $m)) {
        $opts['batch'] = max(100, min(5000, (int)$m[1]));
        continue;
    }
    if (preg_match('/^--sleep-ms=(\d+)$/', $arg, $m)) {
        $opts['sleep_ms'] = max(0, (int)$m[1]);
        continue;
    }
    if ($arg === '--help' || $arg === '-h') {
        echo "Usage: php cleanup_chat_older_than_days.php [--days=7] [--batch=1000] [--sleep-ms=50] [--execute]\n";
        exit(0);
    }
}

$days = (int)$opts['days'];
$batch = (int)$opts['batch'];
$execute = (bool)$opts['execute'];
$sleepUs = ((int)$opts['sleep_ms']) * 1000;
$cutoff = time() - ($days * 86400);

$msgTable = Im\Support\Db::table('chat_messages');
$pktTable = Im\Support\Db::table('chat_red_packets');
$recTable = Im\Support\Db::table('chat_red_packet_records');

echo '[' . date('Y-m-d H:i:s') . "] cleanup days={$days} cutoff={$cutoff}(" . date('Y-m-d H:i:s', $cutoff) . ') mode=' . ($execute ? 'EXECUTE' : 'DRY-RUN') . " batch={$batch}\n";

function countSql($sql, array $bind = [])
{
    $row = Im\Support\Db::fetch($sql, $bind);
    return (int)($row['c'] ?? 0);
}

function deleteByIds($table, array $ids)
{
    if (!$ids) {
        return 0;
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return 0;
    }
    $in = implode(',', $ids);
    return (int)Im\Support\Db::exec("DELETE FROM {$table} WHERE id IN ({$in})");
}

// ---- 1) 已结束红包主单 + 领取记录 ----
$pktCandidates = countSql(
    "SELECT COUNT(*) AS c FROM {$pktTable} WHERE createtime < ? AND status IN (2,3,4)",
    [$cutoff]
);
echo "red_packets finished older than {$days}d: {$pktCandidates}\n";

$deletedPackets = 0;
$deletedRecords = 0;
$lastId = 0;
while (true) {
    $rows = Im\Support\Db::fetchAll(
        "SELECT id FROM {$pktTable}
         WHERE createtime < ? AND status IN (2,3,4) AND id > ?
         ORDER BY id ASC LIMIT {$batch}",
        [$cutoff, $lastId]
    );
    if (!$rows) {
        break;
    }
    $ids = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $ids[] = $id;
        $lastId = $id;
    }
    if ($execute) {
        $in = implode(',', $ids);
        $deletedRecords += (int)Im\Support\Db::exec("DELETE FROM {$recTable} WHERE packet_id IN ({$in})");
        $deletedPackets += deleteByIds($pktTable, $ids);
        if ($sleepUs > 0) {
            usleep($sleepUs);
        }
    } else {
        $deletedPackets += count($ids);
        $deletedRecords += countSql(
            "SELECT COUNT(*) AS c FROM {$recTable} WHERE packet_id IN (" . implode(',', $ids) . ")"
        );
    }
    if (count($ids) < $batch) {
        break;
    }
}
echo ($execute ? 'deleted' : 'would_delete') . "_red_packets={$deletedPackets} records≈{$deletedRecords}\n";

// ---- 2) 聊天消息 ----
$msgOld = countSql(
    "SELECT COUNT(*) AS c FROM {$msgTable} WHERE createtime < ?",
    [$cutoff]
);
echo "chat_messages older than {$days}d (rough): {$msgOld}\n";

$deletedMsgs = 0;
$skippedOpenRpMsgs = 0;
$lastId = 0;
while (true) {
    $rows = Im\Support\Db::fetchAll(
        "SELECT id, msg_type, extra FROM {$msgTable}
         WHERE createtime < ? AND id > ?
         ORDER BY id ASC LIMIT {$batch}",
        [$cutoff, $lastId]
    );
    if (!$rows) {
        break;
    }
    $delIds = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $lastId = $id;
        $msgType = (int)($r['msg_type'] ?? 1);
        if ($msgType === 2) {
            $extra = $r['extra'] ?? null;
            if (is_string($extra) && $extra !== '') {
                $extra = json_decode($extra, true);
            }
            $pid = 0;
            if (is_array($extra)) {
                $pid = (int)($extra['packet_id'] ?? $extra['id'] ?? $extra['red_packet_id'] ?? 0);
            }
            if ($pid > 0) {
                $st = Im\Support\Db::fetch("SELECT status FROM {$pktTable} WHERE id=? LIMIT 1", [$pid]);
                $status = $st ? (int)($st['status'] ?? 0) : 0;
                if ($status === 1) {
                    $skippedOpenRpMsgs++;
                    continue;
                }
            }
        }
        $delIds[] = $id;
    }
    if ($execute && $delIds) {
        $deletedMsgs += deleteByIds($msgTable, $delIds);
        if ($sleepUs > 0) {
            usleep($sleepUs);
        }
    } else {
        $deletedMsgs += count($delIds);
    }
    if (count($rows) < $batch) {
        break;
    }
}
echo ($execute ? 'deleted' : 'would_delete') . "_messages={$deletedMsgs} skipped_open_rp_msgs={$skippedOpenRpMsgs}\n";
echo '[' . date('Y-m-d H:i:s') . "] done\n";
exit(0);
