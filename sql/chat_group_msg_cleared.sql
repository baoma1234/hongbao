-- 用户删除群聊会话（本端软删）：只存水位消息 ID，历史/列表仅展示 id > cleared_msg_id
-- 执行: php scripts/apply_chat_group_msg_cleared_sql.php
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `fa_chat_group_msg_cleared` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '操作用户',
  `group_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '群ID',
  `cleared_msg_id` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '水位：仅展示 id > 此值的群消息',
  `updatetime` int(10) unsigned NOT NULL DEFAULT '0',
  `createtime` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_group` (`user_id`, `group_id`),
  KEY `idx_group` (`group_id`),
  KEY `idx_cleared` (`cleared_msg_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户群消息软删水位';
