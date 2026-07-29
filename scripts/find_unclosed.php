<?php
$path = dirname(__DIR__) . '/public/888/index.html';
$h = file_get_contents($path);
preg_match_all('#<script(?![^>]+src=)[^>]*>(.*?)</script>#si', $h, $m);
$best = '';
foreach ($m[1] as $b) {
    if (strlen($b) > strlen($best)) $best = $b;
}
$lines = preg_split('/\r?\n/', $best);
$n = count($lines);
$t = sys_get_temp_dir() . '/fh_bin.js';

function checkPrefix($lines, $i, $t) {
    $chunk = implode("\n", array_slice($lines, 0, $i)) . "\n";
    file_put_contents($t, $chunk);
    $out = [];
    $code = 0;
    exec('node --check ' . escapeshellarg($t) . ' 2>&1', $out, $code);
    $msg = implode("\n", $out);
    return [$code, str_contains($msg, 'Unexpected end of input'), $msg, $out];
}

// Find last balanced prefix (code===0)
$lo = 1450;
$hi = $n;
$lastOk = 1450;
while ($lo <= $hi) {
    $mid = intdiv($lo + $hi, 2);
    [$code, $eof, $msg] = checkPrefix($lines, $mid, $t);
    if ($code === 0) {
        $lastOk = $mid;
        $lo = $mid + 1;
    } else {
        $hi = $mid - 1;
    }
}
echo "lastOk=$lastOk\n";
for ($j = max(0, $lastOk - 3); $j < min($n, $lastOk + 40); $j++) {
    echo ($j + 1) . '|' . $lines[$j] . "\n";
}

// Also walk forward from lastOk finding when we get a concrete (non-EOF) error if we close with dummy braces
echo "\n--- look for concrete errors with auto-close ---\n";
for ($i = $lastOk; $i <= min($n, $lastOk + 200); $i++) {
    $chunk = implode("\n", array_slice($lines, 0, $i)) . "\n";
    // append many closers
    $chunk .= "\n" . str_repeat("}\n", 30) . str_repeat(")\n", 30) . str_repeat("]\n", 10);
    file_put_contents($t, $chunk);
    $out = [];
    $code = 0;
    exec('node --check ' . escapeshellarg($t) . ' 2>&1', $out, $code);
    $msg = implode("\n", $out);
    if ($code !== 0 && !str_contains($msg, 'Unexpected end of input') && !str_contains($msg, 'Unexpected token')) {
        // might still be unexpected token from extra closers
    }
    if ($code !== 0 && preg_match('/SyntaxError: (?!Unexpected (?:end of input|token))/', $msg)) {
        echo "at $i: $msg\n";
        break;
    }
    // Detect when adding closers still fails with missing ) after argument list etc on a specific line
    if ($code !== 0 && preg_match('/missing|Invalid|after argument|Unexpected identifier|Unexpected string|Unexpected number/', $msg)) {
        $errLine = 0;
        if (preg_match('/:(\d+)/', $msg, $mm)) $errLine = (int)$mm[1];
        if ($errLine > 0 && $errLine <= $i) {
            echo "CONCRETE at prefix $i errLine $errLine\n";
            echo implode("\n", array_slice($out, 0, 5)) . "\n";
            for ($j = max(0, $errLine - 3); $j < min($n, $errLine + 2); $j++) {
                echo ($j + 1) . '|' . $lines[$j] . "\n";
            }
            break;
        }
    }
}

// Brute: check each line from lastOk+1 for truncated endings
echo "\n--- truncated-looking lines after lastOk ---\n";
for ($j = $lastOk; $j < $n; $j++) {
    $l = rtrim($lines[$j]);
    if ($l === '') continue;
    if (preg_match('/\|\|\s*$/', $l)
        || preg_match('/\?\s*$/', $l)
        || preg_match('/:\s*$/', $l)
        || preg_match('/=\s*$/', $l)
        || preg_match('/\(\s*$/', $l)
        || preg_match('/,\s*$/', $l)
        || preg_match('/return\s+$/', $l)
        || preg_match('/\b(await|throw|new)\s+$/', $l)
        || preg_match('/\|\|\s*[\'"][^\'"]*$/', $l)
        || preg_match('/[^\x20-\x7E\x{4e00}-\x{9fff}\s]$/u', $l) // ends with weird char
    ) {
        // filter false positives: lines that correctly continue
        $next = isset($lines[$j + 1]) ? ltrim($lines[$j + 1]) : '';
        $okContinue = preg_match('/^[.?\[\(\+\-\*\&\|\!]/', $next) || preg_match('/^(then|catch|finally|else)\b/', $next);
        if (preg_match('/\|\|\s*$|\?\s*$|:\s*$|=\s*$|\(\s*$|,\s*$/', $l) && $okContinue) continue;
        if (preg_match('/,\s*$/', $l) && $next !== '' && $next[0] !== '}') {
            // array/arg continuation often fine
            if (preg_match('/^[\'"\w\[\{]/', $next)) continue;
        }
        echo ($j + 1) . '|' . $l . "\n";
    }
}
