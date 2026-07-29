<?php
/**
 * Scan remaining CJK UI strings and dump for patching
 */
$root = dirname(__DIR__);
$files = [
    'public/888/partials/header.php',
    'public/888/partials/tab-home.php',
    'public/888/partials/tab-exchange.php',
    'public/888/partials/tab-profile.php',
    'public/888/partials/profile-subpages.php',
    'public/888/partials/tab-messages.php',
    'public/888/partials/login.php',
    'public/888/partials/tab-master.php',
    'public/888/partials/bottom-and-overlays.php',
    'public/888/partials/modals.php',
];
$copy = include $root . '/application/extra/fanshub_h5_copy.php';
echo 'existing_keys=' . count($copy) . PHP_EOL;

foreach ($files as $rel) {
    $path = $root . '/' . $rel;
    if (!is_file($path)) {
        echo "MISSING $rel\n";
        continue;
    }
    $html = file_get_contents($path);
    $hasDataCopy = substr_count($html, 'data-copy');
    // find text nodes-ish: >...CJK...<
    preg_match_all('/>([^<]*[\x{4e00}-\x{9fff}][^<]*)</u', $html, $m);
    $placeholders = [];
    preg_match_all('/placeholder="([^"]*[\x{4e00}-\x{9fff}][^"]*)"/u', $html, $mp);
    preg_match_all('/aria-label="([^"]*[\x{4e00}-\x{9fff}][^"]*)"/u', $html, $ma);
    $texts = array_values(array_unique(array_map('trim', $m[1])));
    $texts = array_values(array_filter($texts, function ($t) {
        return $t !== '' && !preg_match('/^\{\$/', $t);
    }));
    echo "\n== $rel data-copy=$hasDataCopy cjk_text=" . count($texts) . " ph=" . count($mp[1]) . " ==\n";
    foreach (array_slice($texts, 0, 40) as $t) {
        echo '  T: ' . $t . "\n";
    }
    foreach ($mp[1] as $t) {
        echo '  P: ' . $t . "\n";
    }
}
