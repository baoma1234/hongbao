<?php
$boot = file(dirname(__DIR__) . '/public/888/js/app-boot.js');
echo "=== settle 330-360 ===\n";
for ($i = 329; $i < 360; $i++) echo ($i + 1) . ':' . $boot[$i];
echo "=== marquee 1020-1070 ===\n";
for ($i = 1019; $i < 1070; $i++) echo ($i + 1) . ':' . $boot[$i];
$core = file(dirname(__DIR__) . '/public/888/js/app-core.js');
echo "=== updateDynamicCopy 557-620 ===\n";
for ($i = 556; $i < 620; $i++) echo ($i + 1) . ':' . $core[$i];
echo "en count=" . count(include dirname(__DIR__) . '/application/extra/i18n/en-PH.php') . "\n";
