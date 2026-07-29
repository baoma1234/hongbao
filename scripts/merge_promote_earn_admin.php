<?php
$root = dirname(__DIR__);
$zh = include $root . '/application/extra/fanshub_h5_copy.php';
$keys = [
    'promote_earn_title', 'promote_earn_live', 'promote_earn_col_uid', 'promote_earn_col_type',
    'promote_earn_col_detail', 'promote_earn_col_amount', 'promote_earn_type_share', 'promote_earn_type_group',
    'promote_earn_detail_share_n', 'promote_earn_detail_groups_n', 'promote_earn_detail_multi',
    'promote_earn_detail_exposure', 'promote_earn_refreshed',
];

$fanshubPath = $root . '/application/extra/fanshub.php';
$cfg = include $fanshubPath;
if (!is_array($cfg)) {
    fwrite(STDERR, "fanshub.php invalid\n");
    exit(1);
}
if (!isset($cfg['h5_copy']) || !is_array($cfg['h5_copy'])) {
    $cfg['h5_copy'] = [];
}
$n = 0;
foreach ($keys as $k) {
    if (!isset($cfg['h5_copy'][$k]) || $cfg['h5_copy'][$k] === '') {
        if (isset($zh[$k])) {
            $cfg['h5_copy'][$k] = $zh[$k];
            $n++;
        }
    }
}
// rewrite fanshub.php carefully — only if we can detect return array
$src = file_get_contents($fanshubPath);
if (preg_match('/\$h5_copy_end/', $src)) {
    // unlikely
}
// Use var_export of whole config is risky if file has comments/logic.
// Safer: patch h5_copy section via array merge write of just ensuring keys exist in runtime — 
// many sites use fanshub.php as plain return array.
if (preg_match('/^<\?php\s*return\s/s', ltrim($src))) {
    file_put_contents($fanshubPath, "<?php\nreturn " . var_export($cfg, true) . ";\n");
    echo "fanshub_merged=$n\n";
} else {
    echo "fanshub_skip_complex_file added_would=$n\n";
}

require $root . '/scripts/generate_i18n_locales.php';
echo "DONE\n";
