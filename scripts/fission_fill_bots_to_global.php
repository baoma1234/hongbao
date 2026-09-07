<?php
/**
 * 裂变活动：总份数对齐为 N（默认取当前 global_quals），
 * 真人资格不动，差额全部生成机器人已领取记录（随机金额、时间排序）。
 *
 *   php scripts/fission_fill_bots_to_global.php           # dry-run
 *   php scripts/fission_fill_bots_to_global.php --apply
 *   php scripts/fission_fill_bots_to_global.php --apply --target=77
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

use app\common\library\FansHubService;
use app\common\model\fanshub\FissionActivity;
use app\common\model\fanshub\FissionQual;
use think\Db;

$apply = in_array('--apply', $argv, true);
$targetOpt = 0;
foreach ($argv as $a) {
    if (strpos($a, '--target=') === 0) {
        $targetOpt = (int)substr($a, 9);
    }
}

$act = Db::name('fans_fission_activity')
    ->where('status', FissionActivity::STATUS_RUNNING)
    ->order('id', 'desc')
    ->find();
if (!$act) {
    fwrite(STDERR, "no running activity\n");
    exit(1);
}
$aid = (int)$act['id'];
$pool = round((float)$act['pool_amount'], 2);
$target = $targetOpt > 0 ? $targetOpt : max(1, (int)$act['global_quals']);
$target = min($target, max(1, (int)$act['global_cap']));

echo "activity=#{$aid} pool={$pool} global_quals={$act['global_quals']} cap={$act['global_cap']}\n";
echo "target_total={$target} mode=" . ($apply ? 'APPLY' : 'DRY-RUN') . "\n";

$existing = Db::name('fans_fission_qual')
    ->alias('q')
    ->join('user u', 'u.id=q.user_id', 'LEFT')
    ->join('fans_account a', 'a.user_id=q.user_id', 'LEFT')
    ->where('q.activity_id', $aid)
    ->field('q.*,u.nickname,COALESCE(a.is_bot,0) AS is_bot')
    ->order('q.id', 'asc')
    ->select();
$existing = is_array($existing) ? $existing : $existing->toArray();
$existCnt = count($existing);
$existSum = 0.0;
foreach ($existing as $r) {
    $existSum = round($existSum + (float)$r['win_amount'], 2);
    $flag = ((int)$r['is_bot'] === 1) ? 'bot' : 'human';
    echo sprintf(
        " keep #%d %s uid=%s nick=%s amt=%s claimed=%s\n",
        $r['id'],
        $flag,
        $r['user_id'],
        $r['nickname'],
        $r['win_amount'],
        $r['claimed']
    );
}
echo "existing={$existCnt} sum={$existSum}\n";

$need = $target - $existCnt;
if ($need <= 0) {
    echo "already >= target, only sync cap/quals\n";
    if ($apply) {
        Db::name('fans_fission_activity')->where('id', $aid)->update([
            'global_cap'   => max($target, (int)($act['global_cap'] ?? $target)),
            'global_quals' => $target,
            'updatetime'   => time(),
        ]);
    }
    exit(0);
}

$cap = max(1, (int)$act['global_cap']);
$remain = round(max(0, $pool - $existSum), 2);
$remainCents = (int)round($remain * 100);
$slotsLeftToCap = max(1, $cap - $existCnt);
echo "need_bots={$need} remain_pool={$remain} slots_left_to_cap={$slotsLeftToCap}\n";

// 按「剩余金额 / 剩余至 cap 份数」二倍均值，只取本次 need 份（不把整池分光）
$fullParts = splitCents($remainCents, $slotsLeftToCap);
$parts = array_slice($fullParts, 0, $need);
$partsSum = array_sum($parts);
echo "new_parts_sum_cents={$partsSum} reserved_for_later_cents=" . ($remainCents - $partsSum) . "\n";

$bots = Db::name('fans_account')
    ->alias('a')
    ->join('user u', 'u.id=a.user_id')
    ->where('a.is_bot', 1)
    ->where('a.status', 'normal')
    ->where('u.status', 'normal')
    ->field('a.user_id,u.nickname')
    ->orderRaw('RAND()')
    ->limit(max(50, $need * 2))
    ->select();
$bots = is_array($bots) ? $bots : $bots->toArray();
if (count($bots) < $need) {
    // 允许重复使用机器人
    while (count($bots) < $need) {
        $bots = array_merge($bots, $bots);
    }
}
shuffle($bots);

$startTs = (int)$act['start_time'];
$nowTs = time();
$actEnd = (int)$act['end_time'] ?: $nowTs;
$endTs = min($nowTs, $actEnd);
if ($endTs <= $startTs + 60) {
    $endTs = $nowTs;
    $startTs = max(0, min($startTs, $nowTs - 600));
}
if ($endTs <= $startTs + 30) {
    $startTs = max(0, $endTs - 600);
}

// 在「开始→当前」时间轴上生成领取时间，绝不写入未来
$times = [];
for ($i = 0; $i < $need; $i++) {
    $span = max(1, $endTs - $startTs);
    $t = (int)round($startTs + $span * (($i + 0.5) / $need));
    $t += random_int(-120, 180);
    $t = max($startTs + 30, min($endTs - 1, $t));
    $t = min($t, $nowTs - 1);
    $times[] = $t;
}
sort($times);

$plan = [];
for ($i = 0; $i < $need; $i++) {
    $bot = $bots[$i % count($bots)];
    $amt = round($parts[$i] / 100, 2);
    $ct = max($startTs + 10, $times[$i] - random_int(20, 90));
    $plan[] = [
        'bot_uid'    => (int)$bot['user_id'],
        'bot_nick'   => (string)$bot['nickname'],
        'amount'     => $amt,
        'createtime' => $ct,
        'claimed_at' => $times[$i],
    ];
}

echo "--- plan first/last 3 ---\n";
foreach (array_slice($plan, 0, 3) as $p) {
    echo sprintf(" + bot %s(%d) ¥%s at %s\n", $p['bot_nick'], $p['bot_uid'], number_format($p['amount'], 2, '.', ''), date('Y-m-d H:i:s', $p['claimed_at']));
}
echo "...\n";
foreach (array_slice($plan, -3) as $p) {
    echo sprintf(" + bot %s(%d) ¥%s at %s\n", $p['bot_nick'], $p['bot_uid'], number_format($p['amount'], 2, '.', ''), date('Y-m-d H:i:s', $p['claimed_at']));
}
$sumPlan = 0.0;
foreach ($plan as $p) {
    $sumPlan = round($sumPlan + $p['amount'], 2);
}
echo "plan_sum={$sumPlan} final_total_amt=" . round($existSum + $sumPlan, 2) . " final_count=" . ($existCnt + $need) . "\n";

if (!$apply) {
    echo "Dry-run only. Re-run with --apply\n";
    exit(0);
}

$ok = 0;
Db::startTrans();
try {
    foreach ($plan as $p) {
        $qid = (int)Db::name('fans_fission_qual')->insertGetId([
            'activity_id' => $aid,
            'user_id'     => $p['bot_uid'],
            'source'      => FissionQual::SOURCE_ADMIN,
            'ref_user_id' => 0,
            'win_amount'  => $p['amount'],
            'claimed'     => 1,
            'claimed_at'  => $p['claimed_at'],
            'createtime'  => $p['createtime'],
        ]);
        FansHubService::changeAssets(
            $p['bot_uid'],
            0,
            0,
            'fission_reward',
            '裂变红包机器人填充 #' . $aid . ' qual#' . $qid,
            0,
            'fission_bot_fill',
            $p['amount']
        );
        $ok++;
    }
    Db::name('fans_fission_activity')->where('id', $aid)->update([
        'global_cap'   => max($target, (int)($act['global_cap'] ?? $target)),
        'global_quals' => $target,
        'updatetime'   => time(),
    ]);
    Db::commit();
} catch (\Throwable $e) {
    Db::rollback();
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}

$agg = Db::name('fans_fission_qual')
    ->where('activity_id', $aid)
    ->field('COUNT(*) c, SUM(claimed=1) claimed, ROUND(SUM(win_amount),2) sum_amt, ROUND(SUM(CASE WHEN claimed=1 THEN win_amount ELSE 0 END),2) claimed_amt')
    ->find();
$act2 = Db::name('fans_fission_activity')->where('id', $aid)->find();
echo "done ok={$ok}\n";
echo "agg=" . json_encode($agg, JSON_UNESCAPED_UNICODE) . "\n";
echo "act cap={$act2['global_cap']} quals={$act2['global_quals']}\n";

function splitCents($totalCents, $n)
{
    $totalCents = max(0, (int)$totalCents);
    $n = max(1, (int)$n);
    if ($totalCents < $n) {
        $out = array_fill(0, $n, 0);
        for ($i = 0; $i < $totalCents; $i++) {
            $out[$i] = 1;
        }
        return $out;
    }
    $remain = $totalCents;
    $out = [];
    for ($i = 0; $i < $n - 1; $i++) {
        $left = $n - $i;
        $max = max(1, (int)floor(($remain / $left) * 2));
        $max = min($max, $remain - ($left - 1));
        $amt = $max <= 1 ? 1 : random_int(1, $max);
        $out[] = $amt;
        $remain -= $amt;
    }
    $out[] = $remain;
    shuffle($out);
    return $out;
}
