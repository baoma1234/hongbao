<?php
/**
 * OG视讯：玩家映射 + 转账流水表
 * php scripts/install_og_tables.php
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

$pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}fans_og_player` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `player_id` varchar(64) NOT NULL DEFAULT '' COMMENT 'OG player_id',
  `og_nickname` varchar(64) NOT NULL DEFAULT '' COMMENT 'OG nickname',
  `status` varchar(16) NOT NULL DEFAULT '' COMMENT 'registered|fail',
  `last_rs_code` varchar(32) NOT NULL DEFAULT '',
  `last_rs_message` varchar(255) NOT NULL DEFAULT '',
  `registered_at` int(10) unsigned NOT NULL DEFAULT 0,
  `createtime` int(10) unsigned NOT NULL DEFAULT 0,
  `updatetime` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user` (`user_id`),
  UNIQUE KEY `uk_player` (`player_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='OG视讯玩家映射'");
echo "OK fans_og_player\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}fans_og_transfer` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `player_id` varchar(64) NOT NULL DEFAULT '',
  `direction` varchar(16) NOT NULL DEFAULT '' COMMENT 'deposit|withdraw',
  `transaction_id` varchar(64) NOT NULL DEFAULT '',
  `amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `status` varchar(16) NOT NULL DEFAULT '' COMMENT 'pending|success|fail',
  `rs_code` varchar(32) NOT NULL DEFAULT '',
  `rs_message` varchar(255) NOT NULL DEFAULT '',
  `createtime` int(10) unsigned NOT NULL DEFAULT 0,
  `updatetime` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_txid` (`transaction_id`),
  KEY `idx_user_dir` (`user_id`,`direction`,`createtime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='OG视讯转账流水'");
echo "OK fans_og_transfer\n";
echo "DONE\n";
