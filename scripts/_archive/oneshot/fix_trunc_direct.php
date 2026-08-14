<?php
$live = dirname(__DIR__) . '/public/888/index.html';
$src = file_get_contents($live);

$fixes = [
    "const text = String(message "
        => "const text = String(message || '').replace(/\\\\n/g, '\\n').replace(/\\n{3,}/g, '\\n\\n').trim();",

    "const locale = (window.FanshubI18n && FanshubI18n.locale) "
        => "const locale = (window.FanshubI18n && FanshubI18n.locale) || 'zh-CN';",

    "const httpMethod = (method "
        => "const httpMethod = (method || 'POST').toUpperCase();",

    "const nick = (profile.nickname "
        => "const nick = (profile.nickname || '').trim();",

    "const letter = (nick || profile.mobile_mask "
        => "const letter = (nick || profile.mobile_mask || 'U').charAt(0);",

    "if (uidEl) uidEl.textContent = profile.user_id "
        => "if (uidEl) uidEl.textContent = profile.user_id || '-';",

    "if (mobileEl) mobileEl.textContent = profile.mobile_mask || profile.mobile "
        => "if (mobileEl) mobileEl.textContent = profile.mobile_mask || profile.mobile || '';",

    "if (nameEl) nameEl.textContent = nick || (profile.mobile_mask || ('ID' + (profile.user_id "
        => "if (nameEl) nameEl.textContent = nick || (profile.mobile_mask || ('ID' + (profile.user_id || '')));",

    "const name = fc('phase2_honor_name_' + n.id) || n.name "
        => "const name = fc('phase2_honor_name_' + n.id) || n.name;",

    "const nextName = fc('phase2_honor_name_' + (honor.next_tier.id "
        => "const nextName = fc('phase2_honor_name_' + (honor.next_tier.id || '')) || honor.next_tier.name;",

    "document.getElementById('phase2ModalTitle').textContent = title "
        => "document.getElementById('phase2ModalTitle').textContent = title || '';",

    "bodyEl.innerHTML = (body "
        => "bodyEl.innerHTML = (body || '').replace(/\\n/g, '<br>');",

    "bodyEl.textContent = body "
        => "bodyEl.textContent = body || '';",

    "btn.className = 'phase2-modal-btn ' + (act.style "
        => "btn.className = 'phase2-modal-btn ' + (act.style || 'primary');",

    "const uid = (account.main_uid "
        => "const uid = (account.main_uid || '').trim();",

    "if ((account.main_uid_audit "
        => "if ((account.main_uid_audit || '') === 'pending') {\n                        showFanshubToast(fc('uid_hint_pending'), 'info');",

    "return String(raw "
        => "return String(raw || '').replace(/[^A-Za-z0-9]/g, '').slice(0, 32);",

    "+ icon + ' ' + name + '<br>' + (fc('phase2_honor_people', { count: n.threshold }) || ('(' + n.threshold + '浜?'))"
        => "+ icon + ' ' + name + '<br>' + (fc('phase2_honor_people', { count: n.threshold }) || ('(' + n.threshold + '人)'))",
];

$n = 0;
foreach ($fixes as $from => $to) {
    if (str_contains($src, $from)) {
        $src = str_replace($from, $to, $src, $c);
        $n += $c;
        echo "OK x$c :: " . substr($from, 0, 50) . "\n";
    } else {
        echo "MISS :: " . substr($from, 0, 60) . "\n";
    }
}

// Fix reward ternary opener if still broken: "const reward = (bal > 0" should continue - check
if (preg_match('/const reward = \(bal > 0\s*\n\s*\?/', $src)) {
    echo "reward ternary OK\n";
} elseif (str_contains($src, 'const reward = (bal > 0')) {
    // ensure it's `const reward = (bal > 0` followed by newline and ?
    echo "reward check: " . (strpos($src, "const reward = (bal > 0\n") !== false ? 'multiline' : 'same-line-broken') . "\n";
}

file_put_contents($live, $src);
echo "total=$n\n";

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
