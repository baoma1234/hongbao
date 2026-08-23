<?php
/**
 * 后台用户账户备注：fa_fans_account.admin_remark
 * 用法: php scripts/patch_admin_remark_v1.php
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
$table = $prefix . 'fans_account';

$cols = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'admin_remark'")->fetchAll();
if ($cols) {
    echo "SKIP column admin_remark\n";
    exit(0);
}

$pdo->exec(
    "ALTER TABLE `{$table}` ADD COLUMN `admin_remark` varchar(500) NOT NULL DEFAULT '' COMMENT '后台用户信息备注(仅后台可见)' AFTER `chat_forbid`"
);
echo "OK   add column admin_remark\n";
