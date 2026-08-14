<?php
$path = dirname(__DIR__) . '/public/888/index.html';
$lines = file($path); // keeps newlines

function setLines(array &$lines, $start1, $end1, array $newLines) {
    $start = $start1 - 1;
    $end = $end1 - 1;
    $before = array_slice($lines, 0, $start);
    $after = array_slice($lines, $end + 1);
    $mid = [];
    foreach ($newLines as $nl) {
        $mid[] = rtrim($nl, "\r\n") . "\n";
    }
    $lines = array_merge($before, $mid, $after);
}

// 3849-3854 profile doSend payload
setLines($lines, 3849, 3854, [
    '            async function doSend(sliderPayload) {',
    '                try {',
    '                    const payload = Object.assign({',
    '                        mobile: phone,',
    '                        country_code: window.FanshubI18n ? FanshubI18n.country : \'CN\'',
    '                    }, sliderPayload || {});',
]);

// 4056-4059 pack
// Find current pack broken block by scanning
foreach ($lines as $i => $line) {
    if (str_contains($line, "const pack = (window.FanshubI18n && FanshubI18n.currentPack())")) {
        // replace this and next 3 lines if they look broken
        setLines($lines, $i + 1, $i + 4, [
            '                const pack = Math.round(',
            '                    (parseFloat(honor.next_tier.rights) || 0) * unit',
            '                    + (parseFloat(honor.next_tier.balance) || 0)',
            '                );',
        ]);
        echo "fixed pack at L" . ($i + 1) . "\n";
        break;
    }
}

// doSendSms - find broken payload
foreach ($lines as $i => $line) {
    if (str_contains($line, 'async function doSendSms(phone, sliderPayload)')) {
        // expect try + bad payload next lines
        if (isset($lines[$i + 2]) && str_contains($lines[$i + 2], "const payload = ts +")) {
            setLines($lines, $i + 1, $i + 6, [
                '        async function doSendSms(phone, sliderPayload) {',
                '            try {',
                '                const payload = Object.assign({',
                '                    mobile: phone,',
                '                    country_code: window.FanshubI18n ? FanshubI18n.country : \'CN\'',
                '                }, sliderPayload || {});',
            ]);
            echo "fixed doSendSms at L" . ($i + 1) . "\n";
        }
        break;
    }
}

// exchange broken updateprofile
foreach ($lines as $i => $line) {
    if (str_contains($line, "apiRequest('updateprofile', 'POST', { nickname: nickname })")
        && isset($lines[$i + 1]) && str_contains($lines[$i + 1], 'count: selectCount')) {
        setLines($lines, $i + 1, $i + 5, [
            "                const profile = await apiRequest('exchange', 'POST', {",
            '                    count: selectCount,',
            '                    channel: currentSelectedTeam,',
            "                    request_id: newRequestId('ex')",
            '                });',
        ]);
        echo "fixed exchange at L" . ($i + 1) . "\n";
        break;
    }
}

file_put_contents($path, implode('', $lines));

$h = file_get_contents($path);
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

// show remaining error line context
if ($code !== 0) {
    foreach ($out as $o) {
        if (preg_match('/:(\d+)/', $o, $mm)) {
            $n = (int)$mm[1];
            $sl = explode("\n", $s);
            for ($j = max(0, $n - 3); $j < min(count($sl), $n + 3); $j++) {
                echo ($j + 1) . '| ' . $sl[$j] . "\n";
            }
            break;
        }
    }
}
