<?php
/**
 * Restore truncated JS expressions from index_recovered.html
 */
$live = dirname(__DIR__) . '/public/888/index.html';
$rec = dirname(__DIR__) . '/public/888/index_recovered.html';
$liveLines = file($live);
$recLines = file($rec);

// Map: live line => recovered line (1-based), for known truncations
// Also scan recovered for patterns and apply by function-context matching

$pairs = [
    // [liveApproxLine, recoveredLine]
    [3013, 3014], // String(message
    [3328, null], // locale - check recovered
    [3330, 3337], // httpMethod
    [3705, 3712], // nick
    [3706, null],
    [3707, null],
    [3708, null],
    [3709, null],
    [4023, null], // name =
    [4024, 4031], // reward - multiline ok if starts same
    [4060, 4067], // nextName
    [4172, null],
    [4174, 4181], // bodyEl.innerHTML
    [4176, null],
    [4183, null], // act.style
    [4495, 4502], // main_uid
    [4499, 4506], // main_uid_audit
    [4597, 4605], // sanitizeUidValue
];

// Better approach: for each truncated live line, find similar start in recovered and copy full recovered line
function lineStartKey($line) {
    $t = trim($line);
    // take up to first || or end of short prefix
    if (preg_match('/^(.{20,80?}?)(\s*\|\||\s*$)/u', $t, $m)) {
        return rtrim($m[1]);
    }
    return mb_substr($t, 0, 60);
}

$recByPrefix = [];
foreach ($recLines as $i => $line) {
    $t = trim($line);
    if ($t === '') continue;
    // index by left side of assignment / return
    if (preg_match('/^((?:const|let|var)\s+\w+\s*=\s*[^=]+?)\s*\|\|/u', $t, $m)) {
        $recByPrefix[trim($m[1])] = $line;
    } elseif (preg_match('/^(return\s+String\([^|]+)\s*\|\|/u', $t, $m)) {
        $recByPrefix[trim($m[1])] = $line;
    } elseif (preg_match('/^(bodyEl\.innerHTML\s*=\s*\([^|]+)\s*\|\|/u', $t, $m)) {
        $recByPrefix[trim($m[1])] = $line;
    } elseif (preg_match('/^(document\.getElementById\([^)]+\)\.textContent\s*=\s*[^=]+)\s*$/u', $t, $m)) {
        // skip
    }
}

$fixed = 0;
foreach ($liveLines as $i => $line) {
    $trim = rtrim($line);
    $incomplete = false;
    if (preg_match('/\bString\s*\(\s*\w+\s*$/', $trim)) $incomplete = true;
    if (preg_match('/=\s*\(\s*\w+\s*$/', $trim) && substr_count($trim, '(') > substr_count($trim, ')')) $incomplete = true;
    if (preg_match('/\|\|\s*$/', $trim)) $incomplete = true;
    if (preg_match('/\.id\s*$/', $trim) && str_contains($trim, 'fc(')) $incomplete = true;
    if (preg_match('/profile\.(nickname|mobile_mask)\s*$/', $trim)) $incomplete = true;
    if (preg_match('/account\.main_uid\s*$/', $trim)) $incomplete = true;
    if (preg_match('/main_uid_audit\s*$/', $trim)) $incomplete = true;
    if (preg_match('/act\.style\s*$/', $trim)) $incomplete = true;
    if (preg_match('/FanshubI18n\.locale\s*$/', $trim)) $incomplete = true;
    if (preg_match('/textContent\s*=\s*title\s*$/', $trim)) $incomplete = true;
    if (preg_match('/textContent\s*=\s*body\s*$/', $trim)) $incomplete = true;
    if (preg_match('/innerHTML\s*=\s*\(\s*body\s*$/', $trim)) $incomplete = true;
    if (preg_match('/n\.name\s*$/', $trim) && str_contains($trim, 'fc(')) $incomplete = true;
    if (preg_match('/user_id\s*$/', $trim) && str_contains($trim, 'profile.user_id')) $incomplete = true;
    if (preg_match('/mobile_mask\s*\|\|\s*profile\.mobile\s*$/', $trim)) $incomplete = true;

    if (!$incomplete) continue;

    // Find recovered line with same left-hand prefix
    $found = null;
    $left = $trim;
    foreach ($recLines as $rl) {
        $rt = rtrim($rl);
        // recovered line should start with same beginning
        $prefixLen = min(strlen($trim), 40);
        if ($prefixLen < 10) continue;
        if (strncmp(ltrim($trim), ltrim($rt), $prefixLen) === 0 && strlen($rt) > strlen($trim) + 2) {
            $found = $rl;
            break;
        }
        // softer: match up to last complete token
        if (preg_match('/^(\s*(?:const|let|var)\s+\w+\s*=\s*)/u', $trim, $m)) {
            if (str_starts_with(ltrim($rt), ltrim($m[0])) && str_contains($rt, $m[0] === '' ? 'x' : trim(explode('=', $trim)[0])) ) {
                $lhs = trim(explode('=', $trim, 2)[0]);
                if (str_starts_with(trim($rt), $lhs) && strlen($rt) > strlen($trim)) {
                    $found = $rl;
                    break;
                }
            }
        }
    }
    if ($found) {
        // preserve indentation from live
        if (preg_match('/^(\s*)/', $line, $ind)) {
            $found = $ind[1] . ltrim($found);
            if (!str_ends_with($found, "\n")) $found .= "\n";
        }
        $liveLines[$i] = $found;
        $fixed++;
        echo "FIX L" . ($i + 1) . " => " . substr(trim($found), 0, 100) . "\n";
    } else {
        echo "MISS L" . ($i + 1) . " :: " . substr($trim, 0, 100) . "\n";
    }
}

file_put_contents($live, implode('', $liveLines));
echo "fixed=$fixed\n";

// node check
$h = file_get_contents($live);
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
