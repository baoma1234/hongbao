<?php
/**
 * Fix JS string literals where corrupted ￥/UTF-8 ate the closing quote.
 * Pattern: || '....?;  or || '....?)  or || '....?,
 */
$path = dirname(__DIR__) . '/public/888/index.html';
$src = file_get_contents($path);
$before = $src;
$n = 0;

// 1) || 'text?;  → || 'text';
$src = preg_replace("/\|\| '((?:\\\\'|[^'\n])*?)\?;/", "|| '$1';", $src, -1, $c);
$n += $c;
echo "fix1 || '...?; => $c\n";

// 2) || 'text?) → || 'text')
$src = preg_replace("/\|\| '((?:\\\\'|[^'\n])*?)\?\)/", "|| '$1')", $src, -1, $c);
$n += $c;
echo "fix2 || '...?) => $c\n";

// 3) || 'text?,  → || 'text',
$src = preg_replace("/\|\| '((?:\\\\'|[^'\n])*?)\?,/", "|| '$1',", $src, -1, $c);
$n += $c;
echo "fix3 || '...?, => $c\n";

// 4) Inside concat: + 'text?); → + 'text');
$src = preg_replace("/\+ '((?:\\\\'|[^'\n])*?)\?\);/", "+ '$1');", $src, -1, $c);
$n += $c;
echo "fix4 + '...?); => $c\n";

// 5) Inside concat: + 'text?) → + 'text')
$src = preg_replace("/\+ '((?:\\\\'|[^'\n])*?)\?\)/", "+ '$1')", $src, -1, $c);
$n += $c;
echo "fix5 + '...?) => $c\n";

// 6) fc(...) || 'text?); already covered; also toastMsg || 'text?);
$src = preg_replace("/\|\| '((?:\\\\'|[^'\n])*?)\?\);/", "|| '$1');", $src, -1, $c);
$n += $c;
echo "fix6 || '...?); => $c\n";

// 7) warn/log strings: 'text?, e) → 'text', e)
$src = preg_replace("/'((?:\\\\'|[^'\n])*?)\?, e\)/", "'$1', e)", $src, -1, $c);
$n += $c;
echo "fix7 warn => $c\n";

// 8) Remaining: 'text?; at end of assignment without ||
$src = preg_replace("/= '((?:\\\\'|[^'\n])*?)\?;/", "= '$1';", $src, -1, $c);
$n += $c;
echo "fix8 = '...?; => $c\n";

file_put_contents($path, $src);
echo "bytes " . strlen($before) . " -> " . strlen($src) . " ops=$n\n";

// Re-check odd quotes in largest script
preg_match_all('#<script(?![^>]+src=)[^>]*>(.*?)</script>#si', $src, $blocks);
$script = '';
foreach ($blocks[1] as $b) {
    if (strlen($b) > strlen($script)) $script = $b;
}
$lines = explode("\n", $script);
$odds = 0;
foreach ($lines as $i => $line) {
    $trim = ltrim($line);
    if ($trim === '' || str_starts_with($trim, '//')) continue;
    $q = preg_match_all("/(?<!\\\\)'/", $line);
    if ($q % 2 !== 0) {
        $odds++;
        if ($odds <= 25) {
            echo "ODD " . ($i + 1) . " q=$q " . substr($line, 0, 120) . "\n";
        }
    }
}
echo "remaining_odd=$odds\n";
