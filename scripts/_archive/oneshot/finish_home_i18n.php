<?php
/**
 * Finish home claim/footer/marquee i18n:
 * - enrich EN keys
 * - EN fallback for other locales on generate
 * - hook settle/uid refresh into updateDynamicCopy
 * - bump assetVer
 */
$root = dirname(__DIR__);

$enPath = $root . '/application/extra/i18n/en-PH.php';
$en = include $enPath;
if (!is_array($en)) {
    $en = [];
}

$enAdd = [
    'uid_label' => '🔑 Step 1: Enter the account you registered on the cash site',
    'uid_placeholder' => 'e.g. 555bio (must use the same phone). Otherwise staff cannot credit it.',
    'uid_submit_btn' => 'Submit account for review',
    'uid_hint_idle' => 'Enter a game account (digits or letters). Each account can be submitted once.',
    'uid_hint_pending' => 'Under review — please wait for staff to credit points',
    'uid_hint_approved' => 'Game account verified — locked',
    'uid_hint_rejected' => 'Review failed',
    'uid_submit_pending' => 'Under review…',
    'uid_submit_approved' => 'Verified',
    'settle_title_low' => '🏦 Apply for VIP priority green channel',
    'settle_sub_low' => 'Even if the amount is short, contact VIP support to top up',
    'settle_title_high' => '🛡️ Secure payout channel · VIP final claim',
    'settle_sub_high' => 'Threshold reached — tap to generate your VIP claim code',
    'footer_line1' => '📊 This platform is the [official active-fan reward & promotion survey hall]',
    'footer_line2' => 'Safety pledge: no deposit entry on this board. Shares and dividends are internal activity rewards. Withdrawals are manually approved by the official VIP center and credited to your main account. Final interpretation belongs to the platform.',
    'footer_line3' => '© 2026 Open-Marketing Platform. Terms | Compliance',
    'marquee_text' => "Core team · Squad A cashed 80 shares 3 mins ago and claimed 400.00 official dividend via VIP support!\nUser 555_vip99 just unlocked express open + 3 invites and claimed 50.00 dividend after game turnover clearance.\nRetail trader just exercised 15 shares via Channel A and received 75.00 instantly through official secure chat.",
    'marquee_fallback' => 'Welcome to the official rewards hall',
    'marquee_fallback_prefix' => '🎉 ',
    'page_hero_claim_title' => '🏦 VIP Claim Desk',
    'page_hero_claim_sub' => 'Fill game account → submit review → generate code → contact support to credit',
    'page_hero_social_title' => '💬 Social Hall',
    'page_hero_social_sub' => 'Rankings · posts · vibe — focus on invites & shareouts',
    'leaderboard_title' => '🏆 Invite fission TOP10',
    'leaderboard_loading' => 'Loading…',
    'home_quick_exchange' => '⚡ Flash Exchange',
    'home_quick_exchange_sub' => 'Shares → dividend balance',
    'home_quick_master' => '👑 Master Hall',
    'home_quick_master_sub' => 'Ladder + 7-day spark burst',
    'home_quick_messages' => 'Community',
    'home_quick_messages_sub' => 'DM · Group · Red packets',
    'home_quick_profile' => '👤 Profile',
    'home_quick_profile_sub' => 'Info · Password · Logout',
];

$en = array_merge($en, $enAdd);
file_put_contents($enPath, "<?php\nreturn " . var_export($en, true) . ";\n");
echo "en_keys=" . count($en) . "\n";

// Patch generate: missing keys in id/vi/ms/km fall back to EN (not ZH)
$genPath = $root . '/scripts/generate_i18n_locales.php';
$gen = file_get_contents($genPath);
$oldLoop = <<<'PHP'
foreach ($localeFiles as $code => $file) {
    $path = $i18nDir . $file;
    if (!is_file($path)) {
        fwrite(STDERR, "skip missing locale: $file\n");
        continue;
    }
    $data = include $path;
    if (!is_array($data)) {
        fwrite(STDERR, "invalid locale file: $file\n");
        continue;
    }
    $locales[$code] = array_merge($zh, $data);
    if ($code === 'en-PH') {
        $fallback = $locales[$code];
    }
}

if ($fallback) {
    foreach (array_keys($localeFiles) as $code) {
        if ($code === 'en-PH' || isset($locales[$code])) {
            continue;
        }
        $locales[$code] = $fallback;
    }
}
PHP;

$newLoop = <<<'PHP'
$rawLocales = [];
foreach ($localeFiles as $code => $file) {
    $path = $i18nDir . $file;
    if (!is_file($path)) {
        fwrite(STDERR, "skip missing locale: $file\n");
        continue;
    }
    $data = include $path;
    if (!is_array($data)) {
        fwrite(STDERR, "invalid locale file: $file\n");
        continue;
    }
    $rawLocales[$code] = $data;
}

// EN first: zh defaults <- EN overrides
if (isset($rawLocales['en-PH'])) {
    $fallback = array_merge($zh, $rawLocales['en-PH']);
    $locales['en-PH'] = $fallback;
}

foreach ($rawLocales as $code => $data) {
    if ($code === 'en-PH') {
        continue;
    }
    // Other langs: zh <- EN fallback <- locale overrides (missing keys show EN, not Chinese)
    $locales[$code] = array_merge($zh, $fallback ?: [], $data);
}

if ($fallback) {
    foreach (array_keys($localeFiles) as $code) {
        if (isset($locales[$code])) {
            continue;
        }
        $locales[$code] = $fallback;
    }
}
PHP;

if (strpos($gen, $oldLoop) !== false) {
    $gen = str_replace($oldLoop, $newLoop, $gen);
    file_put_contents($genPath, $gen);
    echo "generate_fallback_patched=1\n";
} elseif (strpos($gen, 'Other langs: zh <- EN fallback') !== false) {
    echo "generate_fallback_patched=already\n";
} else {
    echo "generate_fallback_patched=0\n";
}

// Hook updateDynamicCopy (only inside that function — prior script matched elsewhere)
$corePath = $root . '/public/888/js/app-core.js';
$core = file_get_contents($corePath);

$hookMarker = '/* home-i18n-refresh */';
if (strpos($core, $hookMarker) === false) {
    $needle = "            updateBalanceProgress();\n            syncClaimPageEcho();\n            initMarqueeInterval();\n        }";
    $inject = "            updateBalanceProgress();\n            syncClaimPageEcho();\n            initMarqueeInterval();\n"
        . "            " . $hookMarker . "\n"
        . "            if (typeof updateManualSettleButton === 'function') {\n"
        . "                try { updateManualSettleButton(); } catch (e1) {}\n"
        . "            }\n"
        . "            if (typeof updateUidSubmitButton === 'function') {\n"
        . "                try { updateUidSubmitButton(); } catch (e2) {}\n"
        . "            }\n"
        . "            if (typeof updateUidStatusHint === 'function') {\n"
        . "                try { updateUidStatusHint(typeof account !== 'undefined' ? account : null); } catch (e3) {}\n"
        . "            }\n"
        . "        }";
    if (strpos($core, $needle) !== false) {
        $core = str_replace($needle, $inject, $core);
        file_put_contents($corePath, $core);
        echo "dynamic_hook=1\n";
    } else {
        echo "dynamic_hook=0 needle_missing\n";
        // show nearby
        $pos = strpos($core, 'initMarqueeInterval();');
        echo "initMarquee_pos=" . ($pos === false ? 'no' : $pos) . "\n";
    }
} else {
    echo "dynamic_hook=already\n";
}

// bump assetVer
$indexPath = $root . '/public/888/index.php';
$index = file_get_contents($indexPath);
$newVer = '202607251945';
if (preg_match('/\$assetVer\s*=\s*[\'"][^\'"]+[\'"]/', $index)) {
    $index = preg_replace('/\$assetVer\s*=\s*[\'"][^\'"]+[\'"]/', "\$assetVer = '{$newVer}'", $index, 1);
    file_put_contents($indexPath, $index);
    echo "assetVer={$newVer}\n";
}

require $genPath;

// verify EN js
$js = file_get_contents($root . '/public/888/i18n/locales/en-PH.js');
$pos = strpos($js, '{');
$end = strrpos($js, '}');
$data = json_decode(substr($js, $pos, $end - $pos + 1), true);
foreach (['uid_label', 'footer_line1', 'settle_title_low', 'marquee_text', 'leaderboard_title'] as $k) {
    $v = $data[$k] ?? 'MISS';
    $zh = preg_match('/[\x{4e00}-\x{9fff}]/u', $v) ? 'ZH' : 'OK';
    echo "check $k $zh " . mb_substr($v, 0, 40) . "\n";
}

// verify id falls back to EN for uid_label
$jsId = file_get_contents($root . '/public/888/i18n/locales/id-ID.js');
$pos = strpos($jsId, '{');
$end = strrpos($jsId, '}');
$idData = json_decode(substr($jsId, $pos, $end - $pos + 1), true);
$v = $idData['uid_label'] ?? 'MISS';
echo 'id_uid_fallback=' . (preg_match('/[\x{4e00}-\x{9fff}]/u', $v) ? 'ZH' : 'EN_OK') . ' ' . mb_substr($v, 0, 40) . "\n";

echo "DONE\n";
