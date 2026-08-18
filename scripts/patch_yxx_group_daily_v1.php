<?php
/**
 * 鱼虾蟹群桌：每群每人每日下注权重（解散时强制分爆点池）
 * php scripts/patch_yxx_group_daily_v1.php
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

function tableExists(PDO $pdo, $table)
{
    return (bool)$pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table))->fetchColumn();
}

$t = $prefix . 'fans_yxx_group_daily';
if (!tableExists($pdo, $t)) {
    $pdo->exec("CREATE TABLE `{$t}` (
      `id` bigint unsigned NOT NULL AUTO_INCREMENT,
      `group_id` bigint unsigned NOT NULL,
      `user_id` bigint unsigned NOT NULL,
      `bet_date` char(8) NOT NULL COMMENT 'Ymd',
      `bet_count` int unsigned NOT NULL DEFAULT 0,
      `bet_total` bigint unsigned NOT NULL DEFAULT 0,
      `updatetime` int unsigned NOT NULL DEFAULT 0,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uk_g_user_date` (`group_id`,`user_id`,`bet_date`),
      KEY `idx_g_user` (`group_id`,`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='鱼虾蟹群桌日下注权重'");
    echo "OK   table {$t}\n";
} else {
    echo "SKIP table {$t}\n";
}

echo "DONE yxx group daily v1\n";
