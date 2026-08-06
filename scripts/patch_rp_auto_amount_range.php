<?php
/**
 * 自动发抢金额区间 amount_min / amount_max
 * php scripts/patch_rp_auto_amount_range.php
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
    $table,
    'amount_min',
    "`amount_min` decimal(12,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '金额下限(=上限则固定)' AFTER `total_amount`"
);
addColumn(
    $pdo,
    $table,
    'amount_max',
    "`amount_max` decimal(12,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '金额上限(=下限则固定)' AFTER `amount_min`"
);

$pdo->exec(
    "UPDATE `{$table}` SET
        `amount_min` = IF(`amount_min`<=0, `total_amount`, `amount_min`),
        `amount_max` = IF(`amount_max`<=0, `total_amount`, `amount_max`)
     WHERE `total_amount`>0"
);
echo "DONE backfill amount_min/max from total_amount\n";
