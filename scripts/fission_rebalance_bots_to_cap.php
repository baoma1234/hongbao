<?php
/**
 * 一次性：按 global_cap 重拆活动机器人金额（真人不动）。
 *   php scripts/fission_rebalance_bots_to_cap.php --id=22
 *   php scripts/fission_rebalance_bots_to_cap.php --id=22 --apply
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
    fwrite(STDERR, "usage: php scripts/fission_rebalance_bots_to_cap.php --id=22 [--apply]\n");
    exit(1);
}

$act = Db::name('fans_fission_activity')->where('id', $id)->find();
if (!$act) {
    fwrite(STDERR, "activity not found\n");
    exit(1);
}

$aggBefore = Db::name('fans_fission_qual')->where('activity_id', $id)
    ->field('COUNT(*) c, ROUND(SUM(win_amount),2) sum_amt, ROUND(SUM(CASE WHEN claimed=1 THEN win_amount ELSE 0 END),2) claimed_amt')
    ->find();
$cap = max(1, (int)$act['global_cap']);
$pool = round((float)$act['pool_amount'], 2);
$cnt = (int)$aggBefore['c'];
$ideal = round($pool * $cnt / $cap, 2);
echo "activity=#{$id} pool={$pool} cap={$cap} rows={$cnt} sum={$aggBefore['sum_amt']} ideal≈{$ideal}\n";

if (!$apply) {
    echo "Dry-run. Re-run with --apply to call syncBotClaimsToProgress (rebalance).\n";
    exit(0);
}

$target = (int)$act['global_quals'];
if ($target <= 0) {
    $target = $cnt;
}
$res = FansHubFission::syncBotClaimsToProgress($id, $target, 3);
echo json_encode($res, JSON_UNESCAPED_UNICODE) . "\n";

$aggAfter = Db::name('fans_fission_qual')->where('activity_id', $id)
    ->field('COUNT(*) c, ROUND(SUM(win_amount),2) sum_amt, ROUND(SUM(CASE WHEN claimed=1 THEN win_amount ELSE 0 END),2) claimed_amt')
    ->find();
echo "after=" . json_encode($aggAfter, JSON_UNESCAPED_UNICODE) . "\n";
