<?php
/**
 * OG 视讯昨日返水领取表
 * php scripts/ensure_og_rebate_claim.php
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
$table = $prefix . 'fans_og_rebate_claim';
$col = Db::query("SHOW TABLES LIKE '{$table}'");
if (!$col) {
    Db::execute(
        "CREATE TABLE `{$table}` (
          `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
          `user_id` int(10) unsigned NOT NULL DEFAULT 0,
          `biz_date` char(10) NOT NULL DEFAULT '' COMMENT '返水归属日 Y-m-d（昨日）',
          `bet_amount` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT '有效投注累计',
          `rate` decimal(8,4) NOT NULL DEFAULT 0.0000 COMMENT '返水比例小数',
          `rebate_amount` decimal(18,2) NOT NULL DEFAULT 0.00 COMMENT '领取金额',
          `ledger_id` int(10) unsigned NOT NULL DEFAULT 0,
          `createtime` int(10) unsigned NOT NULL DEFAULT 0,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uk_user_date` (`user_id`,`biz_date`),
          KEY `idx_date` (`biz_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='OG视讯昨日返水领取'"
    );
    echo "OK created {$table}\n";
} else {
    echo "OK exists {$table}\n";
}
