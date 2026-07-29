<?php
$key = '9eT8zVu3z1ubzUPxkpFlDuZpX6A1q42';
$target = '6df47d40052c55e487092859d1c81d28';
$targetNo = null; // find e4fa

$variants = [
    'doc' => 'playername=fhdx888&pages=1&pageLength=10&sKey=' . $key,
    'lower_pl' => 'playername=fhdx888&pages=1&pagelength=10&sKey=' . $key,
    'skey_lower' => 'playername=fhdx888&pages=1&pageLength=10&skey=' . $key,
    'key_suffix' => 'playername=fhdx888&pages=1&pageLength=10&key=' . $key,
    'no_pages' => 'playername=fhdx888&sKey=' . $key,
];
foreach ($variants as $label => $s) {
    $md5 = strtolower(md5($s));
    echo "$label => $md5" . ($md5 === $target ? ' MATCH!' : '') . PHP_EOL;
}

// brute: try if key missing last char etc
for ($i = 0; $i < strlen($key); $i++) {
    $k2 = substr($key, 0, $i) . substr($key, $i + 1);
    $s = 'playername=fhdx888&pages=1&pageLength=10&sKey=' . $k2;
    if (strtolower(md5($s)) === $target) {
        echo "KEY_WITHOUT_CHAR_$i => $k2\n";
    }
}
