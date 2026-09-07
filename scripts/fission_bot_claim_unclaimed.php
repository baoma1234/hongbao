<?php
/**
 * 裂变未领资格：用机器人账户按 createtime 顺序随机代领（写入 claimed_at 递增，记录好看）
 *
 * 默认只处理：is_bot 持有者，或昵称匹配 /^红宝\d+$/ 的账号（不动真人如「背包客」）
 *
 *   php scripts/fission_bot_claim_unclaimed.php           # dry-run
 *   php scripts/fission_bot_claim_unclaimed.php --apply
 *   php scripts/fission_bot_claim_unclaimed.php --apply --all   # 含全部未领（慎用）
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
use think\Db;

$apply = in_array('--apply', $argv, true);
$all = in_array('--all', $argv, true);

$act = Db::name('fans_fission_activity')
    ->where('status', FissionActivity::STATUS_RUNNING)
    ->order('id', 'desc')
    ->find();
if (!$act) {
    $act = Db::name('fans_fission_activity')->order('id', 'desc')->find();
}
if (!$act) {
    fwrite(STDERR, "no activity\n");
    exit(1);
}
$aid = (int)$act['id'];
echo "activity=#{$aid} status={$act['status']} global_quals={$act['global_quals']}\n";
echo 'mode: ' . ($apply ? 'APPLY' : 'DRY-RUN') . ($all ? ' ALL' : ' bot-like-only') . "\n";

$bots = Db::name('fans_account')
    ->alias('a')
    ->join('user u', 'u.id=a.user_id')
    ->where('a.is_bot', 1)
    ->where('a.status', 'normal')
    ->where('u.status', 'normal')
    ->field('a.user_id,u.nickname')
    ->orderRaw('RAND()')
    ->limit(200)
    ->select();
$bots = is_array($bots) ? $bots : $bots->toArray();
if (count($bots) < 3) {
    fwrite(STDERR, "need >=3 bot accounts, got " . count($bots) . "\n");
    exit(1);
}
echo 'bots=' . count($bots) . "\n";

$rows = Db::name('fans_fission_qual')
    ->alias('q')
    ->join('user u', 'u.id=q.user_id', 'LEFT')
    ->join('fans_account a', 'a.user_id=q.user_id', 'LEFT')
    ->where('q.activity_id', $aid)
    ->where('q.claimed', 0)
    ->where('q.win_amount', '>', 0)
    ->field('q.id,q.user_id,q.source,q.win_amount,q.createtime,u.nickname,COALESCE(a.is_bot,0) AS is_bot')
    ->order('q.createtime asc,q.id asc')
    ->select();
$rows = is_array($rows) ? $rows : $rows->toArray();

$targets = [];
foreach ($rows as $r) {
    $nick = trim((string)($r['nickname'] ?? ''));
    $isBot = (int)($r['is_bot'] ?? 0) === 1;
    $botLike = $isBot || (bool)preg_match('/^红宝\d+$/u', $nick);
    if ($all || $botLike) {
        $targets[] = $r;
    } else {
        echo "skip human uid={$r['user_id']} nick={$nick} amt={$r['win_amount']}\n";
    }
}
echo 'targets=' . count($targets) . "\n";

if (!$targets) {
    echo "nothing to claim\n";
    exit(0);
}

// claimed_at：按 createtime 排序，彼此间隔 30～180 秒，且不早于 createtime
$base = max(1, (int)$targets[0]['createtime']);
$cursor = $base + random_int(20, 90);

$plan = [];
$bi = 0;
shuffle($bots);
foreach ($targets as $i => $r) {
    if ($bi >= count($bots)) {
        shuffle($bots);
        $bi = 0;
    }
    $bot = $bots[$bi++];
    $ct = max($base, (int)$r['createtime']);
    if ($cursor < $ct + 15) {
        $cursor = $ct + random_int(15, 60);
    }
    $claimedAt = $cursor;
    $cursor += random_int(30, 180);
    $plan[] = [
        'qual_id'    => (int)$r['id'],
        'from_uid'   => (int)$r['user_id'],
        'from_nick'  => (string)$r['nickname'],
        'bot_uid'    => (int)$bot['user_id'],
        'bot_nick'   => (string)$bot['nickname'],
        'amount'     => round((float)$r['win_amount'], 2),
        'createtime' => $ct,
        'claimed_at' => $claimedAt,
    ];
}

foreach ($plan as $p) {
    echo sprintf(
        "qual#%d %s(%d) → bot %s(%d) ¥%s claim_at=%s\n",
        $p['qual_id'],
        $p['from_nick'],
        $p['from_uid'],
        $p['bot_nick'],
        $p['bot_uid'],
        number_format($p['amount'], 2, '.', ''),
        date('Y-m-d H:i:s', $p['claimed_at'])
    );
}

if (!$apply) {
    echo "Dry-run only. Re-run with --apply\n";
    exit(0);
}

$ok = 0;
foreach ($plan as $p) {
    Db::startTrans();
    try {
        $q = Db::name('fans_fission_qual')->where('id', $p['qual_id'])->lock(true)->find();
        if (!$q || (int)$q['claimed'] === 1) {
            Db::rollback();
            continue;
        }
        $amt = round((float)$q['win_amount'], 2);
        if ($amt <= 0) {
            Db::rollback();
            continue;
        }
        $botUid = (int)$p['bot_uid'];
        Db::name('fans_fission_qual')->where('id', (int)$q['id'])->update([
            'user_id'    => $botUid,
            'claimed'    => 1,
            'claimed_at' => (int)$p['claimed_at'],
        ]);
        FansHubService::changeAssets(
            $botUid,
            0,
            0,
            'fission_reward',
            '裂变红包机器人代领 #' . $aid . ' qual#' . (int)$q['id'],
            0,
            'fission_bot_claim',
            $amt
        );
        Db::commit();
        $ok++;
    } catch (\Throwable $e) {
        Db::rollback();
        fwrite(STDERR, 'fail qual#' . $p['qual_id'] . ' ' . $e->getMessage() . "\n");
    }
}

// 进度与真实份数对齐
$realCnt = (int)Db::name('fans_fission_qual')->where('activity_id', $aid)->count();
Db::name('fans_fission_activity')->where('id', $aid)->update([
    'global_quals' => min((int)$act['global_cap'], max($realCnt, (int)$act['global_quals'])),
    'updatetime'   => time(),
]);

echo "claimed_ok={$ok}\n";
