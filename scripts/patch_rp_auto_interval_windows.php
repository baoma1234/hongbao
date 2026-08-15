<?php
/**
 * 红包自动发抢：增加时段间隔 JSON 字段 interval_windows
 * php scripts/patch_rp_auto_interval_windows.php
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
$table = $prefix . 'chat_rp_auto_task';

$cols = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'interval_windows'")->fetchAll();
if (!$cols) {
    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `interval_windows` text NULL COMMENT '时段固定间隔JSON [{start_hour,end_hour,interval_sec}]' AFTER `interval_sec`");
    echo "OK added interval_windows\n";
} else {
    echo "SKIP interval_windows exists\n";
}

$default = json_encode([
    ['start_hour' => 20, 'end_hour' => 23, 'interval_sec' => 30],
    ['start_hour' => 0, 'end_hour' => 7, 'interval_sec' => 120],
], JSON_UNESCAPED_UNICODE);

$n = $pdo->exec(
    "UPDATE `{$table}` SET `interval_windows`=" . $pdo->quote($default)
    . " WHERE `interval_windows` IS NULL OR TRIM(`interval_windows`)=''"
);
echo "OK backfill default windows rows={$n}\n";
echo "DONE\n";
