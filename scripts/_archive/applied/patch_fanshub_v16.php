<?php
/**
 * 福利大厅 v16：大狗短信（中国）+ 多语言文案批量编辑
 * php scripts/patch_fanshub_v16.php
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
    'sms_dagou_enabled'  => false,
    'sms_dagou_gateway'  => '',
    'sms_dagou_uname'    => '',
    'sms_dagou_apikey'   => '',
    'sms_dagou_timeout'  => 10,
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
    echo "OK    fanshub.php dagou sms keys merged\n";
} else {
    echo "SKIP  fanshub.php already has v16 keys\n";
}

\app\common\library\FansHubService::regenerateI18nBundle();
echo "OK    locales.bundle.js regenerated\n";
