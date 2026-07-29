-- 充值/提现通道 + 流水 + 好友 + 群展示字段
SET NAMES utf8mb4;

ALTER TABLE `fa_fans_account`
  ADD COLUMN IF NOT EXISTS `turnover` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '累计流水' AFTER `balance`;

CREATE TABLE IF NOT EXISTS `fa_fans_pay_channel` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `type` enum('recharge','withdraw') NOT NULL DEFAULT 'recharge' COMMENT '通道类型',
  `name` varchar(64) NOT NULL DEFAULT '' COMMENT '通道名称',
  `icon` varchar(255) NOT NULL DEFAULT '' COMMENT '图标URL',
  `tip` varchar(255) NOT NULL DEFAULT '' COMMENT '前台提示',
  `handler` varchar(64) NOT NULL DEFAULT 'manual' COMMENT '对接处理器',
  `submit_url` varchar(512) NOT NULL DEFAULT '' COMMENT '提交URL',
  `merchant_no` varchar(64) NOT NULL DEFAULT '' COMMENT '商户号',
  `merchant_key` varchar(255) NOT NULL DEFAULT '' COMMENT '商户密钥',
  `pay_type` varchar(32) NOT NULL DEFAULT '' COMMENT '支付类型',
  `pay_channel` varchar(64) NOT NULL DEFAULT '' COMMENT '支付通道',
  `notify_url` varchar(512) NOT NULL DEFAULT '' COMMENT '服务器通知URL',
  `return_url` varchar(512) NOT NULL DEFAULT '' COMMENT '页面跳转URL',
  `product_name` varchar(128) NOT NULL DEFAULT '' COMMENT '商品名称',
  `config` text COMMENT '扩展JSON',
  `min_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `max_amount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '0不限',
  `weigh` int(11) NOT NULL DEFAULT '0',
  `status` enum('normal','hidden') NOT NULL DEFAULT 'normal',
  `createtime` int(10) unsigned DEFAULT NULL,
  `updatetime` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_type_status` (`type`,`status`,`weigh`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='充值提现通道';

CREATE TABLE IF NOT EXISTS `fa_fans_recharge_order` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_no` varchar(40) NOT NULL DEFAULT '',
  `user_id` int(10) unsigned NOT NULL DEFAULT '0',
  `channel_id` int(10) unsigned NOT NULL DEFAULT '0',
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `status` enum('pending','paid','failed','cancelled') NOT NULL DEFAULT 'pending',
  `handler` varchar(64) NOT NULL DEFAULT '',
  `pay_info` text COMMENT '支付信息JSON',
  `remark` varchar(255) NOT NULL DEFAULT '',
  `createtime` int(10) unsigned DEFAULT NULL,
  `updatetime` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='充值订单';

CREATE TABLE IF NOT EXISTS `fa_fans_withdraw_order` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_no` varchar(40) NOT NULL DEFAULT '',
  `user_id` int(10) unsigned NOT NULL DEFAULT '0',
  `channel_id` int(10) unsigned NOT NULL DEFAULT '0',
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `turnover_snapshot` decimal(12,2) NOT NULL DEFAULT '0.00',
  `status` enum('pending','processing','paid','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `handler` varchar(64) NOT NULL DEFAULT '',
  `account_info` text COMMENT '收款信息JSON',
  `remark` varchar(255) NOT NULL DEFAULT '',
  `createtime` int(10) unsigned DEFAULT NULL,
  `updatetime` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='提现订单';

CREATE TABLE IF NOT EXISTS `fa_chat_contacts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL DEFAULT '0',
  `peer_user_id` int(10) unsigned NOT NULL DEFAULT '0',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '1正常',
  `createtime` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_peer` (`user_id`,`peer_user_id`),
  KEY `idx_peer` (`peer_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='IM好友';

ALTER TABLE `fa_chat_groups`
  ADD COLUMN IF NOT EXISTS `hide_member_list` tinyint(1) unsigned NOT NULL DEFAULT '1' COMMENT '1隐藏成员列表' AFTER `member_count`,
  ADD COLUMN IF NOT EXISTS `display_member_count` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '展示人数0=实际' AFTER `hide_member_list`,
  ADD COLUMN IF NOT EXISTS `privacy_mode` enum('open','private') NOT NULL DEFAULT 'private' COMMENT 'open开放群 private隐私群' AFTER `display_member_count`,
  ADD COLUMN IF NOT EXISTS `chat_mode` enum('chat','grab') NOT NULL DEFAULT 'chat' COMMENT 'chat聊天 grab抢红包' AFTER `privacy_mode`;
