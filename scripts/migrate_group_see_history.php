<?php
/**
 * 群「新成员可见历史」字段
 * php scripts/migrate_group_see_history.php
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
$pre = $d['prefix'] ?? 'fa_';
$table = $pre . 'chat_groups';

$cols = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'new_member_see_history'")->fetch(PDO::FETCH_ASSOC);
if (!$cols) {
    $pdo->exec(
        "ALTER TABLE `{$table}` ADD COLUMN `new_member_see_history` tinyint(1) unsigned NOT NULL DEFAULT 0 "
        . "COMMENT '新成员是否可见入群前历史 0否1是' AFTER `weigh`"
    );
    echo "ADDED column new_member_see_history\n";
} else {
    echo "column exists\n";
}

$n = $pdo->exec("UPDATE `{$table}` SET new_member_see_history=1, updatetime=" . time() . " WHERE id=70");
echo "group70 updated rows={$n}\n";
$r = $pdo->query("SELECT id,name,new_member_see_history FROM `{$table}` WHERE id=70")->fetch(PDO::FETCH_ASSOC);
echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
