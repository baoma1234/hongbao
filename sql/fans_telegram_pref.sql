-- Telegram 用户语言偏好（与绑定表独立，未绑定时也可切换）
-- Apply: php scripts/patch_fans_telegram_pref.php

CREATE TABLE IF NOT EXISTS `fa_fans_telegram_pref` (
  `tg_user_id` bigint NOT NULL COMMENT 'Telegram user id',
  `locale` varchar(16) NOT NULL DEFAULT 'zh-CN',
  `updatetime` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`tg_user_id`),
  KEY `idx_locale` (`locale`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Telegram 用户语言偏好';
