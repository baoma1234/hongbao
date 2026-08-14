<?php
/**
 * 福利大厅 v15：国际短信 HTTP 网关 + 浏览器语言检测 + 奖池重置
 * php scripts/patch_fanshub_v15.php
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
    'locale_auto_detect'   => true,
    'sms_http_enabled'     => false,
    'sms_http_url'         => '',
    'sms_http_method'      => 'POST',
    'sms_http_api_key'     => '',
    'sms_http_timeout'     => 10,
    'sms_http_template'    => '{"mobile":"{mobile}","code":"{code}","country":"{country}"}',
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
    echo "OK    fanshub.php v15 keys merged\n";
} else {
    echo "SKIP  fanshub.php already has v15 keys\n";
}

echo "OK    FansHubSms + locale_auto_detect ready\n";
