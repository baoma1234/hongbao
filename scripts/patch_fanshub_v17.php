<?php
/**
 * 福利大厅 v17：Universe Action 国际短信（UNAL/AboSEND）
 * php scripts/patch_fanshub_v17.php
 */
define('APP_PATH', dirname(__DIR__) . '/application/');
require dirname(__DIR__) . '/thinkphp/base.php';
\think\App::initCommon();

$path = APP_PATH . 'extra' . DS . 'fanshub.php';
$config = is_file($path) ? include $path : [];
if (!is_array($config)) {
    $config = [];
}

$defaults = [
    'sms_una_enabled'          => false,
    'sms_una_gateway'          => '',
    'sms_una_org_code'         => '',
    'sms_una_md5_key'          => '',
    'sms_una_content_template' => 'Your verification code is {code}',
    'sms_una_oa_number'        => '',
    'sms_una_notify_url'       => '',
    'sms_una_use_v2'           => true,
    'sms_una_timeout'          => 10,
];

$changed = false;
foreach ($defaults as $key => $val) {
    if (!array_key_exists($key, $config)) {
        $config[$key] = $val;
        $changed = true;
    }
}

if ($changed) {
    if (!\app\common\library\FansHubService::saveFanshubConfig($config)) {
        fwrite(STDERR, "save fanshub.php failed\n");
        exit(1);
    }
    echo "OK    fanshub.php una sms keys merged\n";
} else {
    echo "SKIP  fanshub.php already has v17 keys\n";
}

echo "OK    FansHubUnaSms ready — configure orgCode/MD5Key in admin\n";
