<?php
/**
 * Bulk-restore truncated JS lines from index_recovered.html / index.html.bak
 * by longest-prefix match on trimmed content.
 */
$livePath = dirname(__DIR__) . '/public/888/index.html';
$sources = [
    file(dirname(__DIR__) . '/public/888/index_recovered.html'),
    file(dirname(__DIR__) . '/public/888/index.html.bak'),
];

function isLikelyTruncated($line) {
    $l = rtrim($line);
    if ($l === '' || preg_match('/^\s*\/\//', $l) || preg_match('/^\s*\*/', $l)) return false;
    // ends mid-expression
    if (preg_match('/\|\|\s*$/', $l)) return true;
    if (preg_match('/\|\|\s+[a-zA-Z_$][\w.]*\s*$/', $l) && !preg_match('/[;,)\]}]\s*$/', $l)) return true;
    if (preg_match('/=\s*[a-zA-Z_$][\w.]*\s*$/', $l) && !preg_match('/[;,)\]}]\s*$/', $l) && !preg_match('/\b(?:true|false|null|undefined)\s*$/', $l)) {
        // assignment without terminator - often truncated || ''
        // but `x = y` can be valid if next line continues - caller checks next
        return true;
    }
    if (preg_match('/\?\s*$/', $l)) return true;
    if (preg_match('/[=,]\s*$/', $l)) return true;
    if (preg_match('/\(\s*$/', $l)) return true;
    if (preg_match('/\b(?:return|await|throw|new)\s+$/', $l)) return true;
    // garbled unfinished string often ends with odd chars without quote close
    if (preg_match("/\|\|\s*'[^']*$/", $l) && !preg_match("/'\s*;?\s*$/", $l)) return true;
    if (preg_match('/\|\|\s*"[^"]*$/', $l) && !preg_match('/"\s*;?\s*$/', $l)) return true;
    return false;
}

function nextLooksContinuation($next) {
    $n = ltrim($next);
    if ($n === '') return false;
    return (bool) preg_match('/^[.?\[\(\+\-\*\&\|!:`\'"\w]/', $n)
        && !preg_match('/^(const|let|var|function|async|if|for|while|return|try|catch|else|class|switch|case|break|continue|document\.|window\.|account\.|CONFIG\.|showFans|update|render|apply|start|set|get|open|close|await |sessionStorage|localStorage)\b/', $n);
}

$live = file($livePath);
$fixed = 0;
$miss = [];

for ($i = 0; $i < count($live); $i++) {
    if (!isLikelyTruncated($live[$i])) continue;
    $next = $live[$i + 1] ?? '';
    // Object literal key line `currency: currency,` is fine
    if (preg_match('/^\s*\w+\s*:\s*[^,]+,\s*$/', $live[$i])) continue;
    if (nextLooksContinuation($next) && preg_match('/[?=,(|]\s*$/', rtrim($live[$i]))) continue;

    $trim = rtrim($live[$i]);
    $prefix = preg_replace('/\s+/', ' ', trim($trim));
    // strip trailing incomplete || 
    $soft = preg_replace('/\s*\|\|\s*$/', '', $prefix);
    $soft = preg_replace('/\s+$/', '', $soft);

    $found = null;
    $foundLen = 0;
    foreach ($sources as $src) {
        foreach ($src as $sl) {
            $st = rtrim($sl);
            $stNorm = preg_replace('/\s+/', ' ', trim($st));
            if ($prefix !== '' && str_starts_with($stNorm, $prefix) && strlen($stNorm) > strlen($prefix)) {
                if (strlen($stNorm) > $foundLen) {
                    $found = $sl;
                    $foundLen = strlen($stNorm);
                }
            } elseif (strlen($soft) >= 12 && str_starts_with($stNorm, $soft) && strlen($stNorm) > strlen($soft) + 1) {
                if (strlen($stNorm) > $foundLen) {
                    $found = $sl;
                    $foundLen = strlen($stNorm);
                }
            }
        }
    }

    if (!$found) {
        // Heuristic: append || '' ; for property chains
        if (preg_match('/\|\|\s*[a-zA-Z_$][\w.]*\s*$/', $trim) && !str_contains($trim, '|| \'')) {
            $found = $trim . " || '';\n";
        } elseif (preg_match('/=\s*[a-zA-Z_$][\w.]*\s*$/', $trim)) {
            $found = $trim . " || '';\n";
        } elseif (preg_match('/\|\|\s*$/', $trim)) {
            $found = $trim . " '';\n";
        } else {
            $miss[] = ($i + 1) . '|' . $trim;
            continue;
        }
    }

    if (!str_ends_with($found, "\n")) $found .= "\n";
    // keep live indent if found from heuristic without indent
    if (!preg_match('/^\s/', $found) && preg_match('/^(\s*)/', $live[$i], $ind)) {
        $found = $ind[1] . ltrim($found);
    }

    if (rtrim($live[$i]) === rtrim($found)) continue;
    echo 'FIX L' . ($i + 1) . ' => ' . substr(trim($found), 0, 100) . "\n";
    $live[$i] = $found;
    $fixed++;
}

file_put_contents($livePath, implode('', $live));
echo "fixed=$fixed miss=" . count($miss) . "\n";
foreach (array_slice($miss, 0, 30) as $m) echo "MISS $m\n";
