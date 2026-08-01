-- 支付密码字段（另一台若尚未加列，执行本脚本；已加列会报 duplicate，可忽略）
ALTER TABLE `fa_fans_account`
  ADD COLUMN `pay_password` varchar(32) NOT NULL DEFAULT '' COMMENT '支付密码' AFTER `status`,
  ADD COLUMN `pay_salt` varchar(30) NOT NULL DEFAULT '' COMMENT '支付密码盐' AFTER `pay_password`;
