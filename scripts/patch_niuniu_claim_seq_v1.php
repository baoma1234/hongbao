<?php
/**
 * 尾数牛牛：领取序号（按真实领取顺序从 hash 派生尾数）
 * php scripts/patch_niuniu_claim_seq_v1.php
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

if (!colExists($pdo, $table, 'claim_seq')) {
    $pdo->exec(
        "ALTER TABLE `{$table}` ADD COLUMN `claim_seq` int unsigned NOT NULL DEFAULT 0 "
        . "COMMENT '领取序号(按领取时间从hash取值)' AFTER `claimed_at`, "
        . "ADD KEY `idx_round_claim_seq` (`round_id`,`claim_seq`)"
    );
    echo "OK   col {$table}.claim_seq\n";
} else {
    echo "SKIP col {$table}.claim_seq\n";
}

echo "DONE niuniu claim_seq v1\n";
