<?php
require __DIR__ . '/../vendor/autoload.php';

$cfg = require __DIR__ . '/../config/app.php';
Im\Support\Db::init($cfg['db']);

$table = Im\Support\Db::table('chat_conversation_read');
$sql = <<<SQL
CREATE TABLE IF NOT EXISTS {$table} (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL DEFAULT '0',
  `conversation_type` tinyint(3) unsigned NOT NULL DEFAULT '1',
  `conversation_id` varchar(64) NOT NULL DEFAULT '',
  `last_read_msg_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `updatetime` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_conv` (`user_id`,`conversation_type`,`conversation_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;

Im\Support\Db::exec($sql);
echo "ok: chat_conversation_read ready\n";
