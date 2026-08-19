<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
$root = dirname(__DIR__);
define('APP_PATH', $root . '/application/');
define('ROOT_PATH', $root . DIRECTORY_SEPARATOR);
define('RUNTIME_PATH', $root . '/runtime/');
define('EXTEND_PATH', $root . '/extend/');
define('VENDOR_PATH', $root . '/vendor/');
define('CONF_PATH', APP_PATH);
require $root . '/thinkphp/base.php';
\think\App::initCommon();

$ref = new ReflectionClass(\app\common\library\FansHubFission::class);
$m = $ref->getMethod('splitPoolCents');
$m->setAccessible(true);
$pool = 100000; // ¥1000.00
$parts100 = $m->invoke(null, $pool, 100);
$parts3 = $m->invoke(null, $pool, 3);
echo 'sum100=' . array_sum($parts100) . ' n=' . count($parts100) . ' avg_cents=' . round(array_sum($parts100) / 100) . "\n";
echo 'sum3=' . array_sum($parts3) . ' n=' . count($parts3) . ' avg_cents=' . round(array_sum($parts3) / 3) . "\n";
echo 'first3_of_100_sum=' . array_sum(array_slice($parts100, 0, 3)) . "\n";
echo (array_sum($parts100) === $pool && count($parts100) === 100 ? "PASS: split by 100 keeps pool exact\n" : "FAIL\n");
echo (array_sum(array_slice($parts100, 0, 3)) < array_sum($parts3) ? "PASS: 3 real shares under 100-way split get less than 3-way split\n" : "WARN: amounts similar by chance\n");
