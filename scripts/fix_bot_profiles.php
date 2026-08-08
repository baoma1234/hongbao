<?php
/**
 * 刷新已有机器人：去数字昵称（2～6 字）+ 互不相同头像
 * php scripts/fix_bot_profiles.php
 * php scripts/fix_bot_profiles.php --limit=500
 */
$root = dirname(__DIR__);
$limit = 5000;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, (int)$m[1]);
    }
}

$env = parse_ini_file($root . '/.env', true);
$d = $env['database'];
$prefix = $d['prefix'] ?? 'fa_';
$userT = $prefix . 'user';
$accT = $prefix . 'fans_account';

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $d['hostname'] ?? '127.0.0.1',
        (int)($d['hostport'] ?? 3306),
        $d['database'] ?? ''
    ),
    $d['username'] ?? 'root',
    $d['password'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
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

function makeNick(PDO $pdo, $userT, $uid, array $parts, array $phrases, array &$used)
{
    $chk = $pdo->prepare("SELECT id FROM `{$userT}` WHERE nickname=? AND id<>? LIMIT 1");
    for ($i = 0; $i < 100; $i++) {
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
        if (isset($used[$nick])) {
            continue;
        }
        $chk->execute([$nick, $uid]);
        if ($chk->fetchColumn()) {
            continue;
        }
        $used[$nick] = true;
        return $nick;
    }
    $fallback = '清风' . $parts[array_rand($parts)];
    $fallback = mb_substr(preg_replace('/\d+/u', '', $fallback), 0, 6, 'UTF-8');
    $used[$fallback] = true;
    return $fallback;
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

$rows = $pdo->query(
    "SELECT u.id, u.nickname, u.avatar, u.mobile
     FROM `{$userT}` u
     INNER JOIN `{$accT}` a ON a.user_id=u.id
     WHERE IFNULL(a.is_bot,0)=1
     ORDER BY u.id ASC
     LIMIT " . (int)$limit
)->fetchAll();

$used = [];
foreach ($rows as $r) {
    $n = preg_replace('/\d+/u', '', (string)$r['nickname']);
    if ($n !== '' && !isset($used[$n]) && mb_strlen($n, 'UTF-8') >= 2 && mb_strlen($n, 'UTF-8') <= 6) {
        // 先占住仍合格的旧昵称，减少无谓改名
        $used[$n] = true;
    }
}

$upd = $pdo->prepare("UPDATE `{$userT}` SET nickname=?, avatar=?, updatetime=? WHERE id=?");
$now = time();
$nOk = 0;
foreach ($rows as $r) {
    $uid = (int)$r['id'];
    $oldNick = (string)$r['nickname'];
    $clean = preg_replace('/\d+/u', '', $oldNick);
    $clean = preg_replace('/\s+/u', '', $clean);
    $len = mb_strlen($clean, 'UTF-8');
    $needNick = ($clean !== $oldNick) || $len < 2 || $len > 6 || (isset($used[$clean]) && $clean !== $oldNick);
    // 重复昵称也要换
    $dup = false;
    if (!$needNick) {
        $st = $pdo->prepare("SELECT id FROM `{$userT}` WHERE nickname=? AND id<>? LIMIT 1");
        $st->execute([$oldNick, $uid]);
        $dup = (bool)$st->fetchColumn();
    }
    if ($needNick || $dup || preg_match('/\d/', $oldNick)) {
        // 释放旧名占用后重新分配
        if (isset($used[$clean]) && $clean === $oldNick) {
            unset($used[$clean]);
        }
        $nick = makeNick($pdo, $userT, $uid, $parts, $phrases, $used);
    } else {
        $nick = $oldNick;
        $used[$nick] = true;
    }
    $avatar = botAvatar('bot' . $uid . '_' . substr(md5((string)$r['mobile'] . $uid), 0, 10));
    $upd->execute([$nick, $avatar, $now, $uid]);
    $nOk++;
    if ($nOk <= 8 || $nOk % 50 === 0) {
        echo "OK #{$nOk} id={$uid} {$oldNick} => {$nick}\n";
    }
}
echo "done updated={$nOk}\n";
