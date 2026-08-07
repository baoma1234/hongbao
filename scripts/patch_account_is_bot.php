<?php
/**
 * 用户账户表增加 is_bot 字段（机器人标记）
 * php scripts/patch_account_is_bot.php
 */
$root = dirname(__DIR__);
$env = parse_ini_file($root . '/.env', true);
$d = $env['database'];
$prefix = $d['prefix'] ?? 'fa_';
$table = $prefix . 'fans_account';

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $d['hostname'] ?? '127.0.0.1',
        (int)($d['hostport'] ?? 3306),
        $d['database'] ?? ''
    ),
    $d['username'] ?? 'root',
    $d['password'] ?? '',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
    ]
);

$col = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'is_bot'")->fetch(PDO::FETCH_ASSOC);
if ($col) {
    echo "SKIP  {$table}.is_bot already exists ({$col['Type']})\n";
    exit(0);
}

$pdo->exec(
    "ALTER TABLE `{$table}`
     ADD COLUMN `is_bot` tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT '是否机器人:1是0否' AFTER `status`,
     ADD KEY `idx_is_bot` (`is_bot`)"
);
echo "OK    {$table}.is_bot added\n";

// 顺便确认 hongbao / mobile 列
$h = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'hongbao'")->fetch(PDO::FETCH_ASSOC);
echo "INFO  hongbao=" . ($h['Type'] ?? 'missing') . "\n";
$u = $prefix . 'user';
$m = $pdo->query("SHOW COLUMNS FROM `{$u}` LIKE 'mobile'")->fetch(PDO::FETCH_ASSOC);
echo "INFO  user.mobile=" . ($m['Type'] ?? 'missing') . "\n";
$cnt = (int)$pdo->query("SELECT COUNT(*) FROM `{$u}` WHERE mobile LIKE '100000000%'")->fetchColumn();
echo "INFO  existing mobiles 100000000* count={$cnt}\n";
