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

$pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}fans_og_bet` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `fetch_id` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT 'OG fetch_id',
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `player_id` varchar(64) NOT NULL DEFAULT '',
  `transaction_id` varchar(128) NOT NULL DEFAULT '',
  `game_id` varchar(32) NOT NULL DEFAULT '',
  `round_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `game_type_id` tinyint(3) unsigned NOT NULL DEFAULT 1 COMMENT '1真人 2老虎机 3棋牌 4彩票 5体育',
  `game_name` varchar(128) NOT NULL DEFAULT '',
  `bet_place` varchar(64) NOT NULL DEFAULT '',
  `result_url` varchar(512) NOT NULL DEFAULT '',
  `debit_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `credit_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `winlose_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `effective_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(16) NOT NULL DEFAULT '',
  `transaction_type` varchar(32) NOT NULL DEFAULT '',
  `secondary_info` mediumtext COMMENT 'JSON',
  `other_info` mediumtext COMMENT 'JSON',
  `remark` text,
  `debit_at` int(10) unsigned NOT NULL DEFAULT 0,
  `credit_at` int(10) unsigned NOT NULL DEFAULT 0,
  `rollback_at` int(10) unsigned NOT NULL DEFAULT 0,
  `cancel_at` int(10) unsigned NOT NULL DEFAULT 0,
  `resettled_at` int(10) unsigned NOT NULL DEFAULT 0,
  `createtime` int(10) unsigned NOT NULL DEFAULT 0,
  `updatetime` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_txid` (`transaction_id`),
  KEY `idx_fetch` (`fetch_id`),
  KEY `idx_user_debit` (`user_id`,`debit_at`),
  KEY `idx_player` (`player_id`),
  KEY `idx_game_round` (`game_id`,`round_id`),
  KEY `idx_game_type` (`game_type_id`,`debit_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='OG视讯投注记录'");
echo "OK fans_og_bet\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}fans_og_sync` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL DEFAULT '',
  `value` varchar(255) NOT NULL DEFAULT '',
  `updatetime` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='OG同步游标'");
echo "OK fans_og_sync\n";
echo "DONE\n";
