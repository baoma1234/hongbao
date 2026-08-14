<?php
$html = file_get_contents(dirname(__DIR__) . '/public/888/index.html');
$n = preg_match_all('/="[^"]*\?>/', $html, $m);
echo "broken=$n\n";
foreach ($m[0] as $x) {
    echo substr($x, 0, 100) . "\n";
}
// also check header exists
echo (str_contains($html, 'id="localeSelect"') ? "localeSelect ok\n" : "localeSelect MISSING\n");
echo (str_contains($html, 'id="skinSelect"') ? "skinSelect ok\n" : "skinSelect MISSING\n");
echo (str_contains($html, 'aria-label="顶部工具栏"') ? "topbar aria ok\n" : "topbar aria BAD\n");
