<?php
/**
 * 触发进行中裂变活动：把未领的均分金额重拆为随机包
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
$root = dirname(__DIR__);
define('APP_PATH', $root . '/application/');
define('ROOT_PATH', $root . DIRECTORY_SEPARATOR);
define('RUNTIME_PATH', $root . '/runtime/');
define('EXTEND_PATH', $root . '/extend/');
define('VENDOR_PATH', $root . '/vendor/');
define('CONF_PATH', APP_PATH);
require $root . '/thinkphp/base.php';
\think\App::initCommon();

use app\common\library\FansHubFission;
use think\Db;

$rows = Db::name('fans_fission_activity')
    ->where('status', 'in', [1, 2, 3])
    ->order('id', 'desc')
    ->limit(5)
    ->select();
$rows = is_array($rows) ? $rows : $rows->toArray();
foreach ($rows as $act) {
    $id = (int)$act['id'];
    $n = FansHubFission::ensureQualPayouts($id);
    $stats = Db::name('fans_fission_qual')
        ->where('activity_id', $id)
        ->field('COUNT(*) c, ROUND(SUM(win_amount),2) s, ROUND(MIN(win_amount),2) mn, ROUND(MAX(win_amount),2) mx, COUNT(DISTINCT win_amount) distinct_amt')
        ->find();
    echo sprintf(
        "#%d status=%s changed=%d quals=%s sum=%s min=%s max=%s distinct=%s\n",
        $id,
        $act['status'],
        $n,
        $stats['c'],
        $stats['s'],
        $stats['mn'],
        $stats['mx'],
        $stats['distinct_amt']
    );
}
