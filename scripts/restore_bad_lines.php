<?php
$live = file(dirname(__DIR__) . '/public/888/index.html');
$rec = file(dirname(__DIR__) . '/public/888/index_recovered.html');

// Build recovered index by normalized LHS of const/let/var/return assignments
function normLeft($line) {
    $t = trim($line);
    if (preg_match('/^((?:const|let|var)\s+[^=]{1,80}?)=/u', $t, $m)) {
        return 'A:' . preg_replace('/\s+/', ' ', trim($m[1]));
    }
    if (preg_match('/^(return\s+.{1,60}?)(\s*\|\||\s*;|\s*$)/u', $t, $m)) {
        return 'R:' . preg_replace('/\s+/', ' ', trim($m[1]));
    }
    if (preg_match('/^((?:if\s*\([^)]+\)\s*)?\w[\w.]*\.(?:textContent|innerHTML|className|value)\s*=\s*.{0,40}?)(\s*\|\||\s*;|\s*$)/u', $t, $m)) {
        return 'P:' . preg_replace('/\s+/', ' ', trim($m[1]));
    }
    return null;
}

$recMap = [];
foreach ($rec as $i => $line) {
    $k = normLeft($line);
    if ($k && !isset($recMap[$k])) {
        $recMap[$k] = ['line' => $line, 'no' => $i + 1];
    }
}

$fixed = 0;
$miss = 0;
foreach ($live as $i => $line) {
    $trim = rtrim($line);
    if ($trim === '' || str_starts_with(ltrim($trim), '//')) continue;

    $open = substr_count($trim, '(');
    $close = substr_count($trim, ')');
    $q = preg_match_all("/(?<!\\\\)'/", $trim);
    $looksBad = false;
    if ($open > $close) $looksBad = true;
    if ($q % 2 !== 0) $looksBad = true;
    if (preg_match('/\|\|\s*$/', $trim)) $looksBad = true;
    if (preg_match('/=\s*\([^;]*$/', $trim) && !preg_match('/\?\s*$/', $trim) && $open > $close) $looksBad = true;
    // ends without terminator and has unclosed call
    if ($looksBad) {
        $k = normLeft($line);
        if ($k && isset($recMap[$k]) && strlen(trim($recMap[$k]['line'])) > strlen($trim) + 1) {
            $rep = $recMap[$k]['line'];
            if (preg_match('/^(\s*)/', $line, $ind)) {
                $rep = $ind[1] . ltrim($rep);
                if (!str_ends_with($rep, "\n")) $rep .= "\n";
            }
            $live[$i] = $rep;
            $fixed++;
            echo "FIX L" . ($i + 1) . " from rec L{$recMap[$k]['no']}: " . substr(trim($rep), 0, 90) . "\n";
        } else {
            $miss++;
            if ($miss <= 30) {
                echo "MISS L" . ($i + 1) . " o=$open c=$close q=$q :: " . substr($trim, 0, 100) . "\n";
            }
        }
    }
}

file_put_contents(dirname(__DIR__) . '/public/888/index.html', implode('', $live));
echo "fixed=$fixed miss=$miss\n";

$h = file_get_contents(dirname(__DIR__) . '/public/888/index.html');
preg_match_all('#<script(?![^>]+src=)[^>]*>(.*?)</script>#si', $h, $m);
$s = '';
foreach ($m[1] as $b) {
    if (strlen($b) > strlen($s)) $s = $b;
}
$t = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fh_check.js';
file_put_contents($t, $s);
$out = [];
$code = 0;
exec('node --check ' . escapeshellarg($t) . ' 2>&1', $out, $code);
echo implode("\n", $out) . "\nexit=$code\n";
