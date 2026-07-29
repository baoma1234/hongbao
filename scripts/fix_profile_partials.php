<?php
$l = file(dirname(__DIR__) . '/public/888/index.html.monolith.bak');
$base = dirname(__DIR__) . '/public/888/partials';

// tab-profile: comment + tab through logout close (2182-2233)
$html = implode('', array_slice($l, 2181, 2233 - 2182 + 1));
$html = str_replace('<strong> id="profileUserId">-</strong>', '<strong id="profileUserId">-</strong>', $html);
$html = str_replace('<strong> id="profileMobileMask">-</strong>', '<strong id="profileMobileMask">-</strong>', $html);
// Ensure tab-page is closed (line 2233 already has </div>)
file_put_contents($base . '/tab-profile.php', $html);
echo "tab-profile " . strlen($html) . "\n";

// Find modal start
$modalAt = null;
for ($i = 2290; $i < count($l); $i++) {
    if (str_contains($l[$i], 'class="modal-mask"') || str_contains($l[$i], 'id="thresholdBlockModal"') || str_contains($l[$i], 'id="thresholdModal')) {
        $modalAt = $i + 1;
        echo "modal marker L$modalAt: " . trim($l[$i]) . "\n";
        break;
    }
}
// Also look for threshold block root
for ($i = 2320; $i < 2360; $i++) {
    echo ($i + 1) . '|' . rtrim($l[$i]) . "\n";
}

// profile subpages: from profileInfoPane (2235) to before modal, skip orphan </div> at 2295
$start = 2234; // 0-based for line 2235
$end = ($modalAt ?: 2340) - 1; // 1-based exclusive end line before modal
$chunk = array_slice($l, $start, $end - $start);
// Drop lone </div> that closed mainDashboard in monolith (line 2295)
$out = [];
foreach ($chunk as $line) {
    if (preg_match('/^\s*<\/div>\s*$/', $line) && trim(implode('', array_slice($out, -3))) === '') {
        // keep structural closes inside panes; only skip the orphan between password pane and recharge comment
    }
    $out[] = $line;
}
// More precise: remove the blank+`    </div>` immediately before recharge comment
$text = implode('', $chunk);
$text = preg_replace('/\n\s*<\/div>\s*\n(\s*<!--[^>]*充值)/u', "\n$1", $text);
$text = preg_replace('/\n\s*<\/div>\s*\n(\s*<!--[^\n]*recharge|<!--[^\n]*充值)/ui', "\n$1", $text);
// Chinese comment variant in file
$text = preg_replace('/\r?\n\s*<\/div>\s*\r?\n(\s*<!--\s*涓汉涓績锛氬厖)/u', "\n$1", $text);
file_put_contents($base . '/profile-subpages.php', $text);
echo "profile-subpages " . strlen($text) . "\n";

// Fix info pane missing close: after save button should close card+body+pane
// Check structure quickly
if (!str_contains($text, 'id="profileInfoPane"')) {
    echo "WARN missing info pane\n";
}
if (!str_contains($text, 'id="profilePasswordPane"')) {
    echo "WARN missing password pane\n";
}
if (!str_contains($text, 'id="profileRechargePane"')) {
    echo "WARN missing recharge pane\n";
}
