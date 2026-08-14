<?php
/**
 * Add EN translations for home claim/footer/marquee/settle and wire locale refresh.
 */
$root = dirname(__DIR__);

$enPath = $root . '/application/extra/i18n/en-PH.php';
$en = include $enPath;
if (!is_array($en)) $en = [];

$zh = include $root . '/application/extra/fanshub_h5_copy.php';

$enAdd = [
    'uid_label' => '🔑 Step 1: Enter the account you registered on the cash site',
    'uid_placeholder' => 'e.g. 555bio (must use the same phone). Otherwise staff cannot credit it.',
    'uid_submit_btn' => 'Submit account for review',
    'uid_hint_idle' => 'Enter a game account (digits or letters). Each account can be submitted once.',
    'uid_hint_pending' => 'Under review — please wait for staff to credit points',
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
];

// Ensure ZH has settle_sub_high if missing
$zhExtra = [];
if (empty($zh['settle_sub_high'])) {
    $zhExtra['settle_sub_high'] = '已达领取门槛，点击生成 VIP 专属领取密令';
}
if ($zhExtra) {
    $zh = array_merge($zh, $zhExtra);
    file_put_contents($root . '/application/extra/fanshub_h5_copy.php', "<?php\nreturn " . var_export($zh, true) . ";\n");
    echo "zh_extra=" . implode(',', array_keys($zhExtra)) . "\n";
}

$before = count($en);
$en = array_merge($en, $enAdd);
file_put_contents($enPath, "<?php\nreturn " . var_export($en, true) . ";\n");
echo "en_before=$before en_after=" . count($en) . "\n";

// Patch app-core: updateDynamicCopy should refresh settle + uid button
$corePath = $root . '/public/888/js/app-core.js';
$core = file_get_contents($corePath);

// Remove manualSettleTitle/Sub from applyPageCopy skip list so data-copy works as fallback;
// still call updateManualSettleButton for high/low switch.
$oldSkip = "el.id === 'manualSettleTitle' || el.id === 'manualSettleSub' || ";
if (strpos($core, $oldSkip) !== false) {
    $core = str_replace($oldSkip, '', $core);
    echo "skip_list_cleaned=1\n";
} else {
    echo "skip_list_cleaned=0\n";
}

$needle = "            initMarqueeInterval();\n        }";
$inject = "            initMarqueeInterval();\n"
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

if (strpos($core, 'updateManualSettleButton();') === false) {
    if (strpos($core, $needle) !== false) {
        $core = str_replace($needle, $inject, $core);
        echo "dynamic_copy_hooked=1\n";
    } else {
        echo "dynamic_copy_hooked=0\n";
    }
} else {
    echo "dynamic_copy_hooked=already\n";
}

file_put_contents($corePath, $core);

// Improve marquee: for non-zh, never fall back to Chinese server marqueeItems if locale copy exists;
// also if locale marquee equals zh defaults, still allow EN when provided (handled by en pack).
$bootPath = $root . '/public/888/js/app-boot.js';
$boot = file_get_contents($bootPath);
$oldMarquee = <<<'JS'
        function getMarqueeItems() {
            const loc = (window.FanshubI18n && FanshubI18n.locale) || 'zh-CN';
            const fromCopy = parseMarqueeCopyText(fc('marquee_text'));
            // 非中文优先用多语言文案；中文优先用活动配置 marquee_text（接口下发）
            if (loc !== 'zh-CN' && fromCopy.length) return fromCopy;
            if (marqueeItems.length) return marqueeItems;
            if (fromCopy.length) return fromCopy;
            return [fc('marquee_fallback_prefix') + fc('marquee_fallback')];
        }
JS;
$newMarquee = <<<'JS'
        function getMarqueeItems() {
            const loc = (window.FanshubI18n && FanshubI18n.locale) || 'zh-CN';
            const isZh = String(loc).indexOf('zh') === 0;
            const fromCopy = parseMarqueeCopyText(fc('marquee_text'));
            // 非中文：只用多语言文案（避免活动配置中文跑马灯盖住翻译）
            if (!isZh) {
                if (fromCopy.length) return fromCopy;
                return [fc('marquee_fallback_prefix') + fc('marquee_fallback')];
            }
            // 中文：优先活动配置下发，其次文案键
            if (marqueeItems.length) return marqueeItems;
            if (fromCopy.length) return fromCopy;
            return [fc('marquee_fallback_prefix') + fc('marquee_fallback')];
        }
JS;
if (strpos($boot, $oldMarquee) !== false) {
    $boot = str_replace($oldMarquee, $newMarquee, $boot);
    file_put_contents($bootPath, $boot);
    echo "marquee_patched=1\n";
} else {
    echo "marquee_patched=0\n";
}

// Ensure uid placeholder has data-copy-placeholder on home
$home = file_get_contents($root . '/public/888/partials/tab-home.php');
if (strpos($home, 'data-copy-placeholder="uid_placeholder"') === false && strpos($home, 'id="mainStationID"') !== false) {
    $home = preg_replace(
        '/(<input[^>]*id="mainStationID"[^>]*)(>)/u',
        '$1 data-copy-placeholder="uid_placeholder"$2',
        $home,
        1
    );
    file_put_contents($root . '/public/888/partials/tab-home.php', $home);
    echo "uid_ph_wired=1\n";
} else {
    echo "uid_ph_wired=ok\n";
}

echo "DONE\n";
