-- Telegram 用户 ↔ 红宝账号绑定
-- Apply: php scripts/patch_fans_telegram_bind.php

CREATE TABLE IF NOT EXISTS `fa_fans_telegram_bind` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Telegram 账号绑定';
