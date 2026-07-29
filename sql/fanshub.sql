-- 789bingo 福利大厅 (tiyuv7) 数据表
-- 菜单请由 scripts/install_fanshub.php 安装

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `fa_fans_account` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '会员ID(=fa_user.id)',
  `user_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '会员ID(与id相同，兼容旧关联)',
  `rights` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '持有股份',
  `balance` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '可领取余额',
  `main_uid` varchar(64) NOT NULL DEFAULT '' COMMENT '主站UID',
  `main_uid_unique` varchar(64) GENERATED ALWAYS AS (IF(`main_uid` = '', NULL, `main_uid`)) STORED,
  `flow_stage` enum('stage1','stage2') NOT NULL DEFAULT 'stage1' COMMENT '流程阶段',
  `member_level` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '会员等级',
  `user_mode` enum('newbie','master') NOT NULL DEFAULT 'newbie' COMMENT '用户态',
  `fission_streak_days` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '连续签到天数',
  `fission_streak_qualified` tinyint(1) unsigned NOT NULL DEFAULT '1' COMMENT '7天暴击资格',
  `fission_last_checkin_date` date DEFAULT NULL COMMENT '最后签到日',
  `sub_withdrawn_count` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '直属下线提现数',
  `honor_tier_claimed` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '已领荣誉段位',
  `first_withdraw_done` tinyint(1) unsigned NOT NULL DEFAULT '0' COMMENT '已完成新手提现',
  `status` enum('normal','frozen') NOT NULL DEFAULT 'normal' COMMENT '状态',
  `chat_forbid` varchar(255) NOT NULL DEFAULT '' COMMENT '聊天禁言JSON',
  `createtime` int(10) unsigned DEFAULT NULL,
  `updatetime` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_id` (`user_id`),
  UNIQUE KEY `uk_main_uid_nonempty` (`main_uid_unique`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='福利大厅账户';

CREATE TABLE IF NOT EXISTS `fa_fans_ledger` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL DEFAULT '0',
  `type` varchar(32) NOT NULL DEFAULT '' COMMENT 'register/share/open_account/exchange/admin_adjust',
  `rights_change` decimal(10,2) NOT NULL DEFAULT '0.00',
  `balance_change` decimal(10,2) NOT NULL DEFAULT '0.00',
  `rights_after` decimal(10,2) NOT NULL DEFAULT '0.00',
  `balance_after` decimal(10,2) NOT NULL DEFAULT '0.00',
  `remark` varchar(255) NOT NULL DEFAULT '',
  `channel` varchar(64) NOT NULL DEFAULT '' COMMENT '闪兑通道',
  `admin_id` int(10) unsigned NOT NULL DEFAULT '0',
  `createtime` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='福利资产流水';

CREATE TABLE IF NOT EXISTS `fa_fans_secret` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL DEFAULT '0',
  `code` varchar(128) NOT NULL DEFAULT '' COMMENT '密令',
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `tier` enum('VIP','GREEN') NOT NULL DEFAULT 'GREEN',
  `main_uid` varchar(64) NOT NULL DEFAULT '',
  `status` enum('pending','contacted','completed','expired') NOT NULL DEFAULT 'pending',
  `expiretime` int(10) unsigned DEFAULT NULL,
  `createtime` int(10) unsigned DEFAULT NULL,
  `updatetime` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_code` (`code`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='福利密令单';

CREATE TABLE IF NOT EXISTS `fa_fans_invite` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `inviter_user_id` int(10) unsigned NOT NULL DEFAULT '0',
  `invitee_user_id` int(10) unsigned NOT NULL DEFAULT '0',
  `invitee_mobile` varchar(20) NOT NULL DEFAULT '',
  `invitee_ip` varchar(45) NOT NULL DEFAULT '',
  `inviter_ip` varchar(45) NOT NULL DEFAULT '',
  `createtime` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_invitee` (`invitee_user_id`),
  KEY `idx_inviter` (`inviter_user_id`),
  KEY `idx_invitee_ip` (`invitee_ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='福利邀请记录';

CREATE TABLE IF NOT EXISTS `fa_fans_comment` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL DEFAULT '0',
  `content` varchar(500) NOT NULL DEFAULT '',
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `createtime` int(10) unsigned DEFAULT NULL,
  `updatetime` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='福利留言';

CREATE TABLE IF NOT EXISTS `fa_fans_login_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL DEFAULT '0',
  `ip` varchar(45) NOT NULL DEFAULT '',
  `user_agent` varchar(255) NOT NULL DEFAULT '',
  `device_fingerprint` varchar(64) NOT NULL DEFAULT '' COMMENT '设备指纹',
  `createtime` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_ip` (`ip`),
  KEY `idx_device_fp` (`device_fingerprint`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='福利登录日志';

CREATE TABLE IF NOT EXISTS `fa_fans_task` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL DEFAULT '0',
  `task_type` varchar(32) NOT NULL DEFAULT '' COMMENT 'share/open_account/exchange',
  `channel` varchar(64) NOT NULL DEFAULT '',
  `rights` decimal(10,2) NOT NULL DEFAULT '0.00',
  `balance` decimal(10,2) NOT NULL DEFAULT '0.00',
  `extra` varchar(255) NOT NULL DEFAULT '',
  `ip` varchar(45) NOT NULL DEFAULT '',
  `createtime` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_task_type` (`task_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='福利任务记录';

CREATE TABLE IF NOT EXISTS `fa_fans_idempotent` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL DEFAULT '0',
  `action` varchar(32) NOT NULL DEFAULT '',
  `request_key` varchar(64) NOT NULL DEFAULT '',
  `createtime` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_request` (`user_id`,`action`,`request_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='接口幂等键';

CREATE TABLE IF NOT EXISTS `fa_fans_checkin` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL DEFAULT '0',
  `checkin_date` date NOT NULL,
  `mode` enum('normal','violent') NOT NULL DEFAULT 'normal',
  `base_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `bonus_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `bonus_unlocked` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `streak_day` tinyint(3) unsigned NOT NULL DEFAULT '1',
  `day7_settled` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `createtime` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_date` (`user_id`,`checkin_date`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='星火签到记录';
