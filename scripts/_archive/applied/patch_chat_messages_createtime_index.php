<?php
/**
 * 按时间清理聊天加速：fa_chat_messages (createtime, id)
 * Usage: php scripts/patch_chat_messages_createtime_index.php
 */
define('APP_PATH', __DIR__ . '/../application/');
require __DIR__ . '/../thinkphp/base.php';
\think\App::initCommon();

$prefix = \think\Config::get('database.prefix') ?: 'fa_';
$table = $prefix . 'chat_messages';
$rows = \think\Db::query("SHOW INDEX FROM `{$table}`");
$names = [];
foreach ($rows as $r) {
    $names[$r['Key_name']] = true;
}
if (!empty($names['idx_createtime_id'])) {
    echo "OK idx_createtime_id already exists\n";
    exit(0);
}
\think\Db::execute("ALTER TABLE `{$table}` ADD INDEX `idx_createtime_id` (`createtime`,`id`)");
echo "OK added idx_createtime_id on {$table}\n";
