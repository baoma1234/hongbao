<?php
/**
 * 清理过期管理员日志（fa_admin_log），防止后台列表 OOM
 *
 * Usage:
 *   php scripts/cleanup_admin_log.php --days=30
 *   php scripts/cleanup_admin_log.php --days=30 --execute
 *   php scripts/cleanup_admin_log.php --keep=200000 --execute
 */
$imRoot = dirname(__DIR__) . '/im-server';
// Prefer ThinkPHP DB for fa_* tables
define('APP_PATH', __DIR__ . '/../application/');
require __DIR__ . '/../thinkphp/base.php';
\think\App::initCommon();

$opts = ['days' => 30, 'keep' => 0, 'batch' => 2000, 'execute' => false];
foreach ($argv ?? [] as $i => $arg) {
    if ($i === 0) {
        continue;
    }
    if ($arg === '--execute') {
        $opts['execute'] = true;
        continue;
    }
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) {
        $opts['days'] = max(7, (int)$m[1]);
        continue;
    }
    if (preg_match('/^--keep=(\d+)$/', $arg, $m)) {
        $opts['keep'] = max(0, (int)$m[1]);
        continue;
    }
    if (preg_match('/^--batch=(\d+)$/', $arg, $m)) {
        $opts['batch'] = max(100, min(10000, (int)$m[1]));
    }
}

$prefix = \think\Config::get('database.prefix') ?: 'fa_';
$table = $prefix . 'admin_log';
$total = (int)\think\Db::query("SELECT COUNT(*) AS c FROM `{$table}`")[0]['c'];
echo '[' . date('Y-m-d H:i:s') . "] admin_log total={$total} mode="
    . ($opts['execute'] ? 'EXECUTE' : 'DRY-RUN') . "\n";

$deleted = 0;
if ($opts['keep'] > 0 && $total > $opts['keep']) {
    $cutIdRow = \think\Db::query(
        "SELECT id FROM `{$table}` ORDER BY id DESC LIMIT 1 OFFSET " . (int)($opts['keep'] - 1)
    );
    $cutId = (int)($cutIdRow[0]['id'] ?? 0);
    $cand = $cutId > 0
        ? (int)\think\Db::query("SELECT COUNT(*) AS c FROM `{$table}` WHERE id < {$cutId}")[0]['c']
        : 0;
    echo "keep={$opts['keep']} cut_id={$cutId} candidates={$cand}\n";
    if ($opts['execute'] && $cutId > 0) {
        while (true) {
            $n = \think\Db::execute(
                "DELETE FROM `{$table}` WHERE id < ? ORDER BY id ASC LIMIT " . (int)$opts['batch'],
                [$cutId]
            );
            if ($n <= 0) {
                break;
            }
            $deleted += $n;
            echo "  deleted_batch={$n} total_deleted={$deleted}\n";
        }
    }
} else {
    $cutoff = time() - ((int)$opts['days'] * 86400);
    $cand = (int)\think\Db::query(
        "SELECT COUNT(*) AS c FROM `{$table}` WHERE createtime < ?",
        [$cutoff]
    )[0]['c'];
    echo "days={$opts['days']} cutoff=" . date('Y-m-d H:i:s', $cutoff) . " candidates={$cand}\n";
    if ($opts['execute'] && $cand > 0) {
        while (true) {
            $n = \think\Db::execute(
                "DELETE FROM `{$table}` WHERE createtime < ? ORDER BY id ASC LIMIT " . (int)$opts['batch'],
                [$cutoff]
            );
            if ($n <= 0) {
                break;
            }
            $deleted += $n;
            echo "  deleted_batch={$n} total_deleted={$deleted}\n";
        }
    }
}

$after = (int)\think\Db::query("SELECT COUNT(*) AS c FROM `{$table}`")[0]['c'];
echo "OK deleted={$deleted} after={$after}\n";
if (!$opts['execute']) {
    echo "DRY-RUN: re-run with --execute to delete\n";
}
