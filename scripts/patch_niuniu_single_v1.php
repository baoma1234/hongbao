<?php
/**
 * 尾数牛牛：单结果玩法 + 全群默认开启
 * php scripts/patch_niuniu_single_v1.php
 */
$root = dirname(__DIR__);
$env = parse_ini_file($root . '/.env', true);
$d = $env['database'];
$pdo = new PDO(
    "mysql:host={$d['hostname']};dbname={$d['database']};charset=utf8mb4",
    $d['username'],
    $d['password'],
    [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4', PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$prefix = $d['prefix'] ?? 'fa_';

function colExists(PDO $pdo, $table, $col)
{
    $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetch();
}
function ensureCol(PDO $pdo, $table, $col, $ddl)
{
    if (colExists($pdo, $table, $col)) {
        echo "SKIP col {$table}.{$col}\n";
        return;
    }
    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$ddl}");
    echo "OK   col {$table}.{$col}\n";
}

$g = $prefix . 'chat_groups';
$rounds = $prefix . 'chat_niuniu_rounds';

ensureCol(
    $pdo,
    $rounds,
    'game_mode',
    "`game_mode` tinyint NOT NULL DEFAULT 1 COMMENT '1普通每份一结果 2单结果每用户一尾数' AFTER `status`"
);
ensureCol(
    $pdo,
    $g,
    'niuniu_loop_mode',
    "`niuniu_loop_mode` tinyint NOT NULL DEFAULT 1 COMMENT '连开玩法 1普通 2单结果' AFTER `niuniu_loop_starter`"
);

// 默认全群可开：改默认值 + 存量开启
try {
    $pdo->exec("ALTER TABLE `{$g}` MODIFY COLUMN `niuniu_enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT '尾数牛牛开关'");
    echo "OK   default niuniu_enabled=1\n";
} catch (Throwable $e) {
    echo "WARN modify default: " . $e->getMessage() . "\n";
}
$n = $pdo->exec("UPDATE `{$g}` SET niuniu_enabled=1 WHERE IFNULL(niuniu_enabled,0)=0");
echo "OK   enabled groups rows=" . (int)$n . "\n";

echo "DONE niuniu single v1\n";
