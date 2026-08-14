<?php
$root = dirname(__DIR__);
$cfg = include $root . '/application/extra/fanshub.php';
$hc = $cfg['h5_copy'] ?? [];
echo 'fanshub brand_name=' . ($hc['brand_name'] ?? 'MISS') . PHP_EOL;
$def = include $root . '/application/extra/fanshub_h5_copy.php';
echo 'defaults brand_name=' . ($def['brand_name'] ?? 'MISS') . PHP_EOL;

// how API exposes copy
require $root . '/thinkphp/base.php';
\think\App::initCommon();
$merged = \app\common\library\FansHubService::mergeH5CopyDefaults($hc);
echo 'merged brand_name=' . ($merged['brand_name'] ?? 'MISS') . PHP_EOL;
