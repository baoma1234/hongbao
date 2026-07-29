<?php
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
define('APP_PATH', dirname(__DIR__) . '/application/');
require dirname(__DIR__) . '/thinkphp/base.php';
\think\App::initCommon();

$mobile = $argv[1] ?? '15555555555';
try {
    $data = \app\common\library\FansHubService::loginOrRegister($mobile, '123456', '', 'testfp');
    echo "OK\n";
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        echo "json_encode failed: " . json_last_error_msg() . "\n";
        print_r($data);
    } else {
        echo $json . "\n";
    }
} catch (Throwable $e) {
    echo "ERR: [" . $e->getMessage() . "]\n";
    echo get_class($e) . "\n";
}
