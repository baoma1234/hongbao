<?php
$root = dirname(__DIR__);
$copy = include $root . '/application/extra/fanshub_h5_copy.php';
$home = file_get_contents($root . '/public/888/partials/tab-home.php');

// Extract newbieOpenPanel block
if (!preg_match('/<div class="user-panel" id="newbieOpenPanel">.*?<\/div>\s*<\/div>/s', $home, $m)) {
    // broader
    $pos = strpos($home, 'id="newbieOpenPanel"');
    echo "panel_pos=$pos\n";
    echo bin2hex(substr($home, $pos, 400)) . "\n";
    file_put_contents($root . '/scripts/_oa_panel_snip.txt', substr($home, $pos, 500));
} else {
    file_put_contents($root . '/scripts/_oa_panel_snip.txt', $m[0]);
}

$badge = str_replace('{open_account_rights}', '2', $copy['open_account_badge_fallback']);
$btn = str_replace('{open_account_rights}', '2', $copy['open_account_btn']);

// Rebuild the open account button block cleanly
$block = <<<HTML
            <div class="user-panel" id="newbieOpenPanel">
                <button type="button" class="cta-open-account" id="openAccountBtn" onclick="goToMainStation()">
                    <span class="cta-open-account-label" id="openAccountBtnLabel" data-copy="open_account_badge_fallback">{$badge}</span>
                    <span class="cta-open-account-text" id="openAccountBtnText" data-copy="open_account_btn">{$btn}</span>
                </button>
            </div>
HTML;

// Try replace existing broken panel
if (preg_match('/<div class="user-panel" id="newbieOpenPanel">[\s\S]*?<\/button>\s*<\/div>/u', $home)) {
    $home = preg_replace(
        '/<div class="user-panel" id="newbieOpenPanel">[\s\S]*?<\/button>\s*<\/div>/u',
        trim($block),
        $home,
        1
    );
    echo "panel_replaced=1\n";
} else {
    echo "panel_replace_miss\n";
    // dump nearby
    $pos = strpos($home, 'newbieOpenPanel');
    file_put_contents($root . '/scripts/_oa_panel_raw.txt', substr($home, $pos, 600));
}

file_put_contents($root . '/public/888/partials/tab-home.php', $home);

// verify
if (preg_match('/id="openAccountBtnLabel"[^>]*>([^<]*)</u', $home, $mm)) {
    echo "label=" . $mm[1] . "\n";
}
if (preg_match('/id="openAccountBtnText"[^>]*>([^<]*)</u', $home, $mm)) {
    echo "text=" . $mm[1] . "\n";
} else {
    echo "text_missing_check_structure\n";
    $pos = strpos($home, 'openAccountBtn');
    echo substr($home, $pos, 350) . "\n";
}
