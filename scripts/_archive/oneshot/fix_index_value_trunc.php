<?php
$path = dirname(__DIR__) . '/public/888/index.html';
$src = file_get_contents($path);

$replacements = [
    // login phone
    "const raw = (document.getElementById('loginPhone') || {}).value \n                return raw.trim();"
        => "const raw = (document.getElementById('loginPhone') || {}).value || '';\n                return String(raw).trim();",

    "return FanshubI18n.toE164((document.getElementById('loginPhone') || {}).value \n        }"
        => "return FanshubI18n.toE164((document.getElementById('loginPhone') || {}).value || '');\n        }",

    "const placeholderKey = country.placeholderKey \n            phoneInput.placeholder = fc(placeholderKey);"
        => "const placeholderKey = country.placeholderKey || 'login_phone_placeholder';\n            phoneInput.placeholder = fc(placeholderKey);",
];

// Line-based fixes for remaining truncated .value lines
$lines = explode("\n", $src);
foreach ($lines as $i => $line) {
    $trim = rtrim($line);
    // ends with .value  (no || '' , no ; , no ))
    if (preg_match('/\|\{\}\)\.value\s*$/', $trim) || preg_match('/\|\| \{\}\)\.value\s*$/', $trim)) {
        // already covered by patterns above perhaps
    }
    if (preg_match('/\.value\s*$/', $trim) && !str_contains($trim, '|| \'\'')) {
        // If next line continues with operator, skip
        $next = isset($lines[$i + 1]) ? ltrim($lines[$i + 1]) : '';
        if ($next !== '' && ($next[0] === '.' || $next[0] === '+' || str_starts_with($next, '||') || str_starts_with($next, '&&'))) {
            continue;
        }
        // Restore || '' and close if looks like assignment/call start
        if (preg_match('/^\s*(const|let|var)\s+\w+\s*=\s*\(/', $trim) || preg_match('/^\s*(const|let|var)\s+\w+\s*=\s*\(document/', $trim)) {
            $lines[$i] = $trim . " || '';";
            echo "FIX assign L" . ($i + 1) . "\n";
        } elseif (preg_match('/return\s+\w+\.\w+\(\(/', $trim) || preg_match('/return\s+\w+\.\w+\(/', $trim)) {
            // return Foo.bar((...).value   → add || '');
            $lines[$i] = $trim . " || '');";
            echo "FIX return L" . ($i + 1) . "\n";
        } elseif (preg_match('/String\([^)]*\.value\s*$/', $trim)) {
            $lines[$i] = $trim . " || '')";
            echo "FIX string L" . ($i + 1) . "\n";
        } elseif (preg_match('/body\.\w+\s*=\s*\(.*\.value\s*$/', $trim)) {
            $lines[$i] = $trim . " || '';";
            echo "FIX body L" . ($i + 1) . "\n";
        } else {
            echo "SKIP L" . ($i + 1) . " :: " . substr($trim, 0, 100) . "\n";
        }
    }
    // placeholderKey truncated
    if (preg_match('/placeholderKey\s*=\s*country\.placeholderKey\s*$/', $trim)) {
        $lines[$i] = $trim . " || 'login_phone_placeholder';";
        echo "FIX placeholder L" . ($i + 1) . "\n";
    }
}
$src2 = implode("\n", $lines);
file_put_contents($path, $src2);

// re-check with node
$h = $src2;
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
