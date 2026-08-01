-- 用户删除私聊会话：隐藏列表 + 全量消息备份（不删 fa_chat_messages，对方仍可见）
-- 执行: php scripts/apply_chat_conversation_deleted_sql.php
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `fa_chat_conversation_deleted` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '操作删除的用户',
  `conversation_type` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '1私聊',
  `conversation_id` varchar(64) NOT NULL DEFAULT '' COMMENT '会话键 min_max',
  `peer_user_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '对方会员ID',
  `deleted_at` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '删除时间',
  `restored_at` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '0=仍隐藏；有值=因新消息恢复列表',
  `backup_msg_count` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '本次备份消息条数',
  `last_msg_id` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '删除时最后消息ID',
  `payload_json` mediumtext COMMENT '会话元数据快照',
  PRIMARY KEY (`id`),
  KEY `idx_user_active` (`user_id`, `restored_at`, `conversation_type`),
  KEY `idx_user_conv` (`user_id`, `conversation_type`, `conversation_id`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户删除私聊会话记录';

CREATE TABLE IF NOT EXISTS `fa_chat_messages_deleted_backup` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `backup_id` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT 'fa_chat_conversation_deleted.id',
  `user_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '谁删的',
  `orig_msg_id` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '原 fa_chat_messages.id',
  `msg_id` varchar(40) NOT NULL DEFAULT '',
  `conversation_type` tinyint(3) unsigned NOT NULL DEFAULT '1',
  `conversation_id` varchar(64) NOT NULL DEFAULT '',
  `group_id` int(10) unsigned NOT NULL DEFAULT '0',
  `from_user_id` int(10) unsigned NOT NULL DEFAULT '0',
  `to_user_id` int(10) unsigned NOT NULL DEFAULT '0',
  `msg_type` tinyint(3) unsigned NOT NULL DEFAULT '1',
  `content` varchar(2000) NOT NULL DEFAULT '',
  `extra` json DEFAULT NULL,
  `status` tinyint(3) unsigned NOT NULL DEFAULT '1',
  `createtime` int(10) unsigned NOT NULL DEFAULT '0',
  `backed_up_at` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_backup` (`backup_id`),
  KEY `idx_user_conv` (`user_id`, `conversation_id`),
  KEY `idx_orig` (`orig_msg_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='删除会话时消息全量备份';
