<?php
define('APP_PATH', dirname(__DIR__) . '/application/');
require dirname(__DIR__) . '/thinkphp/base.php';
\think\App::initCommon();

function runCase($label, $start, $end)
{
    echo "=== {$label} ===\n";
    try {
        $stats = \app\common\library\FansHubService::dashboardStats($start, $end);
        echo "OK registered={$stats['registered']} secret_vip={$stats['secret_vip']}\n";
    } catch (Throwable $e) {
        echo "ERR: " . $e->getMessage() . "\n";
        if (method_exists($e, 'getData')) {
            echo "SQL: " . ($e->getData()['Database Status']['Error SQL'] ?? '') . "\n";
        }
        echo $e->getTraceAsString() . "\n";
        exit(1);
    }
}

runCase('no filter', 0, 0);
runCase('with filter', strtotime('2025-01-01'), strtotime('2026-12-31'));
