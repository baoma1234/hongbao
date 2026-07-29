-- 好友申请 + 客服被添加自动回复语
CREATE TABLE IF NOT EXISTS `fa_chat_friend_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `from_user_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '申请人',
  `to_user_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '被申请人',
  `message` varchar(200) NOT NULL DEFAULT '' COMMENT '申请附言',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '0待处理 1已通过 2已拒绝 3已取消',
  `handle_user_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '处理人(会员或0=系统/后台)',
  `createtime` int(10) unsigned NOT NULL DEFAULT '0',
  `updatetime` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_to_status` (`to_user_id`,`status`),
  KEY `idx_from_status` (`from_user_id`,`status`),
  KEY `idx_status_time` (`status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='IM好友申请';

-- 客服号被加好友时的自动回复（空则用全局默认）
ALTER TABLE `fa_chat_agent_accounts`
  ADD COLUMN `friend_reply` varchar(500) NOT NULL DEFAULT '' COMMENT '被加好友自动回复' AFTER `scope`;
