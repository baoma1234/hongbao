<?php
$path = dirname(__DIR__) . '/public/888/index.html';
$src = file_get_contents($path);

// Exact block replacements for sections damaged by bad restore
$blocks = [
    // err.message
    [
        "const msg = String((err && err.message) \n            if (!msg) return false;",
        "const msg = String((err && err.message) || '');\n            if (!msg) return false;",
    ],
    // SMS cooldown JSON.parse
    [
        "const map = JSON.parse(localStorage.getItem(SMS_COOLDOWN_KEY) \n                const until = map[phone];",
        "const map = JSON.parse(localStorage.getItem(SMS_COOLDOWN_KEY) || '{}');\n                const until = map[phone];",
    ],
    [
        "const map = JSON.parse(localStorage.getItem(SMS_COOLDOWN_KEY) \n                map[phone] = Date.now() + seconds * 1000;",
        "const map = JSON.parse(localStorage.getItem(SMS_COOLDOWN_KEY) || '{}');\n                map[phone] = Date.now() + seconds * 1000;",
    ],
    // profile SMS doSend
    [
        "async function doSend(sliderPayload) {\n                try {\n                    const payload = ts + '\\n' + nonce + '\\n' + httpMethod + '\\n' + path + '\\n' + (httpMethod === 'GET' ? '' : bodyStr);\n                        mobile: phone,\n                        country_code: window.FanshubI18n ? FanshubI18n.country : 'CN'\n                    }, sliderPayload || {});",
        "async function doSend(sliderPayload) {\n                try {\n                    const payload = Object.assign({\n                        mobile: phone,\n                        country_code: window.FanshubI18n ? FanshubI18n.country : 'CN'\n                    }, sliderPayload || {});",
    ],
    // login SMS doSendSms
    [
        "async function doSendSms(phone, sliderPayload) {\n            try {\n                const payload = ts + '\\n' + nonce + '\\n' + httpMethod + '\\n' + path + '\\n' + (httpMethod === 'GET' ? '' : bodyStr);\n                    mobile: phone,\n                    country_code: window.FanshubI18n ? FanshubI18n.country : 'CN'\n                }, sliderPayload || {});",
        "async function doSendSms(phone, sliderPayload) {\n            try {\n                const payload = Object.assign({\n                    mobile: phone,\n                    country_code: window.FanshubI18n ? FanshubI18n.country : 'CN'\n                }, sliderPayload || {});",
    ],
    // honor pack
    [
        "const pack = (window.FanshubI18n && FanshubI18n.currentPack()) || {};\n                    (parseFloat(honor.next_tier.rights) || 0) * unit\n                    + (parseFloat(honor.next_tier.balance) || 0)\n                );",
        "const pack = Math.round(\n                    (parseFloat(honor.next_tier.rights) || 0) * unit\n                    + (parseFloat(honor.next_tier.balance) || 0)\n                );",
    ],
    // exchange
    [
        "const profile = await apiRequest('updateprofile', 'POST', { nickname: nickname });\n                    count: selectCount,\n                    channel: currentSelectedTeam,\n                    request_id: newRequestId('ex')\n                });",
        "const profile = await apiRequest('exchange', 'POST', {\n                    count: selectCount,\n                    channel: currentSelectedTeam,\n                    request_id: newRequestId('ex')\n                });",
    ],
];

foreach ($blocks as $i => [$from, $to]) {
    if (str_contains($src, $from)) {
        $src = str_replace($from, $to, $src, $c);
        echo "BLOCK " . ($i + 1) . " OK x$c\n";
    } else {
        echo "BLOCK " . ($i + 1) . " MISS\n";
        // show nearby fingerprint
        $fp = substr($from, 0, 60);
        echo "  looking for: " . json_encode($fp) . "\n";
    }
}

file_put_contents($path, $src);

preg_match_all('#<script(?![^>]+src=)[^>]*>(.*?)</script>#si', $src, $m);
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
