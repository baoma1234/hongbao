<?php
foreach (['en-PH', 'id-ID', 'vi-VN'] as $code) {
    $js = file_get_contents(dirname(__DIR__) . "/public/888/i18n/locales/{$code}.js");
    if (!preg_match('/FANSHUB_LOCALES\[[^\]]+\]=(\{.*\});/s', $js, $m)) {
        echo "$code PARSE_FAIL\n";
        continue;
    }
    $data = json_decode($m[1], true);
    $keys = ['uid_label', 'footer_line1', 'settle_title_low', 'marquee_text', 'leaderboard_title', 'uid_hint_approved'];
    echo "== $code ==\n";
    foreach ($keys as $k) {
        $v = $data[$k] ?? 'MISS';
        $flag = preg_match('/[\x{4e00}-\x{9fff}]/u', $v) ? 'ZH' : 'OK';
        echo "  $k|$flag|" . mb_substr(str_replace("\n", ' ', $v), 0, 50) . "\n";
    }
}

// bump asset again for cache bust
$index = dirname(__DIR__) . '/public/888/index.php';
$c = file_get_contents($index);
$c = preg_replace('/\$assetVer\s*=\s*[\'"][^\'"]+[\'"]/', "\$assetVer = '202607251950'", $c, 1);
file_put_contents($index, $c);
echo "assetVer=202607251950\n";

// confirm hook
$core = file_get_contents(dirname(__DIR__) . '/public/888/js/app-core.js');
echo 'hook=' . (strpos($core, 'home-i18n-refresh') !== false ? 'yes' : 'no') . "\n";
$boot = file_get_contents(dirname(__DIR__) . '/public/888/js/app-boot.js');
echo 'marquee_nonzh=' . (strpos($boot, '非中文：只用多语言文案') !== false ? 'yes' : 'no') . "\n";
