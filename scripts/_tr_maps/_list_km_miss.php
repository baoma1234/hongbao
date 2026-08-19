<?php
$dir = __DIR__;
$en = [];
foreach (['a','b','c','d'] as $p) {
    $en = array_merge($en, include "$dir/en_{$p}.php");
}
$kmA = include "$dir/km_a.php";
$miss = [];
foreach ($en as $k => $v) {
    if (!isset($kmA[$k])) $miss[] = $k;
}
echo count($miss) . " missing\n";
file_put_contents("$dir/_km_miss_keys.txt", implode("\n", $miss));
file_put_contents("$dir/_km_miss_en.json", json_encode(array_intersect_key($en, array_flip($miss)), JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
echo "wrote miss files\n";
