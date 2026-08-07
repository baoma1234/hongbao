<?php
/**
 * 批量注册机器人（PDO，不依赖 ThinkPHP CLI）
 * php scripts/seed_bot_users.php
 * php scripts/seed_bot_users.php --count=300 --start=10000000001 --hongbao=100000
 */
$root = dirname(__DIR__);
$count = 300;
$start = '10000000001';
$hongbao = 100000.0;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--count=(\d+)$/', $arg, $m)) {
        $count = (int)$m[1];
    } elseif (preg_match('/^--start=(.+)$/', $arg, $m)) {
        $start = trim($m[1]);
    } elseif (preg_match('/^--hongbao=([\d.]+)$/', $arg, $m)) {
        $hongbao = (float)$m[1];
    }
}
$count = max(1, min(5000, $count));
$start = preg_replace('/\D+/', '', $start) ?: '10000000001';
$hongbao = max(0, round($hongbao, 2));

$env = parse_ini_file($root . '/.env', true);
$d = $env['database'];
$prefix = $d['prefix'] ?? 'fa_';
$userT = $prefix . 'user';
$accT = $prefix . 'fans_account';
$ledT = $prefix . 'fans_ledger';

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $d['hostname'] ?? '127.0.0.1',
        (int)($d['hostport'] ?? 3306),
        $d['database'] ?? ''
    ),
    $d['username'] ?? 'root',
    $d['password'] ?? '',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
    ]
);

$adj = ['快乐','幸运','阳光','神秘','闪闪','金色','赤焰','清风','星辰','云端','灵动','欢喜','锦鲤','如意','福气','天成','红火','奔腾','小幸运','大吉'];
$noun = ['小宝','红宝','达人','少年','星子','旅人','骑士','船长','渔夫','猎人','公子','小姐','掌柜','侠客','浪客','飞侠','喵喵','旺旺','豆豆','果果'];

function incMobile($digits)
{
    $carry = 1;
    $out = '';
    for ($i = strlen($digits) - 1; $i >= 0; $i--) {
        $n = (int)$digits[$i] + $carry;
        if ($n >= 10) {
            $out = '0' . $out;
            $carry = 1;
        } else {
            $out = (string)$n . $out;
            $carry = 0;
        }
    }
    return $carry ? ('1' . $out) : $out;
}

function allocId(PDO $pdo, $userT, $accT)
{
    for ($i = 0; $i < 80; $i++) {
        $id = random_int(10000000, 99999999);
        $st = $pdo->prepare("SELECT id FROM `{$userT}` WHERE id=? LIMIT 1");
        $st->execute([$id]);
        if ($st->fetchColumn()) {
            continue;
        }
        $st = $pdo->prepare("SELECT id FROM `{$accT}` WHERE id=? OR user_id=? LIMIT 1");
        $st->execute([$id, $id]);
        if ($st->fetchColumn()) {
            continue;
        }
        return $id;
    }
    throw new RuntimeException('无法分配唯一用户ID');
}

function uniqueNick(PDO $pdo, $userT, array $adj, array $noun)
{
    $chk = $pdo->prepare("SELECT id FROM `{$userT}` WHERE nickname=? LIMIT 1");
    for ($i = 0; $i < 40; $i++) {
        $nick = $adj[array_rand($adj)] . $noun[array_rand($noun)];
        if (mt_rand(0, 1)) {
            $nick .= (string)mt_rand(10, 99);
        }
        if (mb_strlen($nick) > 12) {
            $nick = mb_substr($nick, 0, 12);
        }
        $chk->execute([$nick]);
        if (!$chk->fetchColumn()) {
            return $nick;
        }
    }
    return '红宝' . substr((string)time(), -3) . str_pad((string)mt_rand(0, 999), 3, '0', STR_PAD_LEFT);
}

function randSalt($len = 6)
{
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $s = '';
    for ($i = 0; $i < $len; $i++) {
        $s .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $s;
}

$existsQ = $pdo->prepare("SELECT id FROM `{$userT}` WHERE mobile=? OR username=? LIMIT 1");
$insUser = $pdo->prepare(
    "INSERT INTO `{$userT}`
    (id, group_id, username, nickname, password, salt, email, mobile, avatar, level, score, jointime, joinip, logintime, loginip, prevtime, status, createtime, updatetime)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
);
$insAcc = $pdo->prepare(
    "INSERT INTO `{$accT}`
    (id, user_id, rights, balance, hongbao, flow_stage, member_level, status, is_bot, createtime, updatetime)
    VALUES (?,?,0,0,?,?,?,?,1,?,?)"
);
// ledger 兼容有/无 hongbao 列
$ledCols = $pdo->query("SHOW COLUMNS FROM `{$ledT}`")->fetchAll(PDO::FETCH_COLUMN);
$hasHongbao = in_array('hongbao_change', $ledCols, true);
if ($hasHongbao) {
    $insLed = $pdo->prepare(
        "INSERT INTO `{$ledT}`
        (user_id, type, rights_change, balance_change, hongbao_change, rights_after, balance_after, hongbao_after, remark, admin_id, createtime)
        VALUES (?,?,0,0,?,0,0,?,?,0,?)"
    );
} else {
    $insLed = $pdo->prepare(
        "INSERT INTO `{$ledT}`
        (user_id, type, rights_change, balance_change, rights_after, balance_after, remark, admin_id, createtime)
        VALUES (?,?,0,?,0,?,?,0,?)"
    );
}

echo "seed bots count={$count} start={$start} hongbao={$hongbao}\n";
$created = [];
$skipped = 0;
$errors = 0;
$mobile = $start;
$now = time();

for ($i = 0; $i < $count; $i++) {
    if ($i > 0) {
        $mobile = incMobile($mobile);
    }
    try {
        $existsQ->execute([$mobile, $mobile]);
        if ($existsQ->fetchColumn()) {
            $skipped++;
            continue;
        }
        $id = allocId($pdo, $userT, $accT);
        $nick = uniqueNick($pdo, $userT, $adj, $noun);
        $salt = randSalt(6);
        $pwdPlain = randSalt(10);
        $password = md5(md5($pwdPlain) . $salt);

        $pdo->beginTransaction();
        $insUser->execute([
            $id, 0, $mobile, $nick, $password, $salt, '', $mobile, '',
            1, 0, $now, '127.0.0.1', $now, '127.0.0.1', $now, 'normal', $now, $now,
        ]);
        $insAcc->execute([$id, $id, $hongbao, 'stage1', 1, 'normal', $now, $now]);
        if ($hongbao > 0) {
            if ($hasHongbao) {
                $insLed->execute([$id, 'admin_adjust', $hongbao, $hongbao, '机器人初始红宝', $now]);
            } else {
                $insLed->execute([$id, 'admin_adjust', $hongbao, $hongbao, '机器人初始红宝', $now]);
            }
        }
        $pdo->commit();
        $created[] = ['id' => $id, 'mobile' => $mobile, 'nickname' => $nick];
        if (count($created) <= 5 || count($created) % 50 === 0) {
            echo "OK #{$i} id={$id} mobile={$mobile} nick={$nick}\n";
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors++;
        echo "ERR mobile={$mobile} " . $e->getMessage() . "\n";
    }
}

echo 'done created=' . count($created) . " skipped={$skipped} errors={$errors}\n";
echo 'IDS=' . implode(',', array_column($created, 'id')) . "\n";
