<?php
$p = dirname(__DIR__) . '/public/888/index.php';
$s = file_get_contents($p);
$s = preg_replace('/\$assetVer\s*=\s*\'[^\']+\'/', "\$assetVer = '202607251520'", $s, 1);
file_put_contents($p, $s);
echo "bumped\n";
