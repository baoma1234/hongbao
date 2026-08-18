<?php
/**
 * 鱼虾蟹群桌：每群独立爆点池
 * php scripts/patch_yxx_group_v1.php
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

$t = $prefix . 'fans_yxx_group_state';
if (!tableExists($pdo, $t)) {
    $pdo->exec("CREATE TABLE `{$t}` (
      `group_id` bigint unsigned NOT NULL,
      `owner_user_id` bigint unsigned NOT NULL DEFAULT 0,
      `is_open` tinyint unsigned NOT NULL DEFAULT 0,
      `gross_pool` bigint unsigned NOT NULL DEFAULT 0,
      `cycle_count` int unsigned NOT NULL DEFAULT 0,
      `boom_half_count` int unsigned NOT NULL DEFAULT 0,
      `updatetime` int unsigned NOT NULL DEFAULT 0,
      PRIMARY KEY (`group_id`),
      KEY `idx_open` (`is_open`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='鱼虾蟹群桌爆点池'");
    echo "OK   table {$t}\n";
} else {
    echo "SKIP table {$t}\n";
}

echo "DONE yxx group v1\n";
