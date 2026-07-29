<?php
foreach (glob(dirname(__DIR__) . '/public/888/partials/*.php') as $f) {
    $c = file_get_contents($f);
    $n = preg_match_all('/data-copy-html="1"[^>]*>[\s\S]{0,300}<\/div>\s*<\/div>/u', $c);
    if ($n) {
        echo basename($f) . " suspicious=$n\n";
    }
}
echo "scan_done\n";
