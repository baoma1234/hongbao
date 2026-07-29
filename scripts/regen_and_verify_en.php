<?php
$path = dirname(__DIR__) . '/application/extra/i18n/en-PH.php';
$en = include $path;
echo 'count=' . count($en) . "\n";
echo 'uid_label=' . ($en['uid_label'] ?? 'MISS') . "\n";
echo 'footer_line1=' . ($en['footer_line1'] ?? 'MISS') . "\n";
echo 'marquee_text_len=' . strlen($en['marquee_text'] ?? '') . "\n";

// Re-run generate merge logic manually
$zh = include dirname(__DIR__) . '/application/extra/fanshub_h5_copy.php';
$merged = array_merge($zh, $en);
echo 'merged_uid=' . $merged['uid_label'] . "\n";
echo 'merged_footer=' . mb_substr($merged['footer_line1'], 0, 50) . "\n";

// Check if en-PH.js was generated from stale cache - regenerate now
require dirname(__DIR__) . '/scripts/generate_i18n_locales.php';
