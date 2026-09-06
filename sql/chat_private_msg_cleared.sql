-- 用户删除私聊会话（本端软删水位）：历史仅展示 id > cleared_msg_id；原消息仍在 fa_chat_messages，后台代聊可查
-- 执行: php scripts/apply_chat_private_msg_cleared_sql.php
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `fa_chat_private_msg_cleared` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '操作用户',
  `conversation_id` varchar(64) NOT NULL DEFAULT '' COMMENT '私聊会话 ID（小id_大id）',
  `peer_user_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '对方用户',
  `cleared_msg_id` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '水位：仅展示 id > 此值的私聊消息',
  `updatetime` int(10) unsigned NOT NULL DEFAULT '0',
  `createtime` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_conv` (`user_id`, `conversation_id`),
  KEY `idx_peer` (`peer_user_id`),
  KEY `idx_cleared` (`cleared_msg_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户私聊消息软删水位';
