-- Per-user conversation inbox (replaces cold-start GROUP BY MAX(id) on chat_messages)
-- Apply: php scripts/patch_chat_user_conversations.php

CREATE TABLE IF NOT EXISTS `fa_chat_user_conversations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `conversation_type` tinyint unsigned NOT NULL COMMENT '1私聊 2群聊',
  `conversation_id` varchar(64) NOT NULL,
  `peer_user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '私聊对方；群为0',
  `group_id` int unsigned NOT NULL DEFAULT 0 COMMENT '群id；私聊为0',
  `last_msg_id` bigint unsigned NOT NULL DEFAULT 0,
  `last_msg_time` int unsigned NOT NULL DEFAULT 0,
  `updatetime` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_conv` (`user_id`,`conversation_type`,`conversation_id`),
  KEY `idx_user_last` (`user_id`,`last_msg_id`),
  KEY `idx_user_time` (`user_id`,`last_msg_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户会话收件箱（列表冷启动）';
