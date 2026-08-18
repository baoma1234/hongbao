<?php
/**
 * 鱼虾蟹万人场：红包雨 grants.paid（默认 1=已入账，新雨显式写 0 后惰性入账）
 * php scripts/patch_yxx_scale_v1.php
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
$table = $prefix . 'fans_yxx_rain_grants';

$exists = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table))->fetchColumn();
if (!$exists) {
    fwrite(STDERR, "SKIP {$table} missing (run patch_yxx_pool_v1.php first)\n");
    exit(0);
}

$col = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'paid'")->fetch(PDO::FETCH_ASSOC);
if (!$col) {
    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `paid` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1已入账 0待大厅惰性入账' AFTER `popup_seen`");
    echo "OK   column {$table}.paid\n";
} else {
    echo "SKIP column {$table}.paid\n";
}

$idx = $pdo->query("SHOW INDEX FROM `{$table}` WHERE Key_name='idx_user_paid'")->fetch(PDO::FETCH_ASSOC);
if (!$idx) {
    $pdo->exec("ALTER TABLE `{$table}` ADD KEY `idx_user_paid` (`user_id`,`paid`)");
    echo "OK   index {$table}.idx_user_paid\n";
} else {
    echo "SKIP index {$table}.idx_user_paid\n";
}

echo "DONE yxx scale v1\n";
