-- 红利/红宝兑入股份：当日锁定，次日(T+1)起可再兑出；分享等渠道送股不锁定
ALTER TABLE `fa_fans_account`
  ADD COLUMN `rights_locked` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '当日兑入锁定股份(T+1可兑出)' AFTER `rights`,
  ADD COLUMN `rights_lock_day` date DEFAULT NULL COMMENT '锁定股份归属日' AFTER `rights_locked`;
