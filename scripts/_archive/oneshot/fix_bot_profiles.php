<?php
/**
 * 刷新机器人资料
 * - 默认：名字来自 scripts/data/bot_names.txt（不重复）+ 多风格头像
 * - 仅换头像：php scripts/fix_bot_profiles.php --avatars-only
 *
 * 头像风格混搭（社交平台真实分布）：
 * 卡通 / 风景 / 真实生活照 / 人物 / 字母 / 影视海报感人物 / 电影片段感
 */
$root = dirname(__DIR__);
$limit = 5000;
$namesPath = $root . '/scripts/data/bot_names.txt';
$avatarsOnly = false;
foreach ($argv ?? [] as $arg) {
    if ($arg === '--avatars-only') {
        $avatarsOnly = true;
    }
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

function unsplashCrop($photoPath)
{
    return 'https://images.unsplash.com/' . $photoPath
        . '?auto=format&fit=crop&w=480&h=480&q=80&crop=faces,center';
}

function unsplashScene($photoPath)
{
    return 'https://images.unsplash.com/' . $photoPath
        . '?auto=format&fit=crop&w=480&h=480&q=80';
}

function findBoldFont()
{
    $cands = [
        'C:/Windows/Fonts/msyhbd.ttc',
        'C:/Windows/Fonts/msyh.ttc',
        'C:/Windows/Fonts/simhei.ttf',
        'C:/Windows/Fonts/arialbd.ttf',
        'C:/Windows/Fonts/arial.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/wqy/wqy-microhei.ttc',
    ];
    foreach ($cands as $f) {
        if (is_file($f)) {
            return $f;
        }
    }
    return null;
}

/** 字母/单字头像（本地 GD） */
function makeLetterAvatar($text, $destPath)
{
    $size = 480;
    $im = imagecreatetruecolor($size, $size);
    $palettes = [
        [0x1a, 0x73, 0xe8], [0xe5, 0x39, 0x35], [0x43, 0xa0, 0x47], [0xfb, 0x8c, 0x00],
        [0x8e, 0x24, 0xaa], [0x00, 0x89, 0x7b], [0x5d, 0x40, 0x37], [0x37, 0x47, 0x4f],
        [0xc2, 0x18, 0x5b], [0x39, 0x49, 0xab], [0xf4, 0x51, 0x1e], [0x00, 0x79, 0x6b],
        [0x61, 0x61, 0x61], [0xd8, 0x1b, 0x60], [0x15, 0x65, 0xc0], [0x2e, 0x7d, 0x32],
    ];
    $bg = $palettes[random_int(0, count($palettes) - 1)];
    $fill = imagecolorallocate($im, $bg[0], $bg[1], $bg[2]);
    imagefilledrectangle($im, 0, 0, $size, $size, $fill);
    // 轻微渐变感
    for ($y = 0; $y < $size; $y++) {
        $a = (int)(18 * $y / $size);
        $c = imagecolorallocatealpha($im, 0, 0, 0, max(0, 127 - $a));
        imageline($im, 0, $y, $size, $y, $c);
    }
    $fg = imagecolorallocate($im, 255, 255, 255);
    $font = findBoldFont();
    $label = mb_substr((string)$text, 0, 2, 'UTF-8');
    if ($label === '') {
        $letters = 'ABCDEFGHJKLMNPRSTUVWXYZ阿北川东风光海江金乐林梦南青山水天星月云';
        $label = mb_substr($letters, random_int(0, mb_strlen($letters, 'UTF-8') - 1), 1, 'UTF-8');
    }
    if ($font) {
        $fs = mb_strlen($label, 'UTF-8') >= 2 ? 150 : 220;
        $bbox = imagettfbbox($fs, 0, $font, $label);
        $tw = abs($bbox[2] - $bbox[0]);
        $th = abs($bbox[7] - $bbox[1]);
        $x = (int)(($size - $tw) / 2 - $bbox[0]);
        $y = (int)(($size + $th) / 2);
        imagettftext($im, $fs, 0, $x, $y, $fg, $font, $label);
    } else {
        $cx = (int)($size / 2 - 20);
        $cy = (int)($size / 2 - 30);
        imagestring($im, 5, $cx, $cy, substr($label, 0, 2), $fg);
    }
    $ok = imagejpeg($im, $destPath, 88);
    imagedestroy($im);
    return (bool)$ok;
}

/** 简易「海报风」：色块 + 标题字（避免版权海报） */
function makePosterStyleAvatar($title, $destPath)
{
    $size = 480;
    $im = imagecreatetruecolor($size, $size);
    $themes = [
        [[20, 20, 40], [180, 40, 60], [240, 200, 80]],
        [[10, 30, 50], [40, 120, 200], [220, 240, 255]],
        [[30, 10, 40], [140, 40, 180], [255, 120, 200]],
        [[15, 40, 30], [40, 160, 90], [220, 255, 180]],
        [[40, 20, 10], [200, 90, 30], [255, 220, 120]],
        [[5, 5, 5], [80, 80, 80], [240, 240, 240]],
        [[60, 10, 20], [200, 30, 50], [255, 180, 60]],
    ];
    $t = $themes[random_int(0, count($themes) - 1)];
    for ($y = 0; $y < $size; $y++) {
        $r = (int)($t[0][0] + ($t[1][0] - $t[0][0]) * $y / $size);
        $g = (int)($t[0][1] + ($t[1][1] - $t[0][1]) * $y / $size);
        $b = (int)($t[0][2] + ($t[1][2] - $t[0][2]) * $y / $size);
        $c = imagecolorallocate($im, $r, $g, $b);
        imageline($im, 0, $y, $size, $y, $c);
    }
    // 侧光条 / 人物剪影块
    $acc = imagecolorallocate($im, $t[2][0], $t[2][1], $t[2][2]);
    $dark = imagecolorallocate($im, 0, 0, 0);
    imagefilledellipse($im, (int)($size * 0.62), (int)($size * 0.55), 220, 320, $dark);
    imagefilledrectangle($im, 0, (int)($size * 0.78), $size, $size, imagecolorallocatealpha($im, 0, 0, 0, 40));
    imageline($im, 24, 28, 180, 28, $acc);
    $font = findBoldFont();
    $label = mb_substr((string)$title, 0, 4, 'UTF-8');
    if ($label === '') {
        $pool = ['夜行', '风云', '追光', '破晓', '暗涌', '星河', '烈火', '迷雾', '孤岛', '回声'];
        $label = $pool[random_int(0, count($pool) - 1)];
    }
    $fg = imagecolorallocate($im, 255, 255, 255);
    if ($font) {
        imagettftext($im, 42, 0, 28, $size - 48, $fg, $font, $label);
        imagettftext($im, 16, 0, 30, 56, $acc, $font, 'FEATURE');
    }
    $ok = imagejpeg($im, $destPath, 88);
    imagedestroy($im);
    return (bool)$ok;
}

/**
 * 多风格头像规格池：按目标比例生成，再打散，避免某一类挤爆
 * 每项: ['cat'=>..., 'src'=>'url|letter:X|poster:X']
 */
function buildMixedAvatarSpecs($need)
{
    $cartoonStyles = [
        'avataaars', 'lorelei', 'notionists', 'adventurer', 'pixel-art',
        'big-ears', 'fun-emoji', 'bottts-neutral', 'thumbs', 'shapes',
        'croodles', 'micah', 'open-peeps', 'personas',
    ];
    $letterPool = array_merge(
        str_split('ABCDEFGHJKLMNPRSTUVWXYZ'),
        ['阿', '北', '川', '东', '风', '光', '海', '江', '金', '乐', '林', '梦', '南', '青', '山', '水', '天', '星', '月', '云', '小', '大', '安', '平', '明', '华', '强', '伟']
    );
    $posterTitles = ['夜行', '风云', '追光', '破晓', '暗涌', '星河', '烈火', '迷雾', '孤岛', '回声', '锋芒', '余烬', '潮汐', '幻城', '终章', '序曲', '暗战', '黎明', '裂变', '归途'];

    // 目标占比（总和 1.0）
    $ratios = [
        'people' => 0.18,   // 真实人物
        'cartoon' => 0.14,  // 卡通
        'scene' => 0.14,    // 风景
        'life' => 0.14,     // 真实生活照
        'letter' => 0.10,   // 字母/单字
        'poster' => 0.15,   // 影视海报感人物
        'movie' => 0.15,    // 电影片段感
    ];

    $counts = [];
    $assigned = 0;
    foreach ($ratios as $cat => $r) {
        $counts[$cat] = (int)floor($need * $r);
        $assigned += $counts[$cat];
    }
    // 余数补到人物
    $counts['people'] += max(0, $need - $assigned);

    $buckets = [];

    // —— 人物：pravatar + randomuser（比 unsplash 批量更稳）——
    $peopleSrc = [];
    for ($i = 1; $i <= 70; $i++) {
        $peopleSrc[] = 'https://i.pravatar.cc/480?img=' . $i;
    }
    for ($i = 0; $i < 90; $i++) {
        $g = ($i % 2 === 0) ? 'men' : 'women';
        $peopleSrc[] = 'https://randomuser.me/api/portraits/' . $g . '/' . ($i % 99) . '.jpg';
    }
    for ($i = 0; $i < 40; $i++) {
        $peopleSrc[] = 'https://loremflickr.com/480/480/portrait,face?lock=' . (1000 + $i);
    }
    shuffle($peopleSrc);
    $buckets['people'] = [];
    for ($i = 0; $i < $counts['people']; $i++) {
        $buckets['people'][] = ['cat' => 'people', 'src' => $peopleSrc[$i % count($peopleSrc)] . (strpos($peopleSrc[$i % count($peopleSrc)], '?') !== false ? '&' : '?') . 'r=' . $i];
    }

    // —— 卡通 ——
    $buckets['cartoon'] = [];
    for ($i = 0; $i < $counts['cartoon']; $i++) {
        $style = $cartoonStyles[$i % count($cartoonStyles)];
        $seed = 'mix' . $i . '_' . bin2hex(random_bytes(2));
        $buckets['cartoon'][] = [
            'cat' => 'cartoon',
            'src' => 'https://api.dicebear.com/7.x/' . $style . '/png?seed=' . rawurlencode($seed) . '&size=480',
        ];
    }

    // —— 风景（picsum 为主，更快更稳）——
    $buckets['scene'] = [];
    for ($i = 0; $i < $counts['scene']; $i++) {
        if ($i % 4 === 0) {
            $tag = ['nature', 'mountain', 'beach', 'forest', 'sunset', 'lake'][$i % 6];
            $src = 'https://loremflickr.com/480/480/' . $tag . '?lock=' . (2000 + $i);
        } else {
            $src = 'https://picsum.photos/seed/scene' . ($i * 17 + 3) . '/480/480';
        }
        $buckets['scene'][] = ['cat' => 'scene', 'src' => $src];
    }

    // —— 真实生活照：美食/宠物/咖啡/街拍 ——
    $lifeTags = ['food', 'coffee', 'dog', 'cat', 'street', 'travel', 'restaurant', 'bike'];
    $buckets['life'] = [];
    for ($i = 0; $i < $counts['life']; $i++) {
        if ($i % 3 === 0) {
            $src = 'https://picsum.photos/seed/life' . ($i * 13 + 9) . '/480/480';
        } else {
            $tag = $lifeTags[$i % count($lifeTags)];
            $src = 'https://loremflickr.com/480/480/' . $tag . '?lock=' . (3000 + $i);
        }
        $buckets['life'][] = ['cat' => 'life', 'src' => $src];
    }

    // —— 字母 ——
    $buckets['letter'] = [];
    for ($i = 0; $i < $counts['letter']; $i++) {
        $ch = $letterPool[$i % count($letterPool)];
        $buckets['letter'][] = ['cat' => 'letter', 'src' => 'letter:' . $ch];
    }

    // —— 海报人物：戏剧人像 + 本地海报风 ——
    $buckets['poster'] = [];
    for ($i = 0; $i < $counts['poster']; $i++) {
        if ($i % 3 === 0) {
            $buckets['poster'][] = [
                'cat' => 'poster',
                'src' => 'poster:' . $posterTitles[$i % count($posterTitles)],
            ];
        } elseif ($i % 3 === 1) {
            $buckets['poster'][] = [
                'cat' => 'poster',
                'src' => 'https://i.pravatar.cc/480?u=poster' . $i,
            ];
        } else {
            $buckets['poster'][] = [
                'cat' => 'poster',
                'src' => 'https://loremflickr.com/480/480/fashion,model,dramatic?lock=' . (4000 + $i),
            ];
        }
    }

    // —— 电影片段感：影院/霓虹/夜景/公路 ——
    $movieTags = ['cinema', 'neon', 'night,city', 'movie', 'car,night', 'rain,street', 'bridge,night', 'skyline'];
    $buckets['movie'] = [];
    for ($i = 0; $i < $counts['movie']; $i++) {
        if ($i % 3 === 0) {
            $tag = $movieTags[$i % count($movieTags)];
            $src = 'https://loremflickr.com/480/480/' . $tag . '?lock=' . (5000 + $i);
        } else {
            $src = 'https://picsum.photos/seed/movie' . ($i * 19 + 5) . '/480/480';
        }
        $buckets['movie'][] = ['cat' => 'movie', 'src' => $src];
    }

    // 交错合并再轻度打散（保持各类均匀）
    $cats = array_keys($ratios);
    $specs = [];
    $idx = array_fill_keys($cats, 0);
    while (count($specs) < $need) {
        $progress = false;
        foreach ($cats as $cat) {
            if ($idx[$cat] < count($buckets[$cat])) {
                $specs[] = $buckets[$cat][$idx[$cat]];
                $idx[$cat]++;
                $progress = true;
                if (count($specs) >= $need) {
                    break 2;
                }
            }
        }
        if (!$progress) {
            break;
        }
    }
    // 局部 shuffle：每 7 个一组内打乱，避免相邻全是同类，又不太破坏比例
    for ($i = 0; $i + 7 <= count($specs); $i += 7) {
        $chunk = array_slice($specs, $i, 7);
        shuffle($chunk);
        for ($j = 0; $j < 7; $j++) {
            $specs[$i + $j] = $chunk[$j];
        }
    }
    return $specs;
}

function downloadAvatar($url, $destPath)
{
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 25,
            'follow_location' => 1,
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\nAccept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8\r\n",
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
    $head = substr($bin, 0, 64);
    if (stripos($head, '<!DOCTYPE') !== false || stripos($head, '<html') !== false) {
        return false;
    }
    $okMagic = (substr($bin, 0, 3) === "\xFF\xD8\xFF")
        || (substr($bin, 0, 8) === "\x89PNG\r\n\x1a\n")
        || (substr($bin, 0, 4) === 'RIFF');
    if (!$okMagic) {
        return false;
    }
    // PNG/WebP 转存为 jpeg，统一扩展名
    if (substr($bin, 0, 8) === "\x89PNG\r\n\x1a\n" || substr($bin, 0, 4) === 'RIFF') {
        $im = @imagecreatefromstring($bin);
        if ($im === false) {
            return file_put_contents($destPath, $bin) !== false;
        }
        $ok = imagejpeg($im, $destPath, 88);
        imagedestroy($im);
        return (bool)$ok;
    }
    return file_put_contents($destPath, $bin) !== false;
}

function materializeAvatar($spec, $destPath)
{
    $src = (string)$spec['src'];
    if (strpos($src, 'letter:') === 0) {
        $text = preg_replace('/[#:].*$/u', '', substr($src, 7));
        return makeLetterAvatar($text, $destPath);
    }
    if (strpos($src, 'poster:') === 0) {
        $title = preg_replace('/[#:].*$/u', '', substr($src, 7));
        return makePosterStyleAvatar($title, $destPath);
    }
    return downloadAvatar($src, $destPath);
}

$rows = $pdo->query(
    "SELECT u.id, u.nickname, u.avatar, u.mobile
     FROM `{$userT}` u
     INNER JOIN `{$accT}` a ON a.user_id=u.id
     WHERE IFNULL(a.is_bot,0)=1
     ORDER BY u.id ASC
     LIMIT " . (int)$limit
)->fetchAll();
$botCount = count($rows);
echo "bots={$botCount} avatars_only=" . ($avatarsOnly ? '1' : '0') . "\n";
if ($botCount <= 0) {
    echo "done updated=0\n";
    exit(0);
}

$assignNames = [];
if (!$avatarsOnly) {
    $names = loadBotNames($namesPath);
    echo "names_loaded=" . count($names) . " from {$namesPath}\n";
    if (count($names) < $botCount) {
        throw new RuntimeException("not enough unique names: need {$botCount}, have " . count($names));
    }
    shuffle($names);
    $assignNames = array_slice($names, 0, $botCount);
}

$avatarSpecs = buildMixedAvatarSpecs($botCount + 40);
$avatarDir = $root . '/public/uploads/bot_avatars';
if (!is_dir($avatarDir)) {
    mkdir($avatarDir, 0755, true);
}

$updBoth = $pdo->prepare("UPDATE `{$userT}` SET nickname=?, avatar=?, updatetime=? WHERE id=?");
$updAvatar = $pdo->prepare("UPDATE `{$userT}` SET avatar=?, updatetime=? WHERE id=?");
$chkNick = $pdo->prepare("SELECT id FROM `{$userT}` WHERE nickname=? AND id<>? LIMIT 1");
$now = time();
$nOk = 0;
$nFailAvatar = 0;
$catCount = [];

for ($i = 0; $i < $botCount; $i++) {
    $r = $rows[$i];
    $uid = (int)$r['id'];
    $oldNick = (string)$r['nickname'];
    $nick = $avatarsOnly ? $oldNick : $assignNames[$i];

    if (!$avatarsOnly) {
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
                $nick = mb_substr($nick . '子', 0, 8, 'UTF-8');
            }
        }
    }

    $spec = $avatarSpecs[$i % count($avatarSpecs)];
    $cat = $spec['cat'];
    $file = $avatarDir . '/b' . $uid . '.jpg';
    @unlink($file);
    $okDl = materializeAvatar($spec, $file);
    if (!$okDl) {
        // 先同类别重试，再跨类
        for ($t = 1; $t <= 14; $t++) {
            $alt = $avatarSpecs[($i + $t * 11) % count($avatarSpecs)];
            if ($t <= 6 && $alt['cat'] !== $cat) {
                continue;
            }
            if (materializeAvatar($alt, $file)) {
                $okDl = true;
                $spec = $alt;
                $cat = $alt['cat'];
                break;
            }
        }
    }
    // 最终兜底：本地字母 / 海报风（保住“五花八门”不塌到全卡通）
    if (!$okDl) {
        if ($i % 2 === 0) {
            $okDl = makeLetterAvatar(mb_substr($oldNick !== '' ? $oldNick : 'U', 0, 1, 'UTF-8'), $file);
            $cat = 'letter';
        } else {
            $okDl = makePosterStyleAvatar(mb_substr($oldNick !== '' ? $oldNick : '影', 0, 2, 'UTF-8'), $file);
            $cat = 'poster';
        }
    }

    if ($okDl) {
        $avatar = '/uploads/bot_avatars/b' . $uid . '.jpg?v=' . $now;
    } else {
        $nFailAvatar++;
        $avatar = '/assets/img/avatar.png';
    }
    $catCount[$cat] = ($catCount[$cat] ?? 0) + 1;

    if ($avatarsOnly) {
        $updAvatar->execute([$avatar, $now, $uid]);
    } else {
        $updBoth->execute([$nick, $avatar, $now, $uid]);
    }
    $nOk++;
    if ($nOk <= 15 || $nOk % 50 === 0) {
        $tag = $avatarsOnly ? $oldNick : ($oldNick . ' => ' . $nick);
        echo "OK #{$nOk} id={$uid} {$tag} cat={$cat} avatar=" . ($okDl ? 'local' : 'fail') . "\n";
    }
}

ksort($catCount);
$parts = [];
foreach ($catCount as $k => $v) {
    $parts[] = "{$k}={$v}";
}
echo "cats " . implode(' ', $parts) . "\n";
echo "done updated={$nOk} avatar_fail={$nFailAvatar}\n";
