<?php
/**
 * 红宝雨：机器人抢包比例 bot_grab_pct
 * php scripts/migrate_rp_rain_bot_grab_pct.php
 */
$root = dirname(__DIR__);
$env = parse_ini_file($root . '/.env', true);
$d = $env['database'];
$pdo = new PDO(
    "mysql:host={$d['hostname']};dbname={$d['database']};charset=utf8mb4",
    $d['username'],
    $d['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$table = ($d['prefix'] ?? 'fa_') . 'chat_rp_rain_task';
$cols = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('bot_grab_pct', $cols, true)) {
    $pdo->exec(
        "ALTER TABLE `{$table}` ADD COLUMN `bot_grab_pct` tinyint(3) unsigned NOT NULL DEFAULT '80'"
        . " COMMENT '超时前机器人最多抢本轮包数的百分比' AFTER `bot_grab_cap`"
    );
    echo "ADD bot_grab_pct\n";
} else {
    echo "SKIP bot_grab_pct\n";
}
echo "DONE\n";
