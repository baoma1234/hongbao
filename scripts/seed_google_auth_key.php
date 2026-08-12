<?php
/**
 * 生成并写入 fanshub 全局谷歌验证器密钥（开启登录替代）
 * php scripts/seed_google_auth_key.php
 */
$root = dirname(__DIR__);
require $root . '/application/common/library/GoogleAuthenticator.php';

$path = $root . '/application/extra/fanshub.php';
if (!is_file($path)) {
    fwrite(STDERR, "missing {$path}\n");
    exit(1);
}

$data = include $path;
if (!is_array($data)) {
    $data = [];
}

$ga = new PHPGangsta_GoogleAuthenticator();
$secret = $ga->createSecret(16);
$code = $ga->getCode($secret);
$issuer = isset($data['google_auth_issuer']) && trim((string)$data['google_auth_issuer']) !== ''
    ? trim((string)$data['google_auth_issuer'])
    : 'FansHub';

$data['google_auth_login_enabled'] = true;
$data['google_auth_secret'] = $secret;
$data['google_auth_issuer'] = $issuer;

$export = "<?php\n\nreturn " . var_export($data, true) . ";\n";
if (file_put_contents($path, $export) === false) {
    fwrite(STDERR, "write failed: {$path}\n");
    exit(1);
}

$otpauth = 'otpauth://totp/login?secret=' . $secret . '&issuer=' . rawurlencode($issuer);
$qr = 'https://api.qrserver.com/v1/create-qr-code/?data=' . rawurlencode($otpauth) . '&size=200x200&ecc=M';

echo "OK enabled=1\n";
echo "secret={$secret}\n";
echo "code={$code}\n";
echo "issuer={$issuer}\n";
echo "qr={$qr}\n";
