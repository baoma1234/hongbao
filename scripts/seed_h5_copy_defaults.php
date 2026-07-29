<?php

define('APP_PATH', dirname(__DIR__) . '/application/');
require dirname(__DIR__) . '/thinkphp/base.php';
\think\App::initCommon();

$path = APP_PATH . 'extra' . DS . 'fanshub.php';
$config = is_file($path) ? include $path : [];
if (!is_array($config)) {
    $config = [];
}
$saved = isset($config['h5_copy']) && is_array($config['h5_copy']) ? $config['h5_copy'] : [];
$config['h5_copy'] = \app\common\library\FansHubService::mergeH5CopyDefaults($saved);
if (!\app\common\library\FansHubService::saveFanshubConfig($config)) {
    fwrite(STDERR, "save fanshub.php failed\n");
    exit(1);
}
if (!\app\common\library\FansHubService::exportH5CopyDefaultsJs()) {
    fwrite(STDERR, "export copy.defaults.js failed\n");
    exit(1);
}
echo 'merged_h5_copy=' . count($config['h5_copy']) . PHP_EOL;
echo 'exported=public/888|fanshub|fanshubtest/copy.defaults.js' . PHP_EOL;
