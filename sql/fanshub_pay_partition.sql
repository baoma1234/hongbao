-- 充提分区 / 通道归属 / 钱包地址绑定
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `fa_fans_pay_partition` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `type` enum('recharge','withdraw') NOT NULL DEFAULT 'recharge',
  `code` varchar(32) NOT NULL DEFAULT '',
  `name` varchar(64) NOT NULL DEFAULT '',
  `name_i18n` text,
  `bind_mode` varchar(32) NOT NULL DEFAULT 'none',
  `weigh` int(11) NOT NULL DEFAULT '0',
  `status` enum('normal','hidden') NOT NULL DEFAULT 'normal',
  `createtime` int(10) unsigned DEFAULT NULL,
  `updatetime` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_type_code` (`type`,`code`),
  KEY `idx_type_status` (`type`,`status`,`weigh`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='充提通道分区';

CREATE TABLE IF NOT EXISTS `fa_fans_wallet_bind` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL DEFAULT '0',
  `wallet_type` varchar(64) NOT NULL DEFAULT '',
  `bind_mode` varchar(32) NOT NULL DEFAULT 'wallet',
  `account_name` varchar(64) NOT NULL DEFAULT '',
  `account_no` varchar(255) NOT NULL DEFAULT '',
  `account_hash` varchar(64) NOT NULL DEFAULT '',
  `bank_name` varchar(64) NOT NULL DEFAULT '',
  `extra` text,
  `createtime` int(10) unsigned DEFAULT NULL,
  `updatetime` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_type` (`user_id`,`wallet_type`),
  UNIQUE KEY `uk_type_hash` (`wallet_type`,`account_hash`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户提现收款绑定';
