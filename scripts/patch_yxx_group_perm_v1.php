<?php
/**
 * 群聊鱼虾蟹：后台 per-group 开通权限（默认全关）
 * php scripts/patch_yxx_group_perm_v1.php
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
$g = $prefix . 'chat_groups';

function colExists(PDO $pdo, $table, $col)
{
    $st = $pdo->prepare('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '` LIKE ?');
    $st->execute([$col]);
    return (bool)$st->fetchColumn();
}

if (!colExists($pdo, $g, 'yxx_enabled')) {
    $pdo->exec("ALTER TABLE `{$g}` ADD COLUMN `yxx_enabled` tinyint(1) NOT NULL DEFAULT 0 COMMENT '鱼虾蟹群权限(后台)' AFTER `niuniu_desc`");
    echo "OK   column {$g}.yxx_enabled\n";
} else {
    echo "SKIP column {$g}.yxx_enabled\n";
}

echo "DONE yxx group perm v1\n";
