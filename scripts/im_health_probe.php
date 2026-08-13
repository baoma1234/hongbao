<?php
/**
 * IM 深度健康探针（CLI）
 * Usage: php scripts/im_health_probe.php [--json]
 * Exit: 0=ok, 1=degraded/fail
 */
$imRoot = dirname(__DIR__) . '/im-server';
require $imRoot . '/vendor/autoload.php';

$cfg = require $imRoot . '/config/app.php';
Im\Support\Db::init($cfg['db']);
Im\Support\RedisClient::init($cfg['redis']);

$report = Im\Support\HealthProbe::run($cfg);
$asJson = in_array('--json', $argv ?? [], true);

if ($asJson) {
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit($report['ok'] ? 0 : 1);
}

echo $report['ok'] ? "OK im health\n" : "FAIL im health\n";
foreach ($report['checks'] as $name => $c) {
    $flag = !empty($c['ok']) ? 'PASS' : 'FAIL';
    $extra = '';
    if (isset($c['alive'])) {
        $extra .= ' alive=' . implode(',', $c['alive'] ?: []);
    }
    if (isset($c['age_sec'])) {
        $extra .= ' age=' . var_export($c['age_sec'], true) . 's';
    }
    if (isset($c['total'])) {
        $extra .= ' total=' . $c['total'];
    }
    if (isset($c['count'])) {
        $extra .= ' count=' . $c['count'];
    }
    if (isset($c['len'])) {
        $extra .= ' len=' . $c['len'];
    }
    if (isset($c['port'])) {
        $extra .= ' port=' . $c['port'];
    }
    if (!empty($c['error'])) {
        $extra .= ' err=' . $c['error'];
    }
    echo sprintf("  [%s] %-16s%s\n", $flag, $name, $extra);
}
if (!empty($report['metrics'])) {
    echo "metrics: " . json_encode($report['metrics'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
exit($report['ok'] ? 0 : 1);
