<?php
/**
 * 资金流水列表加速：fa_fans_ledger (user_id, id)
 * Usage: php scripts/patch_fans_ledger_user_id_index.php
 */
define('APP_PATH', __DIR__ . '/../application/');
require __DIR__ . '/../thinkphp/base.php';
\think\App::initCommon();

$prefix = \think\Config::get('database.prefix') ?: 'fa_';
$table = $prefix . 'fans_ledger';
$rows = \think\Db::query("SHOW INDEX FROM `{$table}`");
$names = [];
foreach ($rows as $r) {
    $names[$r['Key_name']] = true;
}
if (!empty($names['idx_user_id_id'])) {
    echo "OK idx_user_id_id already exists\n";
    exit(0);
}
\think\Db::execute("ALTER TABLE `{$table}` ADD INDEX `idx_user_id_id` (`user_id`,`id`)");
echo "OK added idx_user_id_id on {$table}\n";
