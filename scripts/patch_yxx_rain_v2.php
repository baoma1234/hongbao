<?php
/**
 * 鱼虾蟹红包雨 V2：奖池 settings JSON、grants.event+paid 索引
 * php scripts/patch_yxx_rain_v2.php
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

$state = $prefix . 'fans_yxx_pool_state';
$exists = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($state))->fetchColumn();
if ($exists) {
    $col = $pdo->query("SHOW COLUMNS FROM `{$state}` LIKE 'settings'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        $pdo->exec("ALTER TABLE `{$state}` ADD COLUMN `settings` text NULL COMMENT '大厅参数JSON，不含底栏开关' AFTER `status`");
        echo "OK   column {$state}.settings\n";
    } else {
        echo "SKIP column {$state}.settings\n";
    }
} else {
    fwrite(STDERR, "SKIP {$state} missing\n");
}

$grants = $prefix . 'fans_yxx_rain_grants';
$exists = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($grants))->fetchColumn();
if ($exists) {
    $idx = $pdo->query("SHOW INDEX FROM `{$grants}` WHERE Key_name='idx_event_paid'")->fetch(PDO::FETCH_ASSOC);
    if (!$idx) {
        $pdo->exec("ALTER TABLE `{$grants}` ADD KEY `idx_event_paid` (`event_id`,`paid`)");
        echo "OK   index {$grants}.idx_event_paid\n";
    } else {
        echo "SKIP index {$grants}.idx_event_paid\n";
    }
} else {
    fwrite(STDERR, "SKIP {$grants} missing\n");
}

echo "DONE yxx rain v2\n";
