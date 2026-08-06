<?php
/**
 * 群红包金额上限 rp_max_amount
 * php scripts/patch_group_rp_max_amount.php
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
$groups = $prefix . 'chat_groups';

$st = $pdo->prepare("SHOW COLUMNS FROM `{$groups}` LIKE ?");
$st->execute(['rp_max_amount']);
if ($st->fetchColumn()) {
    echo "SKIP {$groups}.rp_max_amount\n";
    exit(0);
}
$after = 'rp_min_amount';
$st2 = $pdo->prepare("SHOW COLUMNS FROM `{$groups}` LIKE ?");
$st2->execute([$after]);
$pos = $st2->fetchColumn() ? "AFTER `{$after}`" : '';
$pdo->exec(
    "ALTER TABLE `{$groups}` ADD COLUMN `rp_max_amount` decimal(12,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '金额上限0=不限' {$pos}"
);
echo "OK   {$groups}.rp_max_amount\n";
