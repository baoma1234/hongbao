<?php
/**
 * 鱼虾蟹局归档：波场锁定高度 / Block Hash
 * php scripts/patch_yxx_tron_v1.php
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
$table = $prefix . 'fans_yxx_rounds';

$exists = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table))->fetchColumn();
if (!$exists) {
    fwrite(STDERR, "SKIP {$table} missing (run patch_yxx_pool_v1.php first)\n");
    exit(0);
}

$col = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'tron_block_num'")->fetch(PDO::FETCH_ASSOC);
if (!$col) {
    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `tron_block_num` bigint unsigned NOT NULL DEFAULT 0 COMMENT '锁定波场高度' AFTER `hash_seed`");
    echo "OK   column {$table}.tron_block_num\n";
} else {
    echo "SKIP column {$table}.tron_block_num\n";
}

$col = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'tron_block_id'")->fetch(PDO::FETCH_ASSOC);
if (!$col) {
    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `tron_block_id` varchar(80) NOT NULL DEFAULT '' COMMENT '波场 Block Hash' AFTER `tron_block_num`");
    echo "OK   column {$table}.tron_block_id\n";
} else {
    echo "SKIP column {$table}.tron_block_id\n";
}

echo "DONE yxx tron v1\n";
