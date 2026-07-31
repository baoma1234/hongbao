-- 通讯模块：私聊 / 群聊 / 红包
-- 执行: mysql -u root -p your_db < sql/chat_im.sql
-- 表前缀与 FastAdmin 一致: fa_

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- 会话消息（私聊 + 群聊共用，纯文本）
-- conversation_type: 1=私聊 2=群聊
-- conversation_id: 私聊=双方 user_id 升序拼接 "min_max"；群聊=group_id
-- ----------------------------
CREATE TABLE IF NOT EXISTS `fa_chat_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `msg_id` varchar(40) NOT NULL DEFAULT '' COMMENT '业务消息ID(雪花/UUID，客户端去重)',
  `conversation_type` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '1私聊 2群聊',
  `conversation_id` varchar(64) NOT NULL DEFAULT '' COMMENT '会话键',
  `group_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '群ID，私聊为0',
  `from_user_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '发送者会员ID',
  `to_user_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '私聊接收者；群聊为0',
  `msg_type` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '1文本 2红包 3系统 4图片 5视频 6表情包 7文件',
  `content` varchar(2000) NOT NULL DEFAULT '' COMMENT '纯文本内容',
  `extra` json DEFAULT NULL COMMENT '扩展：红包ID、引用等',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '1正常 2撤回 3删除',
  `createtime` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_msg_id` (`msg_id`),
  KEY `idx_conv_time` (`conversation_type`, `conversation_id`, `id`),
  KEY `idx_group_time` (`group_id`, `id`),
  KEY `idx_from_user` (`from_user_id`, `id`),
  KEY `idx_to_user` (`to_user_id`, `id`),
  KEY `idx_priv_from_conv` (`conversation_type`, `status`, `from_user_id`, `conversation_id`, `id`),
  KEY `idx_priv_to_conv` (`conversation_type`, `status`, `to_user_id`, `conversation_id`, `id`),
  KEY `idx_conv_status_id` (`conversation_type`, `conversation_id`, `status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='IM消息';

-- ----------------------------
-- 群组
-- ----------------------------
CREATE TABLE IF NOT EXISTS `fa_chat_groups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL DEFAULT '' COMMENT '群名称',
  `avatar` varchar(255) NOT NULL DEFAULT '' COMMENT '群头像URL',
  `owner_user_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '群主会员ID',
  `notice` varchar(500) NOT NULL DEFAULT '' COMMENT '群公告',
  `member_count` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '成员数缓存',
  `max_members` int(10) unsigned NOT NULL DEFAULT '500' COMMENT '人数上限',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '1正常 2解散 3禁言全员',
  `createtime` int(10) unsigned NOT NULL DEFAULT '0',
  `updatetime` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_owner` (`owner_user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='IM群组';

-- ----------------------------
-- 群成员
-- ----------------------------
CREATE TABLE IF NOT EXISTS `fa_chat_group_members` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `group_id` int(10) unsigned NOT NULL DEFAULT '0',
  `user_id` int(10) unsigned NOT NULL DEFAULT '0',
  `role` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '1成员 2管理员 3群主',
  `nickname` varchar(64) NOT NULL DEFAULT '' COMMENT '群内昵称',
  `mute_until` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '禁言到期时间戳，0不禁言',
  `last_read_msg_id` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '已读游标(messages.id)',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '1在群 2已退群',
  `jointime` int(10) unsigned NOT NULL DEFAULT '0',
  `updatetime` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_group_user` (`group_id`, `user_id`),
  KEY `idx_user_group` (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='IM群成员';

-- ----------------------------
-- 红包主单（发红包）
-- packet_type: 1普通均分 2拼手气随机
-- scope_type: 1私聊 2群聊
-- ----------------------------
CREATE TABLE IF NOT EXISTS `fa_chat_red_packets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `packet_no` varchar(40) NOT NULL DEFAULT '' COMMENT '红包业务单号',
  `scope_type` tinyint(3) unsigned NOT NULL DEFAULT '2' COMMENT '1私聊 2群聊',
  `conversation_id` varchar(64) NOT NULL DEFAULT '' COMMENT '所属会话',
  `group_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '群红包对应群ID',
  `to_user_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '私聊红包接收方',
  `from_user_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '发送者',
  `packet_type` tinyint(3) unsigned NOT NULL DEFAULT '2' COMMENT '1普通 2拼手气',
  `total_amount` decimal(12,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '总金额(元)',
  `total_count` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '总个数',
  `remain_amount` decimal(12,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '剩余金额(落库快照，权威在Redis)',
  `remain_count` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '剩余个数(落库快照)',
  `blessing` varchar(100) NOT NULL DEFAULT '恭喜发财' COMMENT '祝福语',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '1可抢 2已抢完 3已过期退回 4已关闭',
  `expiretime` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '过期时间',
  `finished_time` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '抢完时间',
  `refund_amount` decimal(12,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '过期退回金额',
  `createtime` int(10) unsigned NOT NULL DEFAULT '0',
  `updatetime` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_packet_no` (`packet_no`),
  KEY `idx_group_status` (`group_id`, `status`),
  KEY `idx_from_user` (`from_user_id`, `id`),
  KEY `idx_expire` (`status`, `expiretime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='红包主单';

-- ----------------------------
-- 红包领取记录（防重复领取靠唯一索引）
-- ----------------------------
CREATE TABLE IF NOT EXISTS `fa_chat_red_packet_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `packet_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `packet_no` varchar(40) NOT NULL DEFAULT '',
  `user_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '领取人',
  `amount` decimal(12,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '到手金额',
  `is_best` tinyint(1) unsigned NOT NULL DEFAULT '0' COMMENT '是否手气最佳',
  `createtime` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_packet_user` (`packet_id`, `user_id`),
  KEY `idx_user_time` (`user_id`, `id`),
  KEY `idx_packet` (`packet_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='红包领取记录';

-- ----------------------------
-- 可选：后台托管账号绑定（运营用某个会员号代聊）
-- ----------------------------
CREATE TABLE IF NOT EXISTS `fa_chat_agent_accounts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '被托管的会员ID(对外发言身份)',
  `admin_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '绑定的后台管理员ID，0=公共池',
  `label` varchar(64) NOT NULL DEFAULT '' COMMENT '备注名，如客服1号',
  `scope` varchar(32) NOT NULL DEFAULT 'all' COMMENT 'all|private|group',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '1启用 0停用',
  `createtime` int(10) unsigned NOT NULL DEFAULT '0',
  `updatetime` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_id` (`user_id`),
  KEY `idx_admin` (`admin_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='IM后台托管账号';

-- ----------------------------
-- 用户自定义聊天表情包
-- ----------------------------
CREATE TABLE IF NOT EXISTS `fa_chat_user_stickers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '会员ID',
  `name` varchar(64) NOT NULL DEFAULT '' COMMENT '展示名',
  `url` varchar(255) NOT NULL DEFAULT '' COMMENT '相对路径 /uploads/stickers/...',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '1正常 0删除',
  `createtime` int(10) unsigned NOT NULL DEFAULT '0',
  `updatetime` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_user_status` (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户自定义IM表情包';

-- ----------------------------
-- 会话已读游标（私聊 + 群聊）
-- ----------------------------
CREATE TABLE IF NOT EXISTS `fa_chat_conversation_read` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL DEFAULT '0',
  `conversation_type` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '1私聊 2群聊',
  `conversation_id` varchar(64) NOT NULL DEFAULT '',
  `last_read_msg_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `updatetime` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_conv` (`user_id`,`conversation_type`,`conversation_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='IM会话已读游标';

SET FOREIGN_KEY_CHECKS = 1;
