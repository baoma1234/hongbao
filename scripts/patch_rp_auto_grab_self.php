<?php
/**
 * 红宝自动任务：增加「自动抢自己」开关
 * php scripts/patch_rp_auto_grab_self.php
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

if (!columnExists($pdo, $table, 'auto_grab_self')) {
    $pdo->exec(
        "ALTER TABLE `{$table}` ADD COLUMN `auto_grab_self` tinyint(1) unsigned NOT NULL DEFAULT 0 "
        . "COMMENT '1=发包机器人立刻领自己刚发的包' AFTER `auto_grab`"
    );
    echo "ADD {$table}.auto_grab_self\n";
} else {
    echo "SKIP {$table}.auto_grab_self\n";
}

// 官方群：标记推荐 + 未充值可发红宝
$gtable = $prefix . 'chat_groups';
$ids = [8, 9, 12, 14, 15, 16];
$in = implode(',', $ids);
$n = $pdo->exec("UPDATE `{$gtable}` SET `is_recommend`=1 WHERE `id` IN ({$in})");
echo "official groups is_recommend=1 affected={$n} ids={$in}\n";
echo "OK\n";
