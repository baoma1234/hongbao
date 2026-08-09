<?php
/**
 * 尾数牛牛：接入波场区块哈希（TRC20/TRON）作随机源
 * php scripts/patch_niuniu_tron_v1.php
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

$rounds = $prefix . 'chat_niuniu_rounds';
$exists = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($rounds))->fetchColumn();
if (!$exists) {
    fwrite(STDERR, "ERR  table {$rounds} missing, run patch_niuniu_v1.php first\n");
    exit(1);
}

ensureCol(
    $pdo,
    $rounds,
    'tron_block_num',
    "`tron_block_num` bigint unsigned NOT NULL DEFAULT 0 COMMENT '锁定/开奖波场块高' AFTER `drand_url`"
);
ensureCol(
    $pdo,
    $rounds,
    'tron_block_id',
    "`tron_block_id` varchar(128) NOT NULL DEFAULT '' COMMENT '波场 Block Hash' AFTER `tron_block_num`"
);
ensureCol(
    $pdo,
    $rounds,
    'tron_status',
    "`tron_status` tinyint NOT NULL DEFAULT 0 COMMENT '0无 1锁定待开 2已开奖 3失败' AFTER `tron_block_id`"
);

echo "DONE niuniu tron columns\n";
