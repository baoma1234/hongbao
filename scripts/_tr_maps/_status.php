<?php
$dir = __DIR__;
foreach (['en','vi','id','ms','km'] as $lang) {
    $n = 0;
    $keys = [];
    foreach (['a','b','c','d'] as $p) {
        $f = "$dir/{$lang}_{$p}.php";
        if (!is_file($f)) continue;
        $part = include $f;
        if (is_array($part)) {
            $n += count($part);
            $keys = array_merge($keys, array_keys($part));
        }
    }
    echo "$lang parts_keys=$n unique=" . count(array_unique($keys)) . "\n";
}
