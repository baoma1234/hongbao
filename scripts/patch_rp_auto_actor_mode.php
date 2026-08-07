<?php
/**
 * 红包自动任务：增加 actor_mode（1=UID池，2=机器人账户随机）
 * 用法: php scripts/patch_rp_auto_actor_mode.php
 */
$root = dirname(__DIR__);
$ini = parse_ini_file($root . '/.env', true);
if (empty($ini['database'])) {
    fwrite(STDERR, "missing .env database\n");
    exit(1);
}
$d = $ini['database'];
$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $d['hostname'],
        (int)($d['hostport'] ?? 3306),
        $d['database']
    ),
    $d['username'],
    $d['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pre = $d['prefix'] ?? 'fa_';
$table = $pre . 'chat_rp_auto_task';
$cols = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
if (in_array('actor_mode', $cols, true)) {
    echo "actor_mode already exists\n";
    exit(0);
}
$pdo->exec(
    "ALTER TABLE `{$table}` ADD COLUMN `actor_mode` tinyint(3) unsigned NOT NULL DEFAULT 1"
    . " COMMENT '1=发包/抢包UID池 2=机器人账户随机发抢' AFTER `auto_grab`"
);
echo "OK added actor_mode to {$table}\n";
