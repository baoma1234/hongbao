<?php
/**
 * 尾数牛牛：红包金额入账标记列
 * php scripts/patch_niuniu_packet_v1.php
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
$table = $prefix . 'chat_niuniu_shares';

function colExists(PDO $pdo, $table, $col)
{
    $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetch();
}

if (!colExists($pdo, $table, 'packet_paid')) {
    $pdo->exec(
        "ALTER TABLE `{$table}` ADD COLUMN `packet_paid` tinyint(1) NOT NULL DEFAULT 0 COMMENT '红包尾数金额是否已入账' AFTER `paid`"
    );
    echo "OK   col {$table}.packet_paid\n";
} else {
    echo "SKIP col {$table}.packet_paid\n";
}
