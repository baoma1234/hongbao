<?php
/**
 * 群置顶消息：fa_chat_groups.pinned_msg_ids
 * php scripts/ensure_group_pinned_msgs.php
 */
$root = dirname(__DIR__);
defined('APP_PATH') or define('APP_PATH', $root . '/application/');
defined('ROOT_PATH') or define('ROOT_PATH', $root . DIRECTORY_SEPARATOR);
defined('RUNTIME_PATH') or define('RUNTIME_PATH', $root . '/runtime/');
require $root . '/thinkphp/base.php';
\think\App::initCommon();

use think\Config;
use think\Db;

$prefix = (string)Config::get('database.prefix');
if ($prefix === '') {
    $prefix = 'fa_';
}
$table = $prefix . 'chat_groups';
$col = Db::query("SHOW COLUMNS FROM `{$table}` LIKE 'pinned_msg_ids'");
if (!$col) {
    Db::execute(
        "ALTER TABLE `{$table}` ADD COLUMN `pinned_msg_ids` text NULL "
        . "COMMENT '置顶消息 id JSON 数组（新→旧）' AFTER `notice_images`"
    );
    echo "OK added {$table}.pinned_msg_ids\n";
} else {
    echo "OK column exists\n";
}
