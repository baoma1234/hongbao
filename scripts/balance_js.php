<?php
$path = dirname(__DIR__) . '/public/888/index.html';
$h = file_get_contents($path);
preg_match_all('#<script(?![^>]+src=)[^>]*>(.*?)</script>#si', $h, $m);
$best = '';
foreach ($m[1] as $b) {
    if (strlen($b) > strlen($best)) $best = $b;
}
$lines = preg_split('/\r?\n/', $best);
$stack = [];
$inS = $inD = $inT = $inLC = $inBC = false;
$esc = false;
for ($li = 0; $li < count($lines); $li++) {
    $line = $lines[$li];
    $len = strlen($line);
    for ($i = 0; $i < $len; $i++) {
        $c = $line[$i];
        $n = $i + 1 < $len ? $line[$i + 1] : '';
        if ($inLC) continue;
        if ($inBC) {
            if ($c === '*' && $n === '/') { $inBC = false; $i++; }
            continue;
        }
        if ($inS) {
            if (!$esc && $c === '\\') { $esc = true; continue; }
            if (!$esc && $c === "'") $inS = false;
            $esc = false;
            continue;
        }
        if ($inD) {
            if (!$esc && $c === '\\') { $esc = true; continue; }
            if (!$esc && $c === '"') $inD = false;
            $esc = false;
            continue;
        }
        if ($inT) {
            if (!$esc && $c === '\\') { $esc = true; continue; }
            if (!$esc && $c === '`') $inT = false;
            $esc = false;
            continue;
        }
        if ($c === '/' && $n === '/') { $inLC = true; continue; }
        if ($c === '/' && $n === '*') { $inBC = true; $i++; continue; }
        if ($c === "'") { $inS = true; continue; }
        if ($c === '"') { $inD = true; continue; }
        if ($c === '`') { $inT = true; continue; }
        if ($c === '(' || $c === '{' || $c === '[') {
            $stack[] = [$c, $li + 1, $i + 1, substr($line, max(0, $i - 20), 60)];
        } elseif ($c === ')' || $c === '}' || $c === ']') {
            $want = $c === ')' ? '(' : ($c === '}' ? '{' : '[');
            if (!$stack) {
                echo "extra $c at script L" . ($li + 1) . "\n";
                exit(1);
            }
            $top = array_pop($stack);
            if ($top[0] !== $want) {
                echo "mismatch got $c want close {$top[0]} opened at L{$top[1]}: {$top[3]}\n";
                echo "at script L" . ($li + 1) . ": $line\n";
                exit(1);
            }
        }
    }
    $inLC = false;
}
echo "unclosed " . count($stack) . "\n";
foreach (array_slice($stack, -15) as $s) {
    echo "  {$s[0]} at L{$s[1]} :: {$s[3]}\n";
}
echo "total script lines: " . count($lines) . "\n";
echo "inS=$inS inD=$inD inT=$inT inBC=$inBC\n";
