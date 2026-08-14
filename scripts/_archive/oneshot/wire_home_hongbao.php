<?php
$root = dirname(__DIR__);
$home = file_get_contents($root . '/public/888/partials/tab-home.php');
if (preg_match_all('/<span class="asset-label"[^>]*>.*?<\/span>/us', $home, $m)) {
    foreach ($m[0] as $line) {
        echo $line . "\n";
    }
}
$c = include $root . '/application/extra/fanshub_h5_copy.php';
foreach ($c as $k => $v) {
    if (stripos($k, 'hongbao') !== false || stripos($k, 'asset_') === 0) {
        echo "$k => $v\n";
    }
}

// wire hongbao label if needed
if (strpos($home, '<span class="asset-label">红宝</span>') !== false) {
    $home = str_replace(
        '<span class="asset-label">红宝</span>',
        '<span class="asset-label" data-copy="swap_asset_hongbao">红宝</span>',
        $home
    );
    file_put_contents($root . '/public/888/partials/tab-home.php', $home);
    echo "wired_hongbao_label=1\n";
} else {
    echo "wired_hongbao_label=0\n";
}
