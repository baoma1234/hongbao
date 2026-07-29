<?php
define('APP_PATH', dirname(__DIR__) . '/application/');
require dirname(__DIR__) . '/thinkphp/base.php';
\think\App::initCommon();

try {
    $cfg = \app\common\library\FansHubService::publicConfig();
    echo "publicConfig OK\n";
    $json = json_encode($cfg, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        echo "json_encode failed: " . json_last_error_msg() . "\n";
        print_r($cfg);
        exit(1);
    }
    echo $json . "\n";
    $lb = \app\common\library\FansHubService::inviteLeaderboard(5);
    echo "leaderboard OK count=" . count($lb) . "\n";
} catch (Throwable $e) {
    echo "ERR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
