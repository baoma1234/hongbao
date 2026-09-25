<?php
/**
 * 群维护字段：fa_chat_groups.maintenance
 * php scripts/ensure_group_maintenance.php
 */
$root = dirname(__DIR__);
define('APP_PATH', $root . '/application/');
define('ROOT_PATH', $root . '/');
define('RUNTIME_PATH', $root . '/runtime/');
define('DS', DIRECTORY_SEPARATOR);

require $root . '/thinkphp/base.php';
\think\App::initCommon();

use think\Config;
use think\Db;

$prefix = (string)Config::get('database.prefix');
if ($prefix === '') {
    $prefix = 'fa_';
}
$table = $prefix . 'chat_groups';
$col = Db::query("SHOW COLUMNS FROM `{$table}` LIKE 'maintenance'");
if (!$col) {
    Db::execute(
        "ALTER TABLE `{$table}` ADD COLUMN `maintenance` tinyint(1) unsigned NOT NULL DEFAULT 0 "
        . "COMMENT '1=维护中：禁进群、在线显示0' AFTER `status`"
    );
    echo "OK added {$table}.maintenance\n";
} else {
    echo "OK column exists\n";
}

$gid = isset($argv[1]) ? (int)$argv[1] : 12;
if ($gid > 0) {
    $n = Db::name('chat_groups')->where('id', $gid)->update([
        'maintenance' => 1,
        'updatetime'  => time(),
    ]);
    echo "OK group {$gid} maintenance=1 updated={$n}\n";
}
