<?php
/**
 * 自动发抢：多人发包 UID + 时间窗突发节奏
 * php scripts/patch_rp_auto_burst_senders.php
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
    'send_user_ids',
    "`send_user_ids` varchar(512) NOT NULL DEFAULT '' COMMENT '发包UID逗号分隔,空则用send_user_id' AFTER `send_user_id`"
);
addColumn(
    $pdo,
    $table,
    'burst_count',
    "`burst_count` int(10) unsigned NOT NULL DEFAULT '1' COMMENT '时间窗内发包数,1=不用突发' AFTER `interval_sec`"
);
addColumn(
    $pdo,
    $table,
    'burst_window_sec',
    "`burst_window_sec` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '突发时间窗秒,0=沿用间隔秒' AFTER `burst_count`"
);
addColumn(
    $pdo,
    $table,
    'burst_window_start',
    "`burst_window_start` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '当前时间窗起点unix' AFTER `burst_window_sec`"
);
addColumn(
    $pdo,
    $table,
    'burst_sent',
    "`burst_sent` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '当前窗已发包数' AFTER `burst_window_start`"
);
addColumn(
    $pdo,
    $table,
    'burst_next_at',
    "`burst_next_at` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '窗内下一次计划发包unix' AFTER `burst_sent`"
);

// 回填：单 UID 复制到 send_user_ids
$pdo->exec(
    "UPDATE `{$table}` SET `send_user_ids`=CAST(`send_user_id` AS CHAR)
     WHERE (`send_user_ids`='' OR `send_user_ids` IS NULL) AND `send_user_id`>0"
);
echo "DONE backfill send_user_ids\n";
