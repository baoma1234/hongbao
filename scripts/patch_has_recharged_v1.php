<?php
/**
 * fans_account.has_recharged + 按已支付充值订单回填
 * php scripts/patch_has_recharged_v1.php
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

$cols = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'has_recharged'")->fetchAll();
if (!$cols) {
    $pdo->exec(
        "ALTER TABLE `{$table}` ADD COLUMN `has_recharged` tinyint(1) unsigned NOT NULL DEFAULT 0
         COMMENT '曾成功充值:1解锁发红包/转账/私域领红包' AFTER `hongbao_frozen`"
    );
    echo "OK   add column has_recharged\n";
} else {
    echo "SKIP column has_recharged\n";
}

$n = $pdo->exec(
    "UPDATE `{$table}` a
     INNER JOIN (
       SELECT DISTINCT user_id FROM `{$prefix}fans_recharge_order` WHERE status='paid'
     ) o ON o.user_id = a.user_id
     SET a.has_recharged = 1
     WHERE IFNULL(a.has_recharged,0)=0"
);
echo "OK   backfill has_recharged={$n}\n";

$bots = $pdo->exec("UPDATE `{$table}` SET has_recharged=1 WHERE IFNULL(is_bot,0)=1 AND IFNULL(has_recharged,0)=0");
echo "OK   bot has_recharged={$bots}\n";
echo "DONE has_recharged v1\n";
