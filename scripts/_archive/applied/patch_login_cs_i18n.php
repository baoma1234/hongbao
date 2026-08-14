<?php
$root = dirname(__DIR__);
$zhPath = $root . '/application/extra/fanshub_h5_copy.php';
$zh = include $zhPath;
$zh['login_cs_label'] = '客服';
file_put_contents($zhPath, "<?php\nreturn " . var_export($zh, true) . ";\n");

$map = [
    'en-PH' => 'Support',
    'vi-VN' => 'Hỗ trợ',
    'id-ID' => 'Bantuan',
    'ms-MY' => 'Bantuan',
    'km-KH' => 'ជំនួយ',
];
foreach ($map as $code => $label) {
    $p = $root . '/application/extra/i18n/' . $code . '.php';
    ob_start();
    $d = include $p;
    ob_end_clean();
    if (!is_array($d)) {
        $d = [];
    }
    $d['login_cs_label'] = $label;
    file_put_contents(
        $p,
        "<?php\n\n/**\n * FansHub H5 copy — {$code}\n */\nreturn " . var_export($d, true) . ";\n"
    );
    echo "$code ok\n";
}

// Also patch fanshub.php h5_copy if present
$fh = include $root . '/application/extra/fanshub.php';
if (is_array($fh) && isset($fh['h5_copy']) && is_array($fh['h5_copy'])) {
    // Do not rewrite whole fanshub.php here; defaults merge covers it.
}
echo "zh login_cs_label set\n";
