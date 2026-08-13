<?php
/**
 * 资金流水冷归档：fa_fans_ledger → fa_fans_ledger_archive
 *
 * 安全规则：
 * - 默认 DRY-RUN（只统计）；加 --execute 才搬迁
 * - 永不搬迁「每个用户最新一条」流水（对账 / hongbao_after 依赖）
 * - 按 createtime cutoff；保留原 id（便于追溯）
 * - 分批 INSERT…SELECT + DELETE，事务内完成
 *
 * Usage:
 *   php scripts/archive_fans_ledger.php --days=90
 *   php scripts/archive_fans_ledger.php --days=90 --execute
 *   php scripts/archive_fans_ledger.php --days=180 --batch=2000 --execute
 *
 * cron 建议（每月 1 号 03:20）：
 *   20 3 1 * * cd /path/to/project && php scripts/archive_fans_ledger.php --days=90 --execute >> runtime/log/ledger_archive.log 2>&1
 */
$imRoot = dirname(__DIR__) . '/im-server';
require $imRoot . '/vendor/autoload.php';
$cfg = require $imRoot . '/config/app.php';
Im\Support\Db::init($cfg['db']);

$opts = [
    'days'     => 90,
    'batch'    => 1000,
    'execute'  => false,
    'sleep_ms' => 30,
];
foreach ($argv ?? [] as $i => $arg) {
    if ($i === 0) {
        continue;
    }
    if ($arg === '--execute') {
        $opts['execute'] = true;
        continue;
    }
    if ($arg === '--help' || $arg === '-h') {
        echo "Usage: php archive_fans_ledger.php [--days=90] [--batch=1000] [--sleep-ms=30] [--execute]\n";
        exit(0);
    }
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) {
        $opts['days'] = max(30, (int)$m[1]); // 最少保留 30 天热数据
        continue;
    }
    if (preg_match('/^--batch=(\d+)$/', $arg, $m)) {
        $opts['batch'] = max(100, min(5000, (int)$m[1]));
        continue;
    }
    if (preg_match('/^--sleep-ms=(\d+)$/', $arg, $m)) {
        $opts['sleep_ms'] = max(0, (int)$m[1]);
    }
}

$days = (int)$opts['days'];
$batch = (int)$opts['batch'];
$execute = (bool)$opts['execute'];
$sleepUs = ((int)$opts['sleep_ms']) * 1000;
$cutoff = time() - ($days * 86400);

$hot = Im\Support\Db::table('fans_ledger');
$arc = Im\Support\Db::table('fans_ledger_archive');

echo '[' . date('Y-m-d H:i:s') . "] ledger archive days={$days} cutoff="
    . date('Y-m-d H:i:s', $cutoff)
    . ' mode=' . ($execute ? 'EXECUTE' : 'DRY-RUN')
    . " batch={$batch}\n";

ensureArchiveTable($hot, $arc);

$candidateSql = "
SELECT COUNT(*) AS c
FROM {$hot} l
INNER JOIN (
  SELECT user_id, MAX(id) AS max_id
  FROM {$hot}
  GROUP BY user_id
) m ON m.user_id = l.user_id
WHERE l.createtime < ?
  AND l.id < m.max_id
";
$candidates = (int)(Im\Support\Db::fetch($candidateSql, [$cutoff])['c'] ?? 0);
$hotTotal = (int)(Im\Support\Db::fetch("SELECT COUNT(*) AS c FROM {$hot}")['c'] ?? 0);
$arcTotal = (int)(Im\Support\Db::fetch("SELECT COUNT(*) AS c FROM {$arc}")['c'] ?? 0);

echo "hot_total={$hotTotal} archive_total={$arcTotal} candidates={$candidates}\n";

if ($candidates <= 0) {
    echo "OK nothing to archive\n";
    exit(0);
}

if (!$execute) {
    // 抽样展示
    $samples = Im\Support\Db::fetchAll("
SELECT l.id, l.user_id, l.type, l.createtime
FROM {$hot} l
INNER JOIN (
  SELECT user_id, MAX(id) AS max_id FROM {$hot} GROUP BY user_id
) m ON m.user_id = l.user_id
WHERE l.createtime < ? AND l.id < m.max_id
ORDER BY l.id ASC
LIMIT 10
", [$cutoff]) ?: [];
    echo "--- sample (first 10) ---\n";
    foreach ($samples as $s) {
        echo sprintf(
            "  id=%s uid=%s type=%s at=%s\n",
            $s['id'],
            $s['user_id'],
            $s['type'],
            date('Y-m-d H:i:s', (int)$s['createtime'])
        );
    }
    echo "DRY-RUN done. Re-run with --execute to move {$candidates} rows.\n";
    exit(0);
}

$moved = 0;
$lastId = 0;
$cols = hotColumnList($hot);
$colList = '`' . implode('`, `', $cols) . '`';

while (true) {
    $rows = Im\Support\Db::fetchAll("
SELECT l.id
FROM {$hot} l
INNER JOIN (
  SELECT user_id, MAX(id) AS max_id FROM {$hot} GROUP BY user_id
) m ON m.user_id = l.user_id
WHERE l.createtime < ?
  AND l.id < m.max_id
  AND l.id > ?
ORDER BY l.id ASC
LIMIT {$batch}
", [$cutoff, $lastId]);
    if (!$rows) {
        break;
    }
    $ids = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $ids[] = $id;
        $lastId = $id;
    }
    $in = implode(',', $ids);
    $now = time();
    try {
        Im\Support\Db::begin();
        // 已在冷表则跳过插入（幂等重跑）
        Im\Support\Db::exec(
            "INSERT IGNORE INTO {$arc} ({$colList}, `archived_at`)
             SELECT {$colList}, {$now} FROM {$hot} WHERE id IN ({$in})"
        );
        $deleted = (int)Im\Support\Db::exec("DELETE FROM {$hot} WHERE id IN ({$in})");
        Im\Support\Db::commit();
        $moved += $deleted;
        echo "  batch last_id={$lastId} deleted={$deleted} moved_total={$moved}\n";
    } catch (\Throwable $e) {
        Im\Support\Db::rollBack();
        fwrite(STDERR, 'FAIL batch last_id=' . $lastId . ' ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    if ($sleepUs > 0) {
        usleep($sleepUs);
    }
}

$hotAfter = (int)(Im\Support\Db::fetch("SELECT COUNT(*) AS c FROM {$hot}")['c'] ?? 0);
$arcAfter = (int)(Im\Support\Db::fetch("SELECT COUNT(*) AS c FROM {$arc}")['c'] ?? 0);
echo "OK moved={$moved} hot_after={$hotAfter} archive_after={$arcAfter}\n";
exit(0);

function ensureArchiveTable($hot, $arc)
{
    $exists = false;
    try {
        Im\Support\Db::fetch("SELECT 1 AS ok FROM {$arc} LIMIT 1");
        $exists = true;
    } catch (\Throwable $e) {
        $exists = false;
    }
    if (!$exists) {
        Im\Support\Db::exec("CREATE TABLE IF NOT EXISTS {$arc} LIKE {$hot}");
        echo "OK created {$arc} (LIKE hot)\n";
    }

    $hasArchived = Im\Support\Db::fetch("SHOW COLUMNS FROM {$arc} LIKE 'archived_at'");
    if (!$hasArchived) {
        Im\Support\Db::exec(
            "ALTER TABLE {$arc} ADD COLUMN `archived_at` int unsigned NOT NULL DEFAULT 0 COMMENT 'archived unix' AFTER `createtime`"
        );
        echo "OK added archived_at on {$arc}\n";
    }
    try {
        Im\Support\Db::exec("ALTER TABLE {$arc} ADD KEY `idx_archived_at` (`archived_at`)");
    } catch (\Throwable $e) {
    }
    try {
        Im\Support\Db::exec("ALTER TABLE {$arc} ADD KEY `idx_createtime_id` (`createtime`,`id`)");
    } catch (\Throwable $e) {
    }
}

function hotColumnList($hot)
{
    $rows = Im\Support\Db::fetchAll("SHOW COLUMNS FROM {$hot}") ?: [];
    $cols = [];
    foreach ($rows as $r) {
        $cols[] = (string)$r['Field'];
    }
    if (!$cols) {
        throw new RuntimeException('cannot read hot ledger columns');
    }
    return $cols;
}
