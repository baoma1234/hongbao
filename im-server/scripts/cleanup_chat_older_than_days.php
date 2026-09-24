<?php
/**
 * 清理 N 天前的 IM 聊天 / 红宝（已结束）数据 —— 供 CentOS cron 调用
 *
 * 安全规则：
 * - 不删资金流水 fa_fans_ledger
 * - 不删仍可抢的红包 status=1
 * - 只删已抢完/已过期退回/已关闭的红包及其领取记录
 * - 聊天消息按 createtime 删除；红包卡片仅在对应红包已结束（或主单已不存在）时再删
 * - 默认不清理频道群 70/71/72/77（可用 --protect-groups= 覆盖，--protect-groups= 置空表示不保护）
 *
 * 用法：
 *   php scripts/cleanup_chat_older_than_days.php --days=7
 *   php scripts/cleanup_chat_older_than_days.php --days=7 --execute
 *   php scripts/cleanup_chat_older_than_days.php --days=7 --execute --batch=2000
 *   php scripts/cleanup_chat_older_than_days.php --days=7 --protect-groups=70,71,72,77
 *
 * cron（每天 04:10）：
 *   10 4 * * * cd /www/wwwroot/你的项目/im-server && bash scripts/cleanup_chat_cron.sh
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

/** 频道群：历史图/视频长期保留 */
const DEFAULT_PROTECT_GROUP_IDS = [70, 71, 72, 77];

$opts = [
    'days' => 7,
    'batch' => 1000,
    'execute' => false,
    'sleep_ms' => 50,
    'protect_groups' => DEFAULT_PROTECT_GROUP_IDS,
    'use_project_env' => false,
];
foreach ($argv as $i => $arg) {
    if ($i === 0) {
        continue;
    }
    if ($arg === '--execute') {
        $opts['execute'] = true;
        continue;
    }
    if ($arg === '--use-project-env') {
        $opts['use_project_env'] = true;
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
    if (preg_match('/^--protect-groups=(.*)$/', $arg, $m)) {
        $raw = trim((string)$m[1]);
        $ids = [];
        if ($raw !== '') {
            foreach (preg_split('/[,\s]+/', $raw) as $part) {
                $id = (int)$part;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }
        $opts['protect_groups'] = array_values(array_unique($ids));
        continue;
    }
    if ($arg === '--help' || $arg === '-h') {
        echo "Usage: php cleanup_chat_older_than_days.php [--days=7] [--batch=1000] [--sleep-ms=50] [--protect-groups=70,71,72,77] [--use-project-env] [--execute]\n";
        echo "  --protect-groups=   永不清理的群 ID，逗号分隔；传空则不保护任何群\n";
        echo "  --use-project-env   用项目根 .env 的数据库（忽略 im-server/config/local.php）\n";
        exit(0);
    }
}

if (!empty($opts['use_project_env'])) {
    $envFile = dirname($root) . DIRECTORY_SEPARATOR . '.env';
    $ini = is_file($envFile) ? parse_ini_file($envFile, true) : [];
    $d = is_array($ini['database'] ?? null) ? $ini['database'] : [];
    $cfg = [
        'db' => [
            'host'     => (string)($d['hostname'] ?? '127.0.0.1'),
            'port'     => (int)($d['hostport'] ?? 3306),
            'database' => (string)($d['database'] ?? ''),
            'username' => (string)($d['username'] ?? ''),
            'password' => (string)($d['password'] ?? ''),
            'charset'  => 'utf8mb4',
            'prefix'   => (string)($d['prefix'] ?? 'fa_'),
        ],
    ];
} else {
    $cfg = require $root . '/config/app.php';
}
Im\Support\Db::init($cfg['db']);
echo 'db_host=' . ($cfg['db']['host'] ?? '') . ' db=' . ($cfg['db']['database'] ?? '') . "\n";

$days = (int)$opts['days'];
$batch = (int)$opts['batch'];
$execute = (bool)$opts['execute'];
$sleepUs = ((int)$opts['sleep_ms']) * 1000;
$cutoff = time() - ($days * 86400);
$protectIds = array_values(array_unique(array_filter(array_map('intval', (array)$opts['protect_groups']))));
$protectIn = $protectIds ? implode(',', $protectIds) : '';
$protectSqlMsg = $protectIn !== ''
    ? " AND NOT (group_id IN ({$protectIn}) OR (conversation_type=2 AND conversation_id IN ('" . implode("','", array_map('strval', $protectIds)) . "')))"
    : '';
$protectSqlPkt = $protectIn !== ''
    ? " AND NOT (group_id IN ({$protectIn}) OR (scope_type=2 AND conversation_id IN ('" . implode("','", array_map('strval', $protectIds)) . "')))"
    : '';

$msgTable = Im\Support\Db::table('chat_messages');
$pktTable = Im\Support\Db::table('chat_red_packets');
$recTable = Im\Support\Db::table('chat_red_packet_records');

echo '[' . date('Y-m-d H:i:s') . "] cleanup days={$days} cutoff={$cutoff}(" . date('Y-m-d H:i:s', $cutoff) . ') mode=' . ($execute ? 'EXECUTE' : 'DRY-RUN') . " batch={$batch}\n";
echo 'protect_groups=' . ($protectIn !== '' ? $protectIn : '(none)') . "\n";

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
    "SELECT COUNT(*) AS c FROM {$pktTable} WHERE createtime < ? AND status IN (2,3,4){$protectSqlPkt}",
    [$cutoff]
);
echo "red_packets finished older than {$days}d: {$pktCandidates}\n";

$deletedPackets = 0;
$deletedRecords = 0;
$skippedProtectPackets = 0;
$lastId = 0;
while (true) {
    $rows = Im\Support\Db::fetchAll(
        "SELECT id, group_id, conversation_id, scope_type FROM {$pktTable}
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
        $lastId = $id;
        $gid = (int)($r['group_id'] ?? 0);
        $cid = (string)($r['conversation_id'] ?? '');
        if ($protectIds && (
            in_array($gid, $protectIds, true)
            || ((int)($r['scope_type'] ?? 0) === 2 && in_array((int)$cid, $protectIds, true))
        )) {
            $skippedProtectPackets++;
            continue;
        }
        $ids[] = $id;
    }
    if ($execute) {
        if ($ids) {
            $in = implode(',', $ids);
            $deletedRecords += (int)Im\Support\Db::exec("DELETE FROM {$recTable} WHERE packet_id IN ({$in})");
            $deletedPackets += deleteByIds($pktTable, $ids);
        }
        if ($sleepUs > 0) {
            usleep($sleepUs);
        }
    } else {
        $deletedPackets += count($ids);
        if ($ids) {
            $deletedRecords += countSql(
                "SELECT COUNT(*) AS c FROM {$recTable} WHERE packet_id IN (" . implode(',', $ids) . ")"
            );
        }
    }
    if (count($rows) < $batch) {
        break;
    }
}
echo ($execute ? 'deleted' : 'would_delete') . "_red_packets={$deletedPackets} records≈{$deletedRecords} skipped_protect_packets={$skippedProtectPackets}\n";

// ---- 2) 聊天消息 ----
$msgOld = countSql(
    "SELECT COUNT(*) AS c FROM {$msgTable} WHERE createtime < ?{$protectSqlMsg}",
    [$cutoff]
);
echo "chat_messages older than {$days}d (excl. protect): {$msgOld}\n";

$deletedMsgs = 0;
$skippedOpenRpMsgs = 0;
$skippedProtectMsgs = 0;
$lastId = 0;
while (true) {
    $rows = Im\Support\Db::fetchAll(
        "SELECT id, msg_type, extra, group_id, conversation_type, conversation_id FROM {$msgTable}
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
        $gid = (int)($r['group_id'] ?? 0);
        $cid = (string)($r['conversation_id'] ?? '');
        if ($protectIds && (
            in_array($gid, $protectIds, true)
            || ((int)($r['conversation_type'] ?? 0) === 2 && in_array((int)$cid, $protectIds, true))
        )) {
            $skippedProtectMsgs++;
            continue;
        }
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
echo ($execute ? 'deleted' : 'would_delete') . "_messages={$deletedMsgs} skipped_open_rp_msgs={$skippedOpenRpMsgs} skipped_protect_msgs={$skippedProtectMsgs}\n";
echo '[' . date('Y-m-d H:i:s') . "] done\n";
exit(0);
