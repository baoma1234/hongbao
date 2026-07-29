<?php
function unmoji($garbled) {
    $bytes = @iconv('UTF-8', 'GBK//IGNORE', $garbled);
    if ($bytes === false) return null;
    if (!mb_check_encoding($bytes, 'UTF-8')) {
        // maybe incomplete
        return ['bytes' => bin2hex($bytes), 'text' => null];
    }
    return ['bytes' => bin2hex($bytes), 'text' => $bytes];
}

$html = file_get_contents(dirname(__DIR__) . '/public/888/index.html');
preg_match_all('/class="tab-ico">([^<]+)</u', $html, $m);
foreach ($m[1] as $g) {
    $r = unmoji($g);
    echo "IN=$g OUT=" . ($r['text'] ?? 'null') . " hex=" . ($r['bytes'] ?? '') . "\n";
}

echo "--- verify known ---\n";
foreach (['🏠','⚡','🏦','💬','👑','👤','✉️','📩'] as $e) {
    $moj = iconv('GBK', 'UTF-8//IGNORE', $e); // treat utf8 bytes as gbk
    $back = unmoji($moj);
    echo "$e moj=$moj back=" . ($back['text'] ?? '?') . "\n";
}
