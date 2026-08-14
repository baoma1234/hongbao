<?php
$root = dirname(__DIR__);
$c = include $root . '/application/extra/fanshub_h5_copy.php';
$html = '';
foreach (glob($root . '/public/888/partials/*.php') as $f) {
    $html .= file_get_contents($f);
}
preg_match_all('/data-copy(?:-placeholder|-aria|-title|-value)?="([^"]+)"/', $html, $m);
$keys = array_unique($m[1]);
$miss = [];
foreach ($keys as $k) {
    if (!isset($c[$k])) {
        $miss[] = $k;
    }
}
sort($miss);
file_put_contents($root . '/scripts/_missing_datacopy_keys.txt', implode("\n", $miss));
echo 'missing=' . count($miss) . "\n" . implode("\n", $miss) . "\n";
