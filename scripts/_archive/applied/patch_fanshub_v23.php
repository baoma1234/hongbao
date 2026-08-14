<?php
/**
 * 福利大厅 v23：开启会员等级、防刷、UID 校验
 * php scripts/patch_fanshub_v23.php
 */
$root = dirname(__DIR__);
define('APP_PATH', $root . '/application/');
require $root . '/thinkphp/base.php';
\think\App::initCommon();

$config = \think\Config::get('fanshub') ?: [];
if (!is_array($config)) {
    $config = [];
}

$secret = (string)($config['api_sign_secret'] ?? '');
if ($secret === '' || $secret === 'fanshub_dev_sign_key_change_me') {
    $secret = bin2hex(random_bytes(16));
}

$updates = [
    'member_level_enabled'        => true,
    'api_sign_enabled'            => true,
    'api_sign_secret'             => $secret,
    'api_sign_ttl'                => max(60, (int)($config['api_sign_ttl'] ?? 300)),
    'device_fp_limit_enabled'     => true,
    'device_fp_max_accounts'      => max(1, (int)($config['device_fp_max_accounts'] ?? 3)),
    'invite_ip_limit_enabled'     => true,
    'main_uid_verify_enabled'     => true,
    'main_uid_verify_local'       => true,
    'main_uid_verify_match_phone' => true,
];

foreach ($updates as $key => $value) {
    $config[$key] = $value;
}

if (!\app\common\library\FansHubService::saveFanshubConfig($config)) {
    fwrite(STDERR, "FAIL  save fanshub.php\n");
    exit(1);
}

echo "OK    member_level_enabled=true\n";
echo "OK    api_sign_enabled=true (secret=" . substr($secret, 0, 8) . "...)\n";
echo "OK    device_fp_limit_enabled=true (max={$config['device_fp_max_accounts']})\n";
echo "OK    invite_ip_limit_enabled=true\n";
echo "OK    main_uid_verify_enabled=true (local mode)\n";
echo "OK    main_uid_verify_match_phone=true\n";
echo "DONE  H5 会自动从 /api/fanshub/config 拉取签名密钥；主站独立时请关闭「本地会员库校验」并填外部 URL\n";
