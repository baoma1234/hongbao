<?php
/**
 * 从 OSS 下载缺封面的公告视频，ffmpeg 截首帧，写回 video_cover（并尽量双写 OSS）。
 * Usage: php scripts/backfill_notice_video_covers.php [ffmpeg.exe]
 */
$root = dirname(__DIR__);

$e = parse_ini_file($root . '/.env', true);
$d = $e['database'];
$pdo = new PDO(
    'mysql:host=' . $d['hostname'] . ';dbname=' . $d['database'] . ';charset=utf8mb4',
    $d['username'],
    $d['password']
);
$p = $d['prefix'] ?? 'fa_';

$oss = $e['oss'] ?? [];
$bucket = trim((string)($oss['bucket'] ?? '888jhdhifhbchashjdl'));
$cdn = trim((string)($oss['cdn_domain'] ?? 'oss-accelerate.aliyuncs.com'));
$cdn = preg_replace('#^https?://#i', '', $cdn);
if ($bucket && stripos($cdn, $bucket . '.') !== 0 && stripos($cdn, 'aliyuncs.com') !== false) {
    $ossBase = 'https://' . $bucket . '.' . $cdn;
} elseif ($cdn !== '') {
    $ossBase = (preg_match('#^https?://#i', (string)($oss['cdn_domain'] ?? '')) ? rtrim($oss['cdn_domain'], '/') : ('https://' . $cdn));
} else {
    $ossBase = 'https://888jhdhifhbchashjdl.oss-accelerate.aliyuncs.com';
}
$ossBase = rtrim($ossBase, '/');
echo "ossBase=$ossBase\n";

$ffmpeg = $argv[1] ?? '';
if ($ffmpeg === '' || !is_file($ffmpeg)) {
    $cand = $root . '/tools/ffmpeg-npm/node_modules/@ffmpeg-installer/win32-x64/ffmpeg.exe';
    if (is_file($cand)) $ffmpeg = $cand;
}
if ($ffmpeg === '' || !is_file($ffmpeg)) {
    fwrite(STDERR, "ffmpeg not found\n");
    exit(1);
}

$forceIds = array_map('intval', array_slice($argv, 2)); // optional force ids
$sql = "SELECT id, video, images, video_cover FROM {$p}fans_notice WHERE video IS NOT NULL AND video <> ''";
if ($forceIds) {
    $sql .= ' AND id IN (' . implode(',', $forceIds) . ')';
} else {
    $sql .= " AND (video_cover IS NULL OR video_cover='')";
}
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$upd = $pdo->prepare("UPDATE {$p}fans_notice SET video_cover=? WHERE id=?");

$tmpDir = $root . '/runtime/video_cover_tmp';
if (!is_dir($tmpDir)) mkdir($tmpDir, 0755, true);

$ok = 0; $fail = 0;
foreach ($rows as $row) {
    $id = (int)$row['id'];
    $video = trim((string)$row['video']);
    $rel = '/' . ltrim(str_replace('\\', '/', $video), '/');
    $local = $root . '/public' . $rel;
    $work = $local;
    $downloaded = false;

    if (!is_file($local) || filesize($local) < 64) {
        $url = $ossBase . $rel;
        $work = $tmpDir . '/v_' . $id . '_' . basename($rel);
        echo "DL id=$id $url\n";
        $ctx = stream_context_create(['http' => ['timeout' => 120], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $bin = @file_get_contents($url, false, $ctx);
        if ($bin === false || strlen($bin) < 64) {
            $imgs = json_decode($row['images'] ?: '[]', true);
            if (is_array($imgs) && !empty($imgs[0])) {
                $upd->execute([trim((string)$imgs[0]), $id]);
                echo "FALLBACK_IMG id=$id\n";
                $ok++;
                continue;
            }
            echo "DL_FAIL id=$id\n";
            $fail++;
            continue;
        }
        file_put_contents($work, $bin);
        $downloaded = true;
    }

    $ymd = date('Ymd');
    $dir = $root . '/public/uploads/' . $ymd;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = 'vc_' . $id . '_' . bin2hex(random_bytes(6)) . '.jpg';
    $outAbs = $dir . '/' . $name;
    $outRel = '/uploads/' . $ymd . '/' . $name;

    $cmd = escapeshellarg($ffmpeg) . ' -y -ss 0.15 -i ' . escapeshellarg($work)
        . ' -frames:v 1 -q:v 3 ' . escapeshellarg($outAbs) . ' 2>&1';
    exec($cmd, $out, $code);
    if (!is_file($outAbs) || filesize($outAbs) < 32) {
        $cmd = escapeshellarg($ffmpeg) . ' -y -i ' . escapeshellarg($work)
            . ' -frames:v 1 -q:v 3 ' . escapeshellarg($outAbs) . ' 2>&1';
        exec($cmd, $out, $code);
    }
    if ($downloaded && is_file($work)) @unlink($work);

    if (!is_file($outAbs) || filesize($outAbs) < 32) {
        $imgs = json_decode($row['images'] ?: '[]', true);
        if (is_array($imgs) && !empty($imgs[0])) {
            $upd->execute([trim((string)$imgs[0]), $id]);
            echo "FALLBACK_IMG id=$id after ffmpeg fail\n";
            $ok++;
            continue;
        }
        echo "FAIL id=$id\n";
        $fail++;
        continue;
    }
    $upd->execute([$outRel, $id]);
    echo "OK id=$id cover=$outRel size=" . filesize($outAbs) . "\n";

    // 尽量双写 OSS，其它服务器 cdnurl 才能读到
    if (!empty($oss['enabled']) && !empty($oss['access_key_id']) && !empty($oss['access_key_secret']) && $bucket !== '') {
        $endpoint = preg_replace('#^https?://#i', '', trim((string)($oss['endpoint'] ?? 'oss-cn-hongkong.aliyuncs.com')));
        $key = ltrim($outRel, '/');
        $body = file_get_contents($outAbs);
        $ctype = 'image/jpeg';
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $resource = '/' . $bucket . '/' . $key;
        $stringToSign = "PUT\n\n{$ctype}\n{$date}\n{$resource}";
        $sig = base64_encode(hash_hmac('sha1', $stringToSign, $oss['access_key_secret'], true));
        $host = $bucket . '.' . $endpoint;
        $putUrl = 'https://' . $host . '/' . $key;
        $ch = curl_init($putUrl);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'Date: ' . $date,
                'Content-Type: ' . $ctype,
                'Authorization: OSS ' . $oss['access_key_id'] . ':' . $sig,
                'Content-Length: ' . strlen($body),
                'Host: ' . $host,
            ],
        ]);
        curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        echo "  oss_put http=$http\n";
    }
    $ok++;
}
echo "done ok=$ok fail=$fail\n";
