<?php
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
define('APP_PATH', dirname(__DIR__) . '/application/');
require dirname(__DIR__) . '/thinkphp/base.php';
\think\App::initCommon();
try {
    print_r(\app\common\library\FansHubService::listComments(1, 30));
} catch (Throwable $e) {
    echo $e->getMessage() . "\n";
}
