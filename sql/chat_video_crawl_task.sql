CREATE TABLE IF NOT EXISTS `fa_chat_video_crawl_task` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL DEFAULT '' COMMENT '任务名',
  `api_url` varchar(512) NOT NULL DEFAULT '' COMMENT '采集接口基址(可带或不带?page=)',
  `group_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '发送到的群ID',
  `send_user_id` int(10) unsigned NOT NULL DEFAULT '11111111' COMMENT '发送UID',
  `start_page` int(10) unsigned NOT NULL DEFAULT '100' COMMENT '倒序起始页(先采这一页)',
  `current_page` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '下一页待采(倒序递减,0=未开始用start_page)',
  `pagecount` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '接口总页数',
  `pages_done` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '已采集页数',
  `sent_count` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '已发送视频数',
  `skip_count` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '跳过(重复/无效)',
  `page_limit` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '每页最多发几条,0=整页',
  `force_run` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1=立刻采一页',
  `auto_run` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1=定时自动倒序采',
  `last_page` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '最近采过的页码',
  `last_error` varchar(255) NOT NULL DEFAULT '',
  `last_run_time` int(10) unsigned NOT NULL DEFAULT '0',
  `status` enum('normal','hidden') NOT NULL DEFAULT 'hidden' COMMENT 'normal=启用',
  `remark` varchar(255) NOT NULL DEFAULT '',
  `createtime` int(10) unsigned DEFAULT NULL,
  `updatetime` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='视频采集任务';

CREATE TABLE IF NOT EXISTS `fa_chat_video_crawl_sent` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `task_id` int(10) unsigned NOT NULL DEFAULT '0',
  `vod_id` int(10) unsigned NOT NULL DEFAULT '0',
  `group_id` int(10) unsigned NOT NULL DEFAULT '0',
  `message_id` int(10) unsigned NOT NULL DEFAULT '0',
  `createtime` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_task_vod` (`task_id`,`vod_id`),
  KEY `idx_vod` (`vod_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='视频采集已发去重';
