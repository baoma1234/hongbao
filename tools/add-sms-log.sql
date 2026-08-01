-- 短信记录表 + 后台菜单（另一台执行；菜单 id 可能不同，按需调整）
CREATE TABLE IF NOT EXISTS `fa_fans_sms_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `event` varchar(32) NOT NULL DEFAULT '' COMMENT '事件',
  `mobile` varchar(32) NOT NULL DEFAULT '' COMMENT '手机号',
  `code` varchar(16) NOT NULL DEFAULT '' COMMENT '验证码',
  `channel` varchar(32) NOT NULL DEFAULT '' COMMENT '通道 mock/dagou/una/http/default',
  `ip` varchar(64) NOT NULL DEFAULT '',
  `status` varchar(16) NOT NULL DEFAULT 'sent' COMMENT 'sent/used',
  `createtime` int unsigned DEFAULT NULL,
  `usedtime` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mobile` (`mobile`),
  KEY `idx_event` (`event`),
  KEY `idx_createtime` (`createtime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='福利大厅短信发送记录';

-- 菜单挂在「会员运营」下（pid 以本机 fanshub_member 为准，另一台请先查：SELECT id FROM fa_auth_rule WHERE name='fanshub_member'）
-- INSERT 示例见 tools/_install_smslog.php
