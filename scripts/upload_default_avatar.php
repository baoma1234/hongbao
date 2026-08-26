<?php
/**
 * Upload new default avatar to OSS and print URL.
 * Usage: php scripts/upload_default_avatar.php
 */
define('APP_PATH', __DIR__ . '/../application/');
define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);

$src = $argv[1] ?? '';
if ($src === '' || !is_file($src)) {
    fwrite(STDERR, "Usage: php scripts/upload_default_avatar.php <local.png>\n");
    exit(1);
}

// Load .env into Env for Config
require ROOT_PATH . 'thinkphp/base.php';
// ThinkPHP may need full bootstrap - simpler: parse .env and call OssService manually

$envFile = ROOT_PATH . '.env';
$ini = parse_ini_file($envFile, true);
$oss = $ini['oss'] ?? [];
if (empty($oss['enabled']) || empty($oss['access_key_id']) || empty($oss['bucket'])) {
    fwrite(STDERR, "OSS not configured in .env\n");
    exit(1);
}

$md5 = md5_file($src);
$ymd = date('Ymd');
$objectKey = 'uploads/' . $ymd . '/' . $md5 . '.png';
$relPath = '/' . $objectKey;

// dual-write local
$localDir = ROOT_PATH . 'public/uploads/' . $ymd;
if (!is_dir($localDir)) {
    mkdir($localDir, 0755, true);
}
$localFile = $localDir . '/' . $md5 . '.png';
if (!copy($src, $localFile)) {
    fwrite(STDERR, "Failed to copy local file\n");
    exit(1);
}
echo "Local: {$localFile}\n";
echo "Path: {$relPath}\n";

$bucket = trim($oss['bucket']);
$endpoint = preg_replace('#^https?://#i', '', trim($oss['endpoint'] ?? 'oss-cn-hongkong.aliyuncs.com'));
$endpoint = rtrim($endpoint, '/');
$ak = (string)$oss['access_key_id'];
$sk = (string)$oss['access_key_secret'];
$cdn = trim((string)($oss['cdn_domain'] ?? ''));

$body = file_get_contents($localFile);
$contentType = 'image/png';
$date = gmdate('D, d M Y H:i:s \G\M\T');
$resource = '/' . $bucket . '/' . $objectKey;
$stringToSign = "PUT\n\n{$contentType}\n{$date}\n{$resource}";
$signature = base64_encode(hash_hmac('sha1', $stringToSign, $sk, true));

$url = 'https://' . $bucket . '.' . $endpoint . '/' . $objectKey;
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => 'PUT',
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Date: ' . $date,
        'Content-Type: ' . $contentType,
        'Authorization: OSS ' . $ak . ':' . $signature,
        'Content-Length: ' . strlen($body),
    ],
    CURLOPT_TIMEOUT => 60,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
]);
$resp = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($code < 200 || $code >= 300) {
    fwrite(STDERR, "OSS upload failed HTTP {$code}: {$err} {$resp}\n");
    exit(1);
}

// public URL (prefer accelerate CDN style used in project)
$publicBase = '';
if ($cdn !== '') {
    if (preg_match('#^https?://#i', $cdn)) {
        $publicBase = rtrim($cdn, '/');
    } else {
        $cdnHost = rtrim($cdn, '/');
        if (stripos($cdnHost, $bucket . '.') !== 0 && stripos($cdnHost, 'aliyuncs.com') !== false) {
            $publicBase = 'https://' . $bucket . '.' . $cdnHost;
        } else {
            $publicBase = 'https://' . $cdnHost;
        }
    }
} else {
    $publicBase = 'https://' . $bucket . '.' . $endpoint;
}

$full = $publicBase . '/' . $objectKey;
// Prefer accelerate domain if that's what project uses
$accel = 'https://' . $bucket . '.oss-accelerate.aliyuncs.com/' . $objectKey;
echo "OSS_OK code={$code}\n";
echo "FULL={$full}\n";
echo "ACCEL={$accel}\n";
echo "REL={$relPath}\n";
