<?php
$zh = include dirname(__DIR__) . '/application/extra/fanshub_h5_copy.php';
$en = include dirname(__DIR__) . '/application/extra/i18n/en-PH.php';
echo 'zh=' . count($zh) . "\n";
echo 'en=' . count($en) . "\n";
echo 'zh_pair=' . ($zh['swap_title_pair'] ?? 'MISS') . "\n";
echo 'en_pair=' . ($en['swap_title_pair'] ?? 'MISS') . "\n";
echo 'en_avail=' . ($en['swap_avail_balance'] ?? 'MISS') . "\n";
