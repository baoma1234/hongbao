<?php
/**
 * 群红包：仅自动机器人可发 + 固定金额
 * php scripts/patch_group_rp_robot_only.php
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

function columnExists(PDO $pdo, $table, $column)
{
    $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $st->execute([$column]);
    return (bool)$st->fetchColumn();
}

function addColumn(PDO $pdo, $table, $column, $ddl)
{
    if (columnExists($pdo, $table, $column)) {
        echo "SKIP {$table}.{$column}\n";
        return;
    }
    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$ddl}");
    echo "OK   {$table}.{$column}\n";
}

addColumn(
    $pdo,
    $groups,
    'rp_robot_only',
    "`rp_robot_only` tinyint(1) unsigned NOT NULL DEFAULT '0' COMMENT '1=禁止个人发红包仅自动/代发' AFTER `rp_enabled_types`"
);
addColumn(
    $pdo,
    $groups,
    'rp_fixed_amount',
    "`rp_fixed_amount` decimal(12,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '固定金额0=不限制' AFTER `rp_robot_only`"
);

echo "done\n";
