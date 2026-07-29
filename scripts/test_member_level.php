<?php
define('APP_PATH', dirname(__DIR__) . '/application/');
require dirname(__DIR__) . '/thinkphp/base.php';
\think\App::initCommon();

try {
    $levels = \app\common\library\FansHubService::memberLevels();
    echo "memberLevels OK count=" . count($levels) . "\n";
    $stats = \app\common\library\FansHubService::memberLevelStats();
    echo "memberLevelStats OK count=" . count($stats) . "\n";
    $parsed = \app\common\library\FansHubService::parseMemberLevelsArray([
        ['level' => 1, 'name' => '普通', 'invite_reward' => 1],
        ['level' => 2, 'name' => '银牌', 'invite_reward' => 2],
    ]);
    echo "parseMemberLevelsArray OK keys=" . implode(',', array_keys($parsed)) . "\n";
} catch (Throwable $e) {
    echo "ERR: " . $e->getMessage() . "\n";
    exit(1);
}
