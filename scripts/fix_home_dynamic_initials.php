<?php
$root = dirname(__DIR__);
$copy = include $root . '/application/extra/fanshub_h5_copy.php';
$home = file_get_contents($root . '/public/888/partials/tab-home.php');

// Fix jackpotPartners initial (dynamic but show clean template)
$partners = $copy['jackpot_partners'] ?? '';
$partners = str_replace(['{partner_count}', '{partner_today_up}'], ['8,000', '0'], $partners);
$home = preg_replace(
    '/(<div class="jackpot-meta" id="jackpotPartners">)(.*?)(<\/div>)/us',
    '$1' . $partners . '$3',
    $home,
    1
);

// Fix share price line if id exists
if (preg_match('/id="jackpotSharePrice"/', $home)) {
    $price = $copy['jackpot_price_line'] ?? '';
    $price = str_replace(['{current_share_price}', '{price_up_pct}'], ['5.00', '0'], $price);
    $home = preg_replace(
        '/(<div[^>]*id="jackpotSharePrice"[^>]*>)(.*?)(<\/div>)/us',
        '$1' . $price . '$3',
        $home,
        1
    );
}

// Open account badge - find garbled span near open account
$oa = $copy['open_account_btn'] ?? '';
$oaBadge = $copy['open_account_badge_fallback'] ?? '';
$oaBadge = str_replace('{open_account_rights}', '2', $oaBadge);
// Replace mojibake open-account related spans if present as plain text nodes
$home = preg_replace_callback(
    '/<(span|a|button)([^>]*)>([^<]*(?:娌℃湁璐﹀彿|鍒版棤|绔嬮€|馃挸)[^<]*)<\/\1>/u',
    function ($m) use ($oa, $oaBadge) {
        $attrs = $m[2];
        // prefer full button text
        if (strpos($attrs, 'open_account') !== false || strpos($attrs, 'OpenAccount') !== false || strpos($attrs, 'btn') !== false) {
            if (!preg_match('/\bdata-copy=/', $attrs)) {
                $attrs .= ' data-copy="open_account_btn"';
            }
            return '<' . $m[1] . $attrs . '>' . $oa . '</' . $m[1] . '>';
        }
        if (!preg_match('/\bdata-copy=/', $attrs)) {
            $attrs .= ' data-copy="open_account_badge_fallback"';
        }
        return '<' . $m[1] . $attrs . '>' . $oaBadge . '</' . $m[1] . '>';
    },
    $home
);

// Fix placeholder attribute for uid if still garbled
$ph = htmlspecialchars($copy['uid_placeholder'], ENT_QUOTES, 'UTF-8');
$home = preg_replace(
    '/(data-copy-placeholder="uid_placeholder"[^>]*placeholder=")([^"]*)(")/u',
    '$1' . $ph . '$3',
    $home,
    1
);
$home = preg_replace(
    '/(placeholder=")([^"]*)("[^>]*data-copy-placeholder="uid_placeholder")/u',
    '$1' . $ph . '$3',
    $home,
    1
);

file_put_contents($root . '/public/888/partials/tab-home.php', $home);
echo "moji_left=" . preg_match_all('/鍏|褰撳|鑲′|鏈|鐢熸|娌℃湁璐/u', $home) . "\n";

// Dump surrounding open account HTML
if (preg_match('/.{0,120}(?:openAccount|open-account|open_account|没有账号|立送).{0,200}/us', $home, $m)) {
    echo "OA_CTX=" . $m[0] . "\n";
} else {
    // search id
    foreach (['btnOpenAccount', 'openAccountBtn', 'openAccountBadge', 'channelOpenHint'] as $id) {
        if (strpos($home, $id) !== false) echo "found_id=$id\n";
    }
    $pos = strpos($home, '555.bio');
    if ($pos) echo substr($home, $pos - 100, 300) . "\n";
}

// Fix exchange avail initial
$ex = file_get_contents($root . '/public/888/partials/tab-exchange.php');
$ex = preg_replace(
    '/(<div class="share-swap-avail" id="shareSwapAvail">)(.*?)(<\/div>)/us',
    '$1' . ($copy['swap_avail'] ?? '可用 —') . '$3',
    $ex,
    1
);
file_put_contents($root . '/public/888/partials/tab-exchange.php', $ex);
echo "exchange_avail_fixed\n";
