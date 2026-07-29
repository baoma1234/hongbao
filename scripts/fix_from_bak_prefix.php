<?php
/**
 * Bulk-repair truncated `|| ...` endings by matching line starts against bak (valid JS).
 */
$livePath = dirname(__DIR__) . '/public/888/index.html';
$bakPath = dirname(__DIR__) . '/public/888/index.html.bak';
$live = file($livePath);
$bak = file($bakPath);

function incomplete($t) {
    $t = rtrim($t);
    $o = substr_count($t, '('); $c = substr_count($t, ')');
    $os = substr_count($t, '['); $cs = substr_count($t, ']');
    $q = preg_match_all("/(?<!\\\\)'/", $t);
    if ($o > $c) return true;
    if ($os > $cs) return true;
    if ($q % 2) return true;
    if (preg_match('/\|\|\s*$/', $t)) return true;
    if (preg_match('/\.\w+\s*$/', $t) && !preg_match('/[;{}]\s*$/', $t) && !preg_match('/^\s*</', $t) && !preg_match('/^\s*\/\//', $t)) {
        // property access ending - likely truncated if next line starts new statement
        return 'maybe';
    }
    return false;
}

// Build bak map: first 50 chars of trimmed line -> full line (prefer longest)
$map = [];
foreach ($bak as $line) {
    $t = trim($line);
    if ($t === '' || str_starts_with($t, '//') || str_starts_with($t, '<')) continue;
    $key = mb_substr($t, 0, 50);
    if (!isset($map[$key]) || strlen($t) > strlen(trim($map[$key]))) {
        $map[$key] = $line;
    }
}

$fixed = 0;
foreach ($live as $i => $line) {
    $flag = incomplete($line);
    if (!$flag) continue;
    $t = trim($line);
    if ($t === '' || str_starts_with($t, '<')) continue;

    $key = mb_substr($t, 0, 50);
    // Also try shorter keys for truncated lines
    $candidates = [];
    foreach ([50, 40, 30, 25, 20] as $len) {
        $k = mb_substr($t, 0, $len);
        foreach ($map as $mk => $mv) {
            if (str_starts_with($mk, $k) || str_starts_with($k, mb_substr($mk, 0, $len))) {
                if (strlen(trim($mv)) > strlen($t) + 1) {
                    $candidates[] = $mv;
                }
            }
        }
        if ($candidates) break;
    }

    // Better: bak line starts with live line content (live is truncated prefix)
    $found = null;
    foreach ($bak as $bl) {
        $bt = trim($bl);
        if ($t !== '' && str_starts_with($bt, $t) && strlen($bt) > strlen($t) + 2) {
            $found = $bl;
            break;
        }
        // live missing trailing part: compare without last incomplete token
        $soft = preg_replace('/(\s*\|\|\s*\[?\s*)$/', '', $t);
        $soft = preg_replace('/\s+$/', '', $soft);
        if (strlen($soft) >= 20 && str_starts_with($bt, $soft) && strlen($bt) > strlen($soft) + 3) {
            $found = $bl;
            break;
        }
    }

    if (!$found) {
        if ($flag === true) {
            echo "MISS L" . ($i + 1) . " :: " . substr($t, 0, 80) . "\n";
        }
        continue;
    }

    similar_text($t, trim($found), $pct);
    if ($pct < 40 && strlen($t) > 15) {
        echo "SKIP L" . ($i + 1) . " sim=$pct\n";
        continue;
    }

    if (preg_match('/^(\s*)/', $line, $ind)) {
        $rep = $ind[1] . ltrim($found);
        if (!str_ends_with($rep, "\n")) $rep .= "\n";
    } else {
        $rep = $found;
    }
    $live[$i] = $rep;
    $fixed++;
    echo "FIX L" . ($i + 1) . " => " . substr(trim($rep), 0, 90) . "\n";
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
echo implode("\n", array_slice($out, 0, 5)) . "\nexit=$code\n";
