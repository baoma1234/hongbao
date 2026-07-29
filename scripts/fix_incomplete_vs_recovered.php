<?php
/**
 * Find live lines that look truncated vs recovered (same function context).
 * Strategy: for each line in live ending with incomplete expr, find recovered line
 * that starts with the same left side and is longer / complete.
 */
$livePath = dirname(__DIR__) . '/public/888/index.html';
$recPath = dirname(__DIR__) . '/public/888/index_recovered.html';
$live = file($livePath);
$rec = file($recPath);

function isIncomplete($line) {
    $t = rtrim($line);
    if ($t === '' || preg_match('/^\s*(\/\/|\/\*|\*)/', $t)) return false;
    if (preg_match('/^\s*(else|catch|finally|do)\b/', $t)) return false;
    // HTML
    if (preg_match('/^\s*</', $t)) return false;

    $open = substr_count($t, '(');
    $close = substr_count($t, ')');
    $sq = preg_match_all("/(?<!\\\\)'/", $t);
    $dq = preg_match_all('/(?<!\\\\)"/', $t);
    $ob = substr_count($t, '{');
    $cb = substr_count($t, '}');
    $os = substr_count($t, '[');
    $cs = substr_count($t, ']');

    if ($open > $close) return true;
    if ($os > $cs) return true;
    if ($sq % 2 !== 0) return true;
    if ($dq % 2 !== 0) return true;
    if (preg_match('/\|\|\s*$/', $t)) return true;
    if (preg_match('/,\s*$/', $t) && $ob <= $cb && !preg_match('/^\s*(case|default)/', $t)) {
        // trailing comma alone on statement might be ok in arrays - if next is not continuation of array
        return false; // don't flag
    }
    // assignment ending mid-token without terminator
    if (preg_match('/=\s*[^;]*[^\s;{})\],]\s*$/', $t)
        && !preg_match('/[;{}]\s*$/', $t)
        && !preg_match('/^\s*(function|class|if|for|while|switch|try)\b/', $t)
        && $open === $close
        && $os === $cs
        && ($sq % 2 === 0)
    ) {
        // e.g. `navigator.userAgent` without || or ;
        if (preg_match('/\.\w+\s*$/', $t) && !preg_match('/;\s*$/', $t) && !preg_match('/\{\s*$/', $t)) {
            return true;
        }
    }
    return false;
}

function leftKey($line) {
    $t = trim($line);
    // strip trailing incomplete junk for matching
    $t = preg_replace('/\s+$/', '', $t);
    if (preg_match('/^((?:const|let|var)\s+\w+)/', $t, $m)) return $m[1];
    if (preg_match('/^(async\s+function\s+\w+|function\s+\w+)/', $t, $m)) return $m[1];
    if (preg_match('/^([a-zA-Z_$][\w.$]*)\s*=/', $t, $m)) return $m[1] . '=';
    return substr($t, 0, 40);
}

$recIndex = [];
foreach ($rec as $i => $line) {
    if (!isIncomplete($line) && trim($line) !== '') {
        $k = leftKey($line);
        // Prefer longer complete lines
        if (!isset($recIndex[$k]) || strlen(trim($line)) > strlen(trim($recIndex[$k]['line']))) {
            $recIndex[$k] = ['line' => $line, 'no' => $i + 1];
        }
    }
}

$fixed = 0;
foreach ($live as $i => $line) {
    if (!isIncomplete($line)) continue;
    $k = leftKey($line);
    $trim = rtrim($line);

    // Prefer exact prefix match in recovered
    $found = null;
    $prefix = preg_replace('/\s+/', ' ', trim($line));
    $prefix = rtrim($prefix, " \t");
    foreach ($rec as $ri => $rl) {
        $rt = preg_replace('/\s+/', ' ', trim($rl));
        if ($prefix !== '' && str_starts_with($rt, $prefix) && strlen($rt) > strlen($prefix) + 2) {
            $found = $rl;
            break;
        }
        // soft: live is prefix of recovered after removing trailing incomplete part
        $soft = preg_replace('/(\s*\|\|\s*)?$/', '', $prefix);
        if (strlen($soft) >= 15 && str_starts_with($rt, $soft) && strlen($rt) > strlen($soft) + 3) {
            $found = $rl;
            break;
        }
    }

    if (!$found && isset($recIndex[$k]) && strlen(trim($recIndex[$k]['line'])) > strlen($trim) + 2) {
        // only if recovered left key matches AND recovered line contains live's distinctive tokens
        $cand = $recIndex[$k]['line'];
        if (str_contains(trim($cand), explode(' ', trim($line))[0] ?? '___')) {
            $found = $cand;
        }
    }

    if ($found) {
        if (preg_match('/^(\s*)/', $line, $ind)) {
            $rep = $ind[1] . ltrim($found);
            if (!str_ends_with($rep, "\n")) $rep .= "\n";
        } else {
            $rep = $found;
        }
        // Safety: don't replace with wildly different content
        similar_text(trim($line), trim($rep), $pct);
        if ($pct < 30 && strlen(trim($line)) > 20) {
            echo "SKIP low-sim L" . ($i + 1) . " pct=$pct :: " . substr(trim($line), 0, 60) . "\n";
            continue;
        }
        $live[$i] = $rep;
        $fixed++;
        echo "FIX L" . ($i + 1) . " :: " . substr(trim($rep), 0, 90) . "\n";
    } else {
        echo "MISS L" . ($i + 1) . " :: " . substr($trim, 0, 90) . "\n";
    }
}

file_put_contents($livePath, implode('', $live));
echo "fixed=$fixed\n";

$h = file_get_contents($livePath);
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
