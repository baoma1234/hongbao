<?php
/**
 * 自动发抢金额模式二：amount_mode / amount_mode2_count / amount_mode2_target
 * php scripts/patch_rp_auto_amount_mode.php
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
    'amount_mode',
    "`amount_mode` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '1=金额区间 2=小额10-100+每10-20包插发200-300;接龙强制1' AFTER `amount_max`"
);
addColumn(
    $pdo,
    $table,
    'amount_mode2_count',
    "`amount_mode2_count` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '模式二：自上次大奖后已发小额包数' AFTER `amount_mode`"
);
addColumn(
    $pdo,
    $table,
    'amount_mode2_target',
    "`amount_mode2_target` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '模式二：下次大奖前小额包数阈值(10-20)' AFTER `amount_mode2_count`"
);

echo "DONE\n";
