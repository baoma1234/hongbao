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

$parts = [
    '清','安','乐','思','语','夏','秋','晨','晚','星','月','云','风','雨','雪',
    '花','叶','竹','松','梅','兰','菊','桃','杏','柳','溪','江','海','山','川',
    '阿','小','大','老','可','若','一','半','初','末','南','北','东','西','中',
    '明','亮','辉','华','杰','豪','博','文','武','轩','宇','浩','然','逸','远',
    '婉','婷','静','柔','慧','颖','欣','怡','悦','琳','瑶','琪','珊','璇','依',
    '子','兮','也','之','然','如','意','心','念','想','愿','梦','行','止','归',
    '宝','贝','果','豆','米','茶','酒','糖','盐','椒','鱼','鸟','猫','鹿','鹤',
];
$phrases = [
    '清风','明月','星河','云端','锦鲤','如意','小满','安然','欢喜','知夏',
    '南巷','北辰','东篱','西窗','半夏','初晴','晚风','听雨','望山','临江',
    '青柠','柠檬','柚子','桃夭','杏雨','柳烟','竹影','松涛','梅香','兰息',
    '小鹿','白鸽','玄狐','金乌','玉兔','青鸟','闲云','野鹤','孤舟','扁舟',
    '阿木','阿南','阿夏','阿秋','阿宁','阿遥','阿川','阿舟','阿琛','阿衡',
    '苏苏','七七','三三','九九','七七子','小橙子','小土豆','小樱桃','大白兔','小狐狸',
    '一念','二两','三分','四时','五味','六合','知否','未央','长安','姑苏',
    '听潮','观澜','拾光','寄远','怀橘','折柳','采薇','问道','寻梅','煮雪',
    '暖阳','微光','浅夏','深秋','长夏','短歌','慢行','速写','轻语','静听',
    '无忧','有喜','正好','刚刚好','小幸运','大吉祥','好时光','好心情','好天气','好日子',
];

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

function uniqueNick(PDO $pdo, $userT, array $parts, array $phrases)
{
    $chk = $pdo->prepare("SELECT id FROM `{$userT}` WHERE nickname=? LIMIT 1");
    for ($i = 0; $i < 80; $i++) {
        if (mt_rand(0, 100) < 55) {
            $nick = $phrases[array_rand($phrases)];
        } else {
            $len = mt_rand(2, 6);
            $nick = '';
            $n = count($parts);
            for ($j = 0; $j < $len; $j++) {
                $nick .= $parts[mt_rand(0, $n - 1)];
            }
        }
        $nick = preg_replace('/\d+/u', '', (string)$nick);
        $nick = preg_replace('/\s+/u', '', $nick);
        $len = mb_strlen($nick, 'UTF-8');
        if ($len < 2 || $len > 6) {
            continue;
        }
        $chk->execute([$nick]);
        if (!$chk->fetchColumn()) {
            return $nick;
        }
    }
    return '清风';
}

function botAvatar($seed)
{
    $styles = ['lorelei', 'notionists', 'adventurer', 'avataaars', 'open-peeps', 'personas', 'big-smile', 'fun-emoji'];
    $style = $styles[crc32($seed) % count($styles)];
    if ((crc32($seed) & 1) === 0) {
        return 'https://api.dicebear.com/9.x/' . $style . '/png?seed=' . rawurlencode($seed) . '&size=128';
    }
    return 'https://i.pravatar.cc/150?u=' . rawurlencode($seed);
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
        $nick = uniqueNick($pdo, $userT, $parts, $phrases);
        $avatar = botAvatar('bot' . $id . '_' . substr(md5($mobile), 0, 8));
        $salt = randSalt(6);
        $pwdPlain = randSalt(10);
        $password = md5(md5($pwdPlain) . $salt);

        $pdo->beginTransaction();
        $insUser->execute([
            $id, 0, $mobile, $nick, $password, $salt, '', $mobile, $avatar,
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
