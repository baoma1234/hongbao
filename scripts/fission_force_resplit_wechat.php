<?php
/**
 * 按「奖池 / 总份数」二倍均值强制重拆机器人金额（真人不动）。
 *   php scripts/fission_force_resplit_wechat.php --id=22 --apply
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

$apply = in_array('--apply', $argv, true);
$id = 0;
foreach ($argv as $a) {
    if (strpos($a, '--id=') === 0) {
        $id = (int)substr($a, 5);
    }
}
if ($id <= 0) {
    fwrite(STDERR, "usage: php scripts/fission_force_resplit_wechat.php --id=22 [--apply]\n");
    exit(1);
}

$before = Db::name('fans_fission_qual')->where('activity_id', $id)
    ->field('COUNT(*) c, ROUND(SUM(win_amount),2) s, ROUND(AVG(win_amount),2) a, ROUND(MIN(win_amount),2) mn, ROUND(MAX(win_amount),2) mx')
    ->find();
echo "before=" . json_encode($before, JSON_UNESCAPED_UNICODE) . "\n";
if (!$apply) {
    echo "Dry-run. Re-run with --apply\n";
    exit(0);
}
$res = FansHubFission::forceResplitBotAmountsWeChat($id);
echo json_encode($res, JSON_UNESCAPED_UNICODE) . "\n";
$after = Db::name('fans_fission_qual')->where('activity_id', $id)
    ->field('COUNT(*) c, ROUND(SUM(win_amount),2) s, ROUND(AVG(win_amount),2) a, ROUND(MIN(win_amount),2) mn, ROUND(MAX(win_amount),2) mx')
    ->find();
echo "after=" . json_encode($after, JSON_UNESCAPED_UNICODE) . "\n";
