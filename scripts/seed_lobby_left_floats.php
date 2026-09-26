<?php
/**
 * 首页左侧三浮标：彩金白嫖→频道群组 / 福利视频→群80 / 海外圈内事→社区
 * php scripts/seed_lobby_left_floats.php
 */
$root = dirname(__DIR__);
$env = parse_ini_file($root . '/.env', true);
$d = $env['database'];
$pdo = new PDO(
    "mysql:host={$d['hostname']};dbname={$d['database']};charset=utf8mb4",
    $d['username'],
    $d['password'],
    [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4', PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$prefix = $d['prefix'] ?? 'fa_';
$table = $prefix . 'fans_lobby_floats';
$now = time();

$pdo->exec("CREATE TABLE IF NOT EXISTS `{$table}` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(64) NOT NULL DEFAULT '' COMMENT '备注名',
  `image` varchar(255) NOT NULL DEFAULT '' COMMENT '悬浮图标',
  `side` varchar(8) NOT NULL DEFAULT 'right' COMMENT 'left|right',
  `link_type` varchar(32) NOT NULL DEFAULT 'internal' COMMENT 'none|internal|external',
  `link_url` varchar(255) NOT NULL DEFAULT '' COMMENT '站内 pages/... 或站外 http(s)',
  `weigh` int(11) NOT NULL DEFAULT 0,
  `status` varchar(16) NOT NULL DEFAULT 'normal' COMMENT 'normal|hidden',
  `createtime` int(10) unsigned NOT NULL DEFAULT 0,
  `updatetime` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_status_side_weigh` (`status`,`side`,`weigh`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='大厅左右浮标'");

function floatMakeTransparent($src, $dest)
{
    if (!function_exists('imagecreatefrompng')) {
        if ($src !== $dest) {
            copy($src, $dest);
        }
        return false;
    }
    $im = @imagecreatefrompng($src);
    if (!$im) {
        if ($src !== $dest) {
            copy($src, $dest);
        }
        return false;
    }
    $w = imagesx($im);
    $h = imagesy($im);
    $out = imagecreatetruecolor($w, $h);
    imagesavealpha($out, true);
    imagealphablending($out, false);
    $transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
    imagefilledrectangle($out, 0, 0, $w, $h, $transparent);
    imagealphablending($out, true);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $rgba = imagecolorat($im, $x, $y);
            $a = ($rgba & 0x7F000000) >> 24;
            $r = ($rgba >> 16) & 0xFF;
            $g = ($rgba >> 8) & 0xFF;
            $b = $rgba & 0xFF;
            if ($r < 18 && $g < 18 && $b < 18) {
                continue;
            }
            $col = imagecolorallocatealpha($out, $r, $g, $b, $a);
            imagesetpixel($out, $x, $y, $col);
        }
    }
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagepng($out, $dest);
    imagedestroy($im);
    imagedestroy($out);
    return true;
}

$assetsDir = $root . '/uni-999/src/static/home/lobby';
$publicDir = $root . '/public/999/static/home/lobby';
if (!is_dir($publicDir)) {
    @mkdir($publicDir, 0755, true);
}

$rows = [
    [
        'title'     => '彩金白嫖',
        'image'     => 'home/lobby/float-caijin.png',
        'file'      => 'float-caijin.png',
        'side'      => 'left',
        'link_type' => 'internal',
        'link_url'  => 'pages/notice/notice?cat=ads',
        'weigh'     => 300,
    ],
    [
        'title'     => '福利视频',
        'image'     => 'home/lobby/float-video.png',
        'file'      => 'float-video.png',
        'side'      => 'left',
        'link_type' => 'internal',
        'link_url'  => 'pages/community/community?sub=channel',
        'weigh'     => 200,
    ],
    [
        'title'     => '红宝·海外圈内事',
        'image'     => 'home/lobby/float-haiwai.png',
        'file'      => 'float-haiwai.png',
        'side'      => 'left',
        'link_type' => 'internal',
        'link_url'  => 'pages/notice/notice?cat=rules',
        'weigh'     => 100,
    ],
];

foreach ($rows as $r) {
    $src = $assetsDir . '/' . $r['file'];
    if (!is_file($src)) {
        fwrite(STDERR, "MISSING {$src}\n");
        continue;
    }
    floatMakeTransparent($src, $src);
    copy($src, $publicDir . '/' . $r['file']);

    $exists = $pdo->prepare("SELECT id FROM `{$table}` WHERE title=? AND side='left' LIMIT 1");
    $exists->execute([$r['title']]);
    $id = (int)$exists->fetchColumn();
    if ($id > 0) {
        $pdo->prepare("UPDATE `{$table}` SET image=?, link_type=?, link_url=?, weigh=?, status='normal', updatetime=? WHERE id=?")
            ->execute([$r['image'], $r['link_type'], $r['link_url'], $r['weigh'], $now, $id]);
        echo "UPD #{$id} {$r['title']}\n";
    } else {
        $pdo->prepare("INSERT INTO `{$table}` (title,image,side,link_type,link_url,weigh,status,createtime,updatetime) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$r['title'], $r['image'], $r['side'], $r['link_type'], $r['link_url'], $r['weigh'], 'normal', $now, $now]);
        echo "INS {$r['title']} #" . $pdo->lastInsertId() . "\n";
    }
}

$cacheDir = $root . '/runtime/cache';
if (is_dir($cacheDir)) {
    foreach (glob($cacheDir . '/*') as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
}
echo "DB OK\n";
