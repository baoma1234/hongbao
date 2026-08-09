<?php
/**
 * 用 scripts/data/bot_names.txt 随机重分配机器人昵称（不重复）
 * 并下载真人照片头像到 /uploads/bot_avatars/
 *
 * php scripts/fix_bot_profiles.php
 * php scripts/fix_bot_profiles.php --limit=500
 * php scripts/fix_bot_profiles.php --names="C:/path/名字.txt"
 */
$root = dirname(__DIR__);
$limit = 5000;
$namesPath = $root . '/scripts/data/bot_names.txt';
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, (int)$m[1]);
    }
    if (preg_match('/^--names=(.+)$/', $arg, $m)) {
        $namesPath = trim($m[1], "\"'");
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

function loadBotNames($path)
{
    if (!is_file($path)) {
        throw new RuntimeException('names file not found: ' . $path);
    }
    $raw = file($path, FILE_IGNORE_NEW_LINES);
    $out = [];
    $seen = [];
    foreach ($raw ?: [] as $line) {
        $n = preg_replace('/\s+/u', '', trim((string)$line));
        $n = preg_replace('/\d+/u', '', $n);
        if ($n === '') {
            continue;
        }
        $len = mb_strlen($n, 'UTF-8');
        if ($len < 2 || $len > 8) {
            continue;
        }
        if (isset($seen[$n])) {
            continue;
        }
        $seen[$n] = true;
        $out[] = $n;
    }
    return $out;
}

/** 真人照片源池（稳定 CDN），打乱后一对一分配 */
function buildAvatarPool($need)
{
    $urls = [];
    for ($i = 0; $i < 100; $i++) {
        $urls[] = 'https://randomuser.me/api/portraits/men/' . $i . '.jpg';
        $urls[] = 'https://randomuser.me/api/portraits/women/' . $i . '.jpg';
    }
    for ($i = 0; $i < 75; $i++) {
        $urls[] = 'https://xsgames.co/randomusers/assets/avatars/male/' . $i . '.jpg';
        $urls[] = 'https://xsgames.co/randomusers/assets/avatars/female/' . $i . '.jpg';
    }
    for ($i = 1; $i <= 70; $i++) {
        $urls[] = 'https://i.pravatar.cc/300?img=' . $i;
    }
    $urls = array_values(array_unique($urls));
    shuffle($urls);
    // 不够则用 pravatar 唯一 seed 补齐
    $i = 0;
    while (count($urls) < $need) {
        $urls[] = 'https://i.pravatar.cc/300?u=hb_bot_' . $i . '_' . bin2hex(random_bytes(3));
        $i++;
    }
    return $urls;
}

function downloadAvatar($url, $destPath)
{
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 20,
            'follow_location' => 1,
            'header' => "User-Agent: Mozilla/5.0\r\nAccept: image/*\r\n",
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);
    $bin = @file_get_contents($url, false, $ctx);
    if ($bin === false || strlen($bin) < 800) {
        return false;
    }
    // 粗检：避免下到 HTML
    $head = substr($bin, 0, 32);
    if (stripos($head, '<!DOCTYPE') !== false || stripos($head, '<html') !== false) {
        return false;
    }
    return file_put_contents($destPath, $bin) !== false;
}

$names = loadBotNames($namesPath);
if (!$names) {
    throw new RuntimeException('empty names list');
}
echo "names_loaded=" . count($names) . " from {$namesPath}\n";

$rows = $pdo->query(
    "SELECT u.id, u.nickname, u.avatar, u.mobile
     FROM `{$userT}` u
     INNER JOIN `{$accT}` a ON a.user_id=u.id
     WHERE IFNULL(a.is_bot,0)=1
     ORDER BY u.id ASC
     LIMIT " . (int)$limit
)->fetchAll();
$botCount = count($rows);
echo "bots={$botCount}\n";
if ($botCount <= 0) {
    echo "done updated=0\n";
    exit(0);
}
if (count($names) < $botCount) {
    throw new RuntimeException("not enough unique names: need {$botCount}, have " . count($names));
}

// 全员重新随机抽名（不重复）
shuffle($names);
$assignNames = array_slice($names, 0, $botCount);

// 真实头像池
$avatarPool = buildAvatarPool($botCount);
$avatarDir = $root . '/public/uploads/bot_avatars';
if (!is_dir($avatarDir)) {
    mkdir($avatarDir, 0755, true);
}

$upd = $pdo->prepare("UPDATE `{$userT}` SET nickname=?, avatar=?, updatetime=? WHERE id=?");
$chkNick = $pdo->prepare("SELECT id FROM `{$userT}` WHERE nickname=? AND id<>? LIMIT 1");
$now = time();
$nOk = 0;
$nFailAvatar = 0;

for ($i = 0; $i < $botCount; $i++) {
    $r = $rows[$i];
    $uid = (int)$r['id'];
    $oldNick = (string)$r['nickname'];
    $nick = $assignNames[$i];

    // 若与真人撞名，往后换一个未用名
    $chkNick->execute([$nick, $uid]);
    if ($chkNick->fetchColumn()) {
        $swapped = false;
        for ($j = $botCount; $j < count($names); $j++) {
            $alt = $names[$j];
            $chkNick->execute([$alt, $uid]);
            if (!$chkNick->fetchColumn()) {
                $nick = $alt;
                $swapped = true;
                break;
            }
        }
        if (!$swapped) {
            $nick = $nick . '子';
            if (mb_strlen($nick, 'UTF-8') > 8) {
                $nick = mb_substr($nick, 0, 8, 'UTF-8');
            }
        }
    }

    $remote = $avatarPool[$i];
    $file = $avatarDir . '/b' . $uid . '.jpg';
    $okDl = downloadAvatar($remote, $file);
    if (!$okDl) {
        // 失败则换池内下一个源再试
        for ($t = 1; $t <= 5; $t++) {
            $altUrl = $avatarPool[($i + $t * 17) % count($avatarPool)];
            if (downloadAvatar($altUrl, $file)) {
                $okDl = true;
                break;
            }
        }
    }
    if ($okDl) {
        $avatar = '/uploads/bot_avatars/b' . $uid . '.jpg?v=' . $now;
    } else {
        $nFailAvatar++;
        // 兜底仍用真人 CDN，避免 dicebear 卡通脸
        $avatar = $remote;
    }

    $upd->execute([$nick, $avatar, $now, $uid]);
    $nOk++;
    if ($nOk <= 12 || $nOk % 50 === 0) {
        echo "OK #{$nOk} id={$uid} {$oldNick} => {$nick} avatar=" . ($okDl ? 'local' : 'cdn') . "\n";
    }
}

echo "done updated={$nOk} avatar_fail={$nFailAvatar}\n";
