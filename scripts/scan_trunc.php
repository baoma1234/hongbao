<?php
/**
 * Find lines that look truncated: end with incomplete expression tokens
 * and next line does not look like a valid continuation.
 */
$path = dirname(__DIR__) . '/public/888/index.html';
$h = file_get_contents($path);
preg_match_all('#<script(?![^>]+src=)[^>]*>(.*?)</script>#si', $h, $m);
$best = '';
foreach ($m[1] as $b) {
    if (strlen($b) > strlen($best)) $best = $b;
}
$lines = preg_split('/\r?\n/', $best);

// Map script line -> html line
$htmlLines = file($path);
$scriptStartHtml = null;
for ($i = 0; $i < count($htmlLines); $i++) {
    if (preg_match('/<script(?![^>]+src=)[^>]*>/', $htmlLines[$i]) && !preg_match('/src=/', $htmlLines[$i])) {
        // find largest script start - approximate: first big inline after body
    }
}
// Better: search unique snippets
echo "Suspicious truncated lines:\n";
for ($j = 0; $j < count($lines); $j++) {
    $l = rtrim($lines[$j]);
    if ($l === '' || preg_match('/^\s*\/\//', $l) || preg_match('/^\s*\*/', $l)) continue;
    $next = isset($lines[$j + 1]) ? ltrim($lines[$j + 1]) : '';
    $isCont = $next !== '' && (
        preg_match('/^[.?\[\(\+\-\*\&\|!:]/', $next)
        || preg_match('/^(then|catch|finally|else|async|await)\b/', $next)
        || (preg_match('/,\s*$/', $l) && preg_match('/^[\'"`\w\[\{\(]/', $next))
        || (preg_match('/\?\s*$/', $l) && preg_match('/^[:]/', $next) === 0 && preg_match('/^\S/', $next))
        || (preg_match('/:\s*$/', $l) && preg_match('/^[\'"`\w\[\{\(]/', $next))
        || (preg_match('/\(\s*$/', $l) && preg_match('/^[\'"`\w\[\{\(]!?\w/', $next))
        || (preg_match('/=\s*$/', $l) && preg_match('/^[\'"`\w\[\{\(]/', $next))
        || (preg_match('/\|\|\s*$/', $l) && preg_match('/^[\'"`\w\[\(\-!]/', $next))
    );
    // Truncation patterns that are NEVER ok without continuation
    $bad = false;
    if (preg_match('/\|\|\s*$/', $l) && !$isCont) $bad = true;
    if (preg_match('/\|\|\s+[a-zA-Z_$][\w.]*\s*$/', $l) && !preg_match('/[;,\)\}\]]\s*$/', $l) && !$isCont) {
        // e.g. || account.phone   without ; or )
        // but || foo() is ok if closed
        if (!preg_match('/\)\s*$/', $l)) $bad = true;
    }
    if (preg_match('/\?\s*$/', $l) && !$isCont) $bad = true;
    if (preg_match('/(?<![?:])\s+:\s*$/', $l) && !$isCont) $bad = true;
    if (preg_match('/[=,]\s*$/', $l) && !$isCont) $bad = true;
    if (preg_match('/\b(return|await|throw|new|typeof|delete)\s+$/', $l)) $bad = true;
    if (preg_match('/\b(const|let|var)\s+\w+\s*=\s*[^=;{]*\|\|\s*\w+(\.\w+)*\s*$/', $l) && !preg_match('/[;\)\}\]]\s*$/', $l) && !$isCont) {
        $bad = true;
    }
    // Unclosed string starting with quote but odd ending
    if (preg_match('/[\'"][^\'"]*$/', $l) && !preg_match('/\\\\$/', $l)) {
        // count quotes
        $sq = substr_count($l, "'") - substr_count($l, "\\'");
        $dq = substr_count($l, '"') - substr_count($l, '\\"');
        // naive
    }
    if ($bad) {
        echo 'S' . ($j + 1) . '|' . $l . "\n";
        // find in html
        $needle = substr(ltrim($l), 0, 40);
        foreach ($htmlLines as $hi => $hl) {
            if (str_contains($hl, $needle)) {
                echo '  HTML L' . ($hi + 1) . "\n";
                break;
            }
        }
    }
}
