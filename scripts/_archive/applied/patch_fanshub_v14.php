<?php
/**
 * 福利大厅 v14：服务端奖池同步 + H5 默认语言配置
 * php scripts/patch_fanshub_v14.php
 */
define('APP_PATH', dirname(__DIR__) . '/application/');
require dirname(__DIR__) . '/thinkphp/base.php';
\think\App::initCommon();

$path = APP_PATH . 'extra' . DS . 'fanshub.php';
$config = is_file($path) ? include $path : [];
if (!is_array($config)) {
    $config = [];
}

$changed = false;
if (!isset($config['default_locale'])) {
    $config['default_locale'] = 'zh-CN';
    $changed = true;
}
if (!isset($config['jackpot_server_sync'])) {
    $config['jackpot_server_sync'] = true;
    $changed = true;
}

if ($changed) {
    if (!\app\common\library\FansHubService::saveFanshubConfig($config)) {
        fwrite(STDERR, "save fanshub.php failed\n");
        exit(1);
    }
    echo "OK    fanshub.php default_locale + jackpot_server_sync\n";
} else {
    echo "SKIP  fanshub.php already has v14 keys\n";
}

\app\common\library\FansHubService::resetJackpotCache();
echo "OK    jackpot cache initialized\n";
