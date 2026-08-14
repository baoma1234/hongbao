<?php
/**
 * Scan index.html inline JS for common corruptions and auto-fix from bak/recovered.
 */
$livePath = dirname(__DIR__) . '/public/888/index.html';
$bakPath = dirname(__DIR__) . '/public/888/index.html.bak';
$recPath = dirname(__DIR__) . '/public/888/index_recovered.html';

function extractLargestScript($path) {
    $h = file_get_contents($path);
    preg_match_all('#<script(?![^>]+src=)[^>]*>(.*?)</script>#si', $h, $m);
    $best = '';
    foreach ($m[1] as $b) {
        if (strlen($b) > strlen($best)) $best = $b;
    }
    return $best;
}

function nodeCheck($script) {
    $t = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fh_scan.js';
    file_put_contents($t, $script);
    $out = [];
    $code = 0;
    // disable_functions may block passthru; exec is used
    exec('node --check ' . escapeshellarg($t) . ' 2>&1', $out, $code);
    $lineNo = 0;
    $msg = implode("\n", $out);
    if (preg_match('/:(\d+)\r?\n/', $msg, $m)) $lineNo = (int)$m[1];
    elseif (preg_match('/:(\d+):/', $msg, $m)) $lineNo = (int)$m[1];
    return [$code, $lineNo, $out, $t];
}

// Pre-pass: fix // comment swallowing next statement on same line
$live = file($livePath);
$fixedComments = 0;
foreach ($live as $i => $line) {
    // Match: // chinese-or-text then more code that looks like a statement
    if (preg_match('/^(\s*)(.*?)(\/\/[^\n]*[\x{4e00}-\x{9fff}][^\n]*?)(\s{2,}(?:sessionStorage|const |let |var |if |for |while |return |updateFlow|showFans|apply|await |document\.|window\.|CONFIG\.|apiRequest|fc\()).*$/u', $line, $m)) {
        // Better: split when // comment is followed by 2+ spaces then identifier
        if (preg_match('/^(.*\/\/[^\n]*?)(\s{2,})([a-zA-Z_$][\w$.]*\s*[\(=.].*)$/u', $line, $mm)) {
            // Only if the comment part looks truncated (ends with garbled?) OR next is clearly a statement
            $before = rtrim($mm[1]);
            $after = ltrim($mm[3]);
            if (preg_match('/^(sessionStorage|const |let |var |if\s*\(|return |updateFlow|showFans|applyProfile|await |document\.|window\.|CONFIG\.)/', $after)) {
                $indent = '';
                if (preg_match('/^(\s*)/', $line, $ind)) $indent = $ind[1];
                $live[$i] = $before . "\n" . $indent . $after . (str_ends_with($after, "\n") ? '' : "\n");
                // Ensure after has newline - file() keeps newlines on lines usually
                if (!str_ends_with($live[$i], "\n")) $live[$i] .= "\n";
                // Actually split into two array entries is cleaner - do replace with two lines in one entry for now
                $fixedComments++;
                echo "comment-swallow L" . ($i+1) . "\n";
            }
        }
    }
}
if ($fixedComments) {
    file_put_contents($livePath, implode('', $live));
    echo "fixed $fixedComments comment-swallow lines\n";
}

$sources = [file($bakPath), file($recPath)];

for ($round = 1; $round <= 60; $round++) {
    $live = file($livePath);
    $script = extractLargestScript($livePath);
    [$code, $lineNo, $out, $t] = nodeCheck($script);
    if ($code === 0) {
        echo "OK after round $round\n";
        exit(0);
    }
    $slines = file($t);
    $bad = rtrim($slines[$lineNo - 1] ?? '');
    echo "R$round err L$lineNo :: " . substr($bad, 0, 100) . "\n";
    if ($bad === '') {
        echo implode("\n", array_slice($out, 0, 8)) . "\n";
        exit(1);
    }

    $liveIdx = null;
    $badTrim = ltrim($bad);
    foreach ($live as $i => $line) {
        if (str_contains($line, $bad) || (strlen($badTrim) > 15 && str_contains($line, substr($badTrim, 0, 50)))) {
            $liveIdx = $i;
            break;
        }
    }
    if ($liveIdx === null) {
        echo "cannot locate\n";
        echo implode("\n", array_slice($out, 0, 10)) . "\n";
        exit(1);
    }

    $liveTrim = rtrim($live[$liveIdx]);
    $found = null;

    // Heuristics first for common truncations
    if (preg_match('/copyTextSilent\(([^)]+)\s*$/', $liveTrim, $hm)) {
        $found = preg_replace('/\s*$/', '', $liveTrim) . " || '');\n";
        // avoid double ||
        if (str_contains($liveTrim, '||')) {
            $found = preg_replace('/\s*$/', '', $liveTrim) . ");\n";
        }
    } elseif (preg_match('/return String\(text\s*$/', $liveTrim) || preg_match('/return String\(text\s+\}\)/', $liveTrim)) {
        $found = "            return String(text || '')\n";
    } elseif (preg_match('/FanshubI18n\.locale\)\s*$/', $liveTrim)) {
        $found = $liveTrim . " || 'zh-CN';\n";
    } elseif (preg_match('/CONFIG\.IM_WS_URL\s*$/', $liveTrim) || preg_match('/cfg\.im_ws_url \|\| CONFIG\.IM_WS_URL\s*$/', $liveTrim)) {
        $found = "            CONFIG.IM_WS_URL = cfg.im_ws_url || CONFIG.IM_WS_URL || '';\n";
    } elseif (preg_match('/let toastMsg = data\.message\s*$/', $liveTrim)) {
        $found = "                let toastMsg = data.message || '';\n";
    } elseif (preg_match('/setProfilePwdMode\(profilePwdMode\s*$/', $liveTrim)) {
        $found = "            if (which === 'password') setProfilePwdMode(profilePwdMode || 'old');\n";
    } elseif (preg_match('/\|\|\s*$/', $liveTrim)) {
        $found = $liveTrim . " '';\n";
    } elseif (preg_match('/\|\|\s*[\'"][^\'"]*$/', $liveTrim) && !preg_match('/[\'"];?\s*$/', $liveTrim)) {
        // truncated string after ||
        $found = null;
    }

    if (!$found) {
        foreach ($sources as $srcLines) {
            foreach ($srcLines as $sl) {
                $st = rtrim($sl);
                $prefix = preg_replace('/\s+/', ' ', trim($liveTrim));
                $stNorm = preg_replace('/\s+/', ' ', trim($st));
                if ($prefix !== '' && str_starts_with($stNorm, $prefix) && strlen($stNorm) > strlen($prefix) + 1) {
                    $found = $sl;
                    break 2;
                }
                $soft = preg_replace('/(\s*\|\|\s*[\'"]?)$/', '', $prefix);
                $soft = preg_replace('/\s+$/', '', $soft);
                if (strlen($soft) >= 16 && str_starts_with($stNorm, $soft) && strlen($stNorm) > strlen($soft) + 2) {
                    $found = $sl;
                    break 2;
                }
            }
        }
    }

    if (!$found) {
        // Try next line context from bak by function name nearby
        echo "no repair for HTML L" . ($liveIdx + 1) . " :: $liveTrim\n";
        // show +/- 2 lines
        for ($j = max(0, $liveIdx - 1); $j <= min(count($live) - 1, $liveIdx + 2); $j++) {
            echo ($j + 1) . "| " . rtrim($live[$j]) . "\n";
        }
        echo implode("\n", array_slice($out, 0, 8)) . "\n";
        exit(1);
    }

    if (preg_match('/^(\s*)/', $live[$liveIdx], $ind)) {
        $rep = $ind[1] . ltrim(is_string($found) ? $found : $found);
        if (!str_ends_with($rep, "\n")) $rep .= "\n";
    } else {
        $rep = $found;
        if (!str_ends_with($rep, "\n")) $rep .= "\n";
    }
    // If found already has correct indent from source, prefer source indent when replacing whole line from bak
    if (is_string($found) && preg_match('/^\s+/', $found)) {
        $rep = $found;
        if (!str_ends_with($rep, "\n")) $rep .= "\n";
    }

    echo "  FIX HTML L" . ($liveIdx + 1) . " => " . substr(trim($rep), 0, 100) . "\n";
    $live[$liveIdx] = $rep;
    file_put_contents($livePath, implode('', $live));
}

echo "max rounds\n";
exit(1);
