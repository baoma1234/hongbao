<?php
/**
 * 把本地 video_cover jpg 推到 OSS（读 .env）
 */
$root = dirname(__DIR__);
$e = parse_ini_file($root . '/.env', true);
$oss = $e['oss'] ?? [];
if (empty($oss['enabled']) || empty($oss['access_key_id'])) {
    fwrite(STDERR, "oss disabled\n");
    exit(1);
}
$d = $e['database'];
$pdo = new PDO(
    'mysql:host=' . $d['hostname'] . ';dbname=' . $d['database'] . ';charset=utf8mb4',
    $d['username'],
    $d['password']
);
$p = $d['prefix'] ?? 'fa_';
$bucket = trim($oss['bucket']);
$endpoint = preg_replace('#^https?://#i', '', trim($oss['endpoint'] ?? 'oss-cn-hongkong.aliyuncs.com'));
$ak = $oss['access_key_id'];
$sk = $oss['access_key_secret'];

$rows = $pdo->query("SELECT id, video_cover FROM {$p}fans_notice WHERE video_cover LIKE '/uploads/%vc_%'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    $rel = $row['video_cover'];
    $local = $root . '/public' . $rel;
    if (!is_file($local)) {
        echo "skip missing {$row['id']} $rel\n";
        continue;
    }
    $key = ltrim($rel, '/');
    $body = file_get_contents($local);
    $ctype = 'image/jpeg';
    $date = gmdate('D, d M Y H:i:s \G\M\T');
    $resource = '/' . $bucket . '/' . $key;
    $stringToSign = "PUT\n\n{$ctype}\n{$date}\n{$resource}";
    $sig = base64_encode(hash_hmac('sha1', $stringToSign, $sk, true));
    $host = $bucket . '.' . $endpoint;
    $url = 'https://' . $host . '/' . $key;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'Date: ' . $date,
            'Content-Type: ' . $ctype,
            'Authorization: OSS ' . $ak . ':' . $sig,
            'Content-Length: ' . strlen($body),
            'Host: ' . $host,
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "id={$row['id']} http=$code key=$key\n";
}
