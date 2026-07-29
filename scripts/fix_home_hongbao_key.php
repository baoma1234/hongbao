<?php
$root = dirname(__DIR__);
$path = $root . '/public/888/partials/tab-home.php';
$html = file_get_contents($path);
$html = str_replace(
    'data-copy="swap_asset_hongbao"',
    'data-copy="asset_hongbao_label"',
    $html
);
file_put_contents($path, $html);
echo "fixed\n";
