<?php
/**
 * Upload login CS float icon to OSS.
 * php scripts/upload_login_cs_icon.php
 */
$root = dirname(__DIR__);
$local = $root . '/public/uploads/brand/login-cs-float.png';
if (!is_file($local)) {
    fwrite(STDERR, "missing $local\n");
    exit(1);
}

// Parse .env [oss] without full ThinkPHP
$envFile = $root . '/.env';
$oss = [
    'enabled' => false,
    'access_key_id' => '',
    'access_key_secret' => '',
    'bucket' => '',
    'endpoint' => 'oss-cn-hongkong.aliyuncs.com',
    'cdn_domain' => '',
];
if (is_file($envFile)) {
    $section = '';
    foreach (file($envFile, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === ';' || $line[0] === '#') {
            continue;
        }
        if ($line[0] === '[' && substr($line, -1) === ']') {
            $section = strtolower(trim($line, '[]'));
            continue;
        }
        if ($section !== 'oss') {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        list($k, $v) = array_map('trim', explode('=', $line, 2));
        $v = trim($v, " \t\"'");
        $k = strtolower($k);
        if ($k === 'enabled') {
            $oss['enabled'] = in_array(strtolower($v), ['1', 'true', 'on', 'yes'], true);
        } elseif (isset($oss[$k]) || in_array($k, ['access_key_id', 'access_key_secret', 'bucket', 'endpoint', 'cdn_domain'], true)) {
            $oss[$k] = $v;
        }
    }
}

$objectKey = 'uploads/brand/login-cs-float.png';
$body = file_get_contents($local);
$contentType = 'image/png';
$date = gmdate('D, d M Y H:i:s \G\M\T');
$bucket = $oss['bucket'];
$endpoint = preg_replace('#^https?://#i', '', rtrim($oss['endpoint'], '/'));
$ak = $oss['access_key_id'];
$sk = $oss['access_key_secret'];

if (!$oss['enabled'] || $bucket === '' || $ak === '' || $sk === '') {
    echo "OSS disabled or incomplete; kept local only: /uploads/brand/login-cs-float.png\n";
    exit(0);
}

$resource = '/' . $bucket . '/' . $objectKey;
$stringToSign = "PUT\n\n{$contentType}\n{$date}\n{$resource}";
$signature = base64_encode(hash_hmac('sha1', $stringToSign, $sk, true));
$host = $bucket . '.' . $endpoint;
$url = 'https://' . $host . '/' . $objectKey;

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Date: ' . $date,
    'Content-Type: ' . $contentType,
    'Authorization: OSS ' . $ak . ':' . $signature,
    'Content-Length: ' . strlen($body),
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
$resp = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($code < 200 || $code >= 300) {
    fwrite(STDERR, "OSS upload fail http=$code err=$err resp=$resp\n");
    exit(1);
}

$cdn = trim((string)$oss['cdn_domain']);
if ($cdn !== '') {
    $cdn = rtrim(preg_match('#^https?://#i', $cdn) ? $cdn : ('https://' . $cdn), '/');
    // accelerate host without bucket prefix
    if (stripos($cdn, 'aliyuncs.com') !== false && stripos($cdn, $bucket . '.') === false) {
        $cdnHost = preg_replace('#^https?://#i', '', $cdn);
        $public = 'https://' . $bucket . '.' . $cdnHost . '/' . $objectKey;
    } else {
        $public = $cdn . '/' . $objectKey;
    }
} else {
    $public = $url;
}

echo "OK $public\n";
file_put_contents($root . '/scripts/_login_cs_icon_url.txt', $public);
