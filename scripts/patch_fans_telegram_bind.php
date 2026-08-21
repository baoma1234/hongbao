<?php
/**
 * Create fa_fans_telegram_bind
 * php scripts/patch_fans_telegram_bind.php
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
$table = $prefix . 'fans_telegram_bind';

$sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tg_user_id` bigint NOT NULL COMMENT 'Telegram user id',
  `user_id` int unsigned NOT NULL COMMENT 'fa_user.id',
  `tg_username` varchar(64) NOT NULL DEFAULT '',
  `tg_first_name` varchar(128) NOT NULL DEFAULT '',
  `tg_last_name` varchar(128) NOT NULL DEFAULT '',
  `createtime` int unsigned NOT NULL DEFAULT 0,
  `updatetime` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tg_user` (`tg_user_id`),
  UNIQUE KEY `uk_user` (`user_id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Telegram 账号绑定'";

$pdo->exec($sql);
echo "OK create/ensure {$table}\n";
echo "Next:\n";
echo "  1) Fill telegram_bot_token + telegram_bot_username in application/extra/fanshub.php\n";
echo "  2) php scripts/set_telegram_bot_webhook.php\n";
echo "  3) BotFather: /setdomain -> hbsq.bio (WebApp)\n";
