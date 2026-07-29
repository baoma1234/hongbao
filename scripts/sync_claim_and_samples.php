<?php
$root = dirname(__DIR__);
$copy = include $root . '/application/extra/fanshub_h5_copy.php';
$home = file_get_contents($root . '/public/888/partials/tab-home.php');
$want = $copy['page_hero_claim_sub'];
preg_match('/data-copy="page_hero_claim_sub"[^>]*>([^<]*)</u', $home, $m);
$got = $m[1] ?? '';
echo "want=$want\n";
echo "got=$got\n";
echo "want_hex=" . bin2hex($want) . "\n";
echo "got_hex=" . bin2hex($got) . "\n";
echo "match=" . ($want === $got ? '1' : '0') . "\n";

if ($want !== $got) {
    $home = preg_replace(
        '/(<[^>]*\bdata-copy="page_hero_claim_sub"[^>]*>)(.*?)(<\/[^>]+>)/us',
        '$1' . addcslashes($want, '\\$') . '$3',
        $home,
        1
    );
    file_put_contents($root . '/public/888/partials/tab-home.php', $home);
    echo "fixed_claim_sub\n";
}

// Also ensure master / profile sample keys look good in HTML
foreach (['tab-master.php' => ['page_hero_master_title','page_hero_master_sub','master_lock_title'], 'tab-exchange.php' => ['swap_title','page_hero_exchange_title'], 'tab-messages.php' => ['chat_community_title','chat_tab_commission']] as $file => $keys) {
    $html = file_get_contents($root . '/public/888/partials/' . $file);
    foreach ($keys as $k) {
        if (!isset($copy[$k])) continue;
        if (preg_match('/data-copy="' . preg_quote($k, '/') . '"[^>]*>([^<]*)</u', $html, $mm)) {
            $ok = ($mm[1] === $copy[$k]) || (strpos($mm[1], '鍏') === false && strpos($mm[1], "\xEF\xBF\xBD") === false);
            echo "$file $k ok=" . ($mm[1] === $copy[$k] ? 'exact' : ($ok ? 'clean' : 'bad')) . " html=" . $mm[1] . "\n";
            if ($mm[1] !== $copy[$k]) {
                $html = preg_replace(
                    '/(<[^>]*\bdata-copy="' . preg_quote($k, '/') . '"[^>]*>)(.*?)(<\/[^>]+>)/us',
                    '$1' . addcslashes($copy[$k], '\\$') . '$3',
                    $html,
                    1
                );
            }
        }
    }
    file_put_contents($root . '/public/888/partials/' . $file, $html);
}
