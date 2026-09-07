<?php
/**
 * 校正裂变领取时间：把「未来」的 claimed_at 拉回到当前之前。
 *   php scripts/fission_clamp_future_claimed_at.php --id=22
 *   php scripts/fission_clamp_future_claimed_at.php --id=22 --apply
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
    fwrite(STDERR, "usage: php scripts/fission_clamp_future_claimed_at.php --id=22 [--apply]\n");
    exit(1);
}

$now = time();
$future = (int)Db::name('fans_fission_qual')
    ->where('activity_id', $id)
    ->where('claimed', 1)
    ->where('claimed_at', '>', $now)
    ->count();
$max = (int)Db::name('fans_fission_qual')->where('activity_id', $id)->where('claimed', 1)->max('claimed_at');
echo "activity=#{$id} now=" . date('Y-m-d H:i:s', $now) . " future={$future} max=" . ($max ? date('Y-m-d H:i:s', $max) : '-') . "\n";

if (!$apply) {
    echo "Dry-run. Re-run with --apply to clamp via syncBotClaimsToProgress.\n";
    exit(0);
}

$act = Db::name('fans_fission_activity')->where('id', $id)->find();
$target = (int)($act['global_quals'] ?? 0);
$res = FansHubFission::syncBotClaimsToProgress($id, $target, 3);
echo json_encode($res, JSON_UNESCAPED_UNICODE) . "\n";

$future2 = (int)Db::name('fans_fission_qual')
    ->where('activity_id', $id)
    ->where('claimed', 1)
    ->where('claimed_at', '>', time())
    ->count();
$max2 = (int)Db::name('fans_fission_qual')->where('activity_id', $id)->where('claimed', 1)->max('claimed_at');
echo "after future={$future2} max=" . ($max2 ? date('Y-m-d H:i:s', $max2) : '-') . "\n";
