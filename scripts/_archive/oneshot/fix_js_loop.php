<?php
/**
 * Iteratively find syntax errors and attempt prefix-restore from bak+recovered.
 */
$livePath = dirname(__DIR__) . '/public/888/index.html';
$sources = [
    file(dirname(__DIR__) . '/public/888/index.html.bak'),
    file(dirname(__DIR__) . '/public/888/index_recovered.html'),
];

function extractScript($htmlFile) {
    $h = is_array($htmlFile) ? implode('', $htmlFile) : file_get_contents($htmlFile);
    preg_match_all('#<script(?![^>]+src=)[^>]*>(.*?)</script>#si', $h, $m);
    $s = '';
    foreach ($m[1] as $b) {
        if (strlen($b) > strlen($s)) $s = $b;
    }
    return $s;
}

function nodeCheck($script) {
    $t = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fh_loop.js';
    file_put_contents($t, $script);
    $out = [];
    $code = 0;
    exec('node --check ' . escapeshellarg($t) . ' 2>&1', $out, $code);
    $lineNo = 0;
    $msg = implode("\n", $out);
    if (preg_match('/:(\d+)\r?\n/', $msg, $m)) $lineNo = (int)$m[1];
    return [$code, $lineNo, $out, $t];
}

for ($round = 1; $round <= 40; $round++) {
    $live = file($livePath);
    $script = extractScript($livePath);
    [$code, $lineNo, $out, $t] = nodeCheck($script);
    if ($code === 0) {
        echo "OK after round $round\n";
        break;
    }
    $slines = file($t);
    $bad = trim($slines[$lineNo - 1] ?? '');
    echo "R$round err L$lineNo :: " . substr($bad, 0, 90) . "\n";
    if ($bad === '') {
        echo "empty bad line, stop\n";
        echo implode("\n", array_slice($out, 0, 5)) . "\n";
        break;
    }

    // Find in live HTML
    $liveIdx = null;
    foreach ($live as $i => $line) {
        if (str_contains($line, $bad) || (strlen($bad) > 20 && str_contains($line, substr($bad, 0, 40)))) {
            $liveIdx = $i;
            break;
        }
    }
    if ($liveIdx === null) {
        // try without leading spaces
        $bad2 = ltrim($bad);
        foreach ($live as $i => $line) {
            if (str_contains($line, $bad2)) { $liveIdx = $i; break; }
        }
    }
    if ($liveIdx === null) {
        echo "cannot locate in HTML, stop\n";
        echo implode("\n", array_slice($out, 0, 8)) . "\n";
        break;
    }

    $liveTrim = rtrim($live[$liveIdx]);
    $found = null;
    foreach ($sources as $srcLines) {
        foreach ($srcLines as $sl) {
            $st = rtrim($sl);
            // source line starts with live truncated content
            $prefix = preg_replace('/\s+$/', '', $liveTrim);
            $prefix = preg_replace('/\s+/', ' ', trim($prefix));
            $stNorm = preg_replace('/\s+/', ' ', trim($st));
            if ($prefix !== '' && str_starts_with($stNorm, $prefix) && strlen($stNorm) > strlen($prefix) + 2) {
                $found = $sl;
                break 2;
            }
            // soft: live is missing || '...')
            $soft = preg_replace('/(\s*\|\|\s*)$/', '', $prefix);
            if (strlen($soft) >= 18 && str_starts_with($stNorm, $soft) && strlen($stNorm) > strlen($soft) + 3) {
                $found = $sl;
                break 2;
            }
        }
    }

    if (!$found) {
        // Manual heuristics for common truncations
        if (preg_match('/setProfilePwdMode\(profilePwdMode\s*$/', $liveTrim)) {
            $found = "            if (which === 'password') setProfilePwdMode(profilePwdMode || 'old');\n";
        } elseif (preg_match('/needPoll = !!\(checkin\s*$/', $liveTrim)) {
            $found = "            const needPoll = !!(checkin && checkin.bonus_unlock_pending);\n";
        } elseif (preg_match('/\|\|\s*$/', $liveTrim)) {
            $found = $liveTrim . " '';\n";
        }
    }

    if (!$found) {
        echo "no repair for L" . ($liveIdx + 1) . " :: $liveTrim\n";
        echo implode("\n", array_slice($out, 0, 8)) . "\n";
        break;
    }

    if (preg_match('/^(\s*)/', $live[$liveIdx], $ind)) {
        $rep = $ind[1] . ltrim($found);
        if (!str_ends_with($rep, "\n")) $rep .= "\n";
    } else {
        $rep = $found;
    }
    echo "  FIX HTML L" . ($liveIdx + 1) . " => " . substr(trim($rep), 0, 90) . "\n";
    $live[$liveIdx] = $rep;
    file_put_contents($livePath, implode('', $live));
}
