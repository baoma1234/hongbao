<?php
/**
 * 恢复频道群（默认 70/71/72/77）里被撤回/后台删除的图片视频（status=2/3 → 1）
 *
 * 说明：
 * - cleanup 硬删的记录无法靠本脚本恢复（需 binlog）
 * - 本脚本只把仍在库里的图/视频软删状态改回可见
 *
 * 用法：
 *   php scripts/restore_protected_group_media_status.php
 *   php scripts/restore_protected_group_media_status.php --execute
 *   php scripts/restore_protected_group_media_status.php --use-project-env --execute
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$protect = [70, 71, 72, 77];
$execute = false;
$useProjectEnv = false;
foreach ($argv as $i => $arg) {
    if ($i === 0) {
        continue;
    }
    if ($arg === '--execute') {
        $execute = true;
        continue;
    }
    if ($arg === '--use-project-env') {
        $useProjectEnv = true;
        continue;
    }
    if (preg_match('/^--protect-groups=(.*)$/', $arg, $m)) {
        $protect = [];
        foreach (preg_split('/[,\s]+/', trim($m[1])) as $p) {
            $id = (int)$p;
            if ($id > 0) {
                $protect[] = $id;
            }
        }
    }
}

if ($useProjectEnv) {
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
echo 'db_host=' . ($cfg['db']['host'] ?? '') . "\n";

$msgTable = Im\Support\Db::table('chat_messages');
$in = implode(',', array_map('intval', $protect));
$rows = Im\Support\Db::fetchAll(
    "SELECT id, msg_id, group_id, msg_type, status, content, createtime
     FROM {$msgTable}
     WHERE group_id IN ({$in})
       AND msg_type IN (4, 5)
       AND status IN (2, 3)
     ORDER BY id ASC"
);
echo 'protect_groups=' . $in . ' soft_deleted_media=' . count($rows) . ' mode=' . ($execute ? 'EXECUTE' : 'DRY-RUN') . "\n";
foreach ($rows as $r) {
    echo 'id=' . $r['id'] . ' g=' . $r['group_id'] . ' type=' . $r['msg_type'] . ' status=' . $r['status']
        . ' msg_id=' . $r['msg_id'] . ' ' . mb_substr((string)$r['content'], 0, 40) . "\n";
}
if ($execute && $rows) {
    $ids = array_map(static fn($r) => (int)$r['id'], $rows);
    $n = Im\Support\Db::exec(
        "UPDATE {$msgTable} SET status=1 WHERE id IN (" . implode(',', $ids) . ") AND status IN (2,3)"
    );
    echo "updated={$n}\n";
} else {
    echo "updated=0 (dry-run)\n";
}
