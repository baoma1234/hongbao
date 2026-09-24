CREATE TABLE IF NOT EXISTS `fa_fans_welfare_rp_daily` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `quota_date` char(8) NOT NULL DEFAULT '' COMMENT 'Ymd 自然日',
  `claim_count` int unsigned NOT NULL DEFAULT 0 COMMENT '今日已领福利群红包次数（独立计数，不读领取明细表）',
  `entertain_count` int unsigned NOT NULL DEFAULT 0 COMMENT '今日娱乐发+抢累计次数',
  `admin_extra` int NOT NULL DEFAULT 0 COMMENT '后台加减额外次数（可为负）',
  `free_limit` int unsigned NOT NULL DEFAULT 0 COMMENT '个人免费额度；0=用全局 welfare_rp_daily_free',
  `createtime` int unsigned NOT NULL DEFAULT 0,
  `updatetime` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_date` (`user_id`,`quota_date`),
  KEY `idx_date` (`quota_date`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='福利群红包每日领取配额（独立表）';
