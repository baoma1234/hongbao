<?php
/**
 * 模式二可配置：大奖区间 + 小额包数阈值
 * php scripts/patch_rp_auto_amount_mode2_cfg.php
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
    'amount_mode2_jackpot_min',
    "`amount_mode2_jackpot_min` decimal(12,2) unsigned NOT NULL DEFAULT '200.00' COMMENT '模式二大奖下限' AFTER `amount_mode2_target`"
);
addColumn(
    $pdo,
    $table,
    'amount_mode2_jackpot_max',
    "`amount_mode2_jackpot_max` decimal(12,2) unsigned NOT NULL DEFAULT '300.00' COMMENT '模式二大奖上限' AFTER `amount_mode2_jackpot_min`"
);
addColumn(
    $pdo,
    $table,
    'amount_mode2_every_min',
    "`amount_mode2_every_min` int(10) unsigned NOT NULL DEFAULT '10' COMMENT '模式二：多少小额包后插大奖(下限)' AFTER `amount_mode2_jackpot_max`"
);
addColumn(
    $pdo,
    $table,
    'amount_mode2_every_max',
    "`amount_mode2_every_max` int(10) unsigned NOT NULL DEFAULT '20' COMMENT '模式二：多少小额包后插大奖(上限)' AFTER `amount_mode2_every_min`"
);

echo "DONE\n";
