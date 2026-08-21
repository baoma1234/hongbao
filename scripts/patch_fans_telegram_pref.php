<?php
/**
 * Create fa_fans_telegram_pref (per-TG-user locale, independent of bind)
 * php scripts/patch_fans_telegram_pref.php
 */
$root = dirname(__DIR__);
$env = parse_ini_file($root . '/.env', true);
if (!$env || empty($env['database'])) {
    fwrite(STDERR, "Missing .env database config\n");
    exit(1);
}
$d = $env['database'];
$pdo = new PDO(
    "mysql:host={$d['hostname']};dbname={$d['database']};charset=utf8mb4",
    $d['username'],
    $d['password'],
    [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4', PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$prefix = $d['prefix'] ?? 'fa_';
$table = $prefix . 'fans_telegram_pref';

$sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
  `tg_user_id` bigint NOT NULL COMMENT 'Telegram user id',
  `locale` varchar(16) NOT NULL DEFAULT 'zh-CN',
  `updatetime` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`tg_user_id`),
  KEY `idx_locale` (`locale`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Telegram 用户语言偏好'";

$pdo->exec($sql);
echo "OK create/ensure {$table}\n";
