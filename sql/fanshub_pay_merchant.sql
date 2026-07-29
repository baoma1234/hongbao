-- 万汇通总商户 + 通道关联 merchant_id
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `fa_fans_pay_merchant` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL DEFAULT '' COMMENT '商户备注名',
  `gateway` varchar(32) NOT NULL DEFAULT 'wanhuitong' COMMENT '网关标识',
  `merchant_no` varchar(64) NOT NULL DEFAULT '' COMMENT '平台商户号',
  `private_key` text COMMENT '商户RSA私钥',
  `platform_public_key` text COMMENT '平台RSA公钥',
  `site` varchar(255) NOT NULL DEFAULT '' COMMENT '公网站点根 https://域名',
  `callback_ips` varchar(255) NOT NULL DEFAULT '18.162.71.242,95.40.141.160' COMMENT '回调IP白名单',
  `status` enum('normal','hidden') NOT NULL DEFAULT 'normal',
  `remark` varchar(255) NOT NULL DEFAULT '',
  `createtime` int(10) unsigned DEFAULT NULL,
  `updatetime` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_gateway_merchant` (`gateway`,`merchant_no`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='支付总商户(万汇通等)';

-- 通道挂靠总商户（兼容已有库）
ALTER TABLE `fa_fans_pay_channel`
  ADD COLUMN `merchant_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '关联总商户ID' AFTER `handler`;
