<?php
/**
 * Fix share-swap i18n: JS hardcoded Chinese was overwriting data-copy.
 */
$root = dirname(__DIR__);

$zhAdd = [
    'swap_title_pair' => '{from}兑换{to}',
    'swap_from_with_asset' => '转出{asset}',
    'swap_avail_balance' => '可用 {amount}',
    'swap_avail_hongbao' => '可用 {amount} 红宝',
    'swap_avail_rights' => '可用 {amount} 份',
    'swap_est_shares' => '{amount} 份',
    'swap_pair_closed' => '{from}兑换{to}已关闭',
    'swap_rate_line' => '1 {from} = {rate} {to}',
    'profile_ex_max_hint' => '单笔上限 {max}',
    'profile_ex_min_max_hint' => '单次最低 {min}，上限 {max}',
    'swap_unit_hongbao' => '宝',
    'swap_unit_balance' => '¥',
];

$enAdd = [
    'swap_title' => 'Asset exchange',
    'swap_avail' => 'Available —',
    'swap_from_label' => 'From',
    'swap_to_label' => 'To',
    'swap_unit_share' => 'sh',
    'swap_unit_hongbao' => 'HB',
    'swap_unit_balance' => '¥',
    'swap_asset_rights' => 'Shares',
    'swap_asset_balance' => 'Dividend',
    'swap_asset_hongbao' => 'Hongbao',
    'swap_all_btn' => 'All',
    'swap_min_hint' => 'Min 1 per swap',
    'swap_rate_label' => 'Rate',
    'swap_est_label' => 'Est. credit',
    'swap_submit' => 'Confirm swap',
    'swap_aria_from' => 'From asset',
    'swap_aria_flip' => 'Flip direction',
    'swap_aria_to' => 'To asset',
    'swap_title_pair' => '{from} → {to}',
    'swap_from_with_asset' => 'From {asset}',
    'swap_avail_balance' => 'Available {amount}',
    'swap_avail_hongbao' => 'Available {amount} Hongbao',
    'swap_avail_rights' => 'Available {amount} shares',
    'swap_est_shares' => '{amount} sh',
    'swap_pair_closed' => '{from} → {to} is closed',
    'swap_rate_line' => '1 {from} = {rate} {to}',
    'profile_ex_min_hint' => 'Min {min}',
    'profile_ex_max_hint' => 'Max {max} per swap',
    'profile_ex_min_max_hint' => 'Min {min} · max {max}',
    'share_swap_rights_locked_hint' => 'Tradable {free} (locked {locked}, available tomorrow)',
    'asset_hongbao_label' => 'Hongbao',
    'asset_shares_label' => 'Shares',
];

$zhPath = $root . '/application/extra/fanshub_h5_copy.php';
$zh = include $zhPath;
$zh = array_merge($zh, $zhAdd);
file_put_contents($zhPath, "<?php\nreturn " . var_export($zh, true) . ";\n");
echo "zh_keys=" . count($zh) . "\n";

$enPath = $root . '/application/extra/i18n/en-PH.php';
$en = include $enPath;
$en = array_merge($en, $enAdd);
file_put_contents($enPath, "<?php\nreturn " . var_export($en, true) . ";\n");
echo "en_keys=" . count($en) . "\n";

// Patch HTML: wire avail initial
$exPath = $root . '/public/888/partials/tab-exchange.php';
$ex = file_get_contents($exPath);
if (strpos($ex, 'id="shareSwapAvail"') !== false && strpos($ex, 'data-copy="swap_avail"') === false) {
    $ex = str_replace(
        '<div class="share-swap-avail" id="shareSwapAvail">可用 —</div>',
        '<div class="share-swap-avail" id="shareSwapAvail" data-copy="swap_avail">可用 —</div>',
        $ex
    );
    file_put_contents($exPath, $ex);
    echo "avail_data_copy=1\n";
} else {
    echo "avail_data_copy=ok\n";
}

$corePath = $root . '/public/888/js/app-core.js';
$core = file_get_contents($corePath);

$oldLabel = <<<'JS'
        function shareSwapAssetLabel(asset) {
            if (asset === 'balance') return fc('asset_balance_label') || '红利';
            if (asset === 'hongbao') return fc('asset_hongbao_label') || '红宝';
            return fc('asset_shares_label') || '股份';
        }

        function shareSwapAssetIcon(asset) {
            if (asset === 'balance') return '¥';
            if (asset === 'hongbao') return '宝';
            return '股';
        }
JS;

$newLabel = <<<'JS'
        function shareSwapAssetLabel(asset) {
            if (asset === 'balance') return fc('swap_asset_balance') || fc('asset_balance_label') || '红利';
            if (asset === 'hongbao') return fc('swap_asset_hongbao') || fc('asset_hongbao_label') || '红宝';
            return fc('swap_asset_rights') || fc('asset_shares_label') || '股份';
        }

        function shareSwapAssetIcon(asset) {
            if (asset === 'balance') return fc('swap_unit_balance') || '¥';
            if (asset === 'hongbao') return fc('swap_unit_hongbao') || '宝';
            return fc('swap_unit_share') || '股';
        }
JS;

if (strpos($core, $oldLabel) !== false) {
    $core = str_replace($oldLabel, $newLabel, $core);
    echo "label_icon=1\n";
} elseif (strpos($core, "fc('swap_asset_balance')") !== false) {
    echo "label_icon=already\n";
} else {
    echo "label_icon=0\n";
}

$oldUiBlock = <<<'JS'
            const titleEl = document.getElementById('shareSwapTitle');
            if (titleEl) titleEl.textContent = shareSwapAssetLabel(shareSwapFrom) + '兑换' + shareSwapAssetLabel(shareSwapTo);
            const fromLabel = document.getElementById('shareSwapFromLabel');
            if (fromLabel) fromLabel.textContent = '转出' + shareSwapAssetLabel(shareSwapFrom);

            const avail = shareSwapAvail(shareSwapFrom);
            const availEl = document.getElementById('shareSwapAvail');
            if (availEl) {
                if (shareSwapFrom === 'balance') availEl.textContent = '可用 ' + currency + avail.toFixed(2);
                else if (shareSwapFrom === 'hongbao') availEl.textContent = '可用 ' + avail.toFixed(2) + ' 红宝';
                else {
                    const locked = Math.max(0, Number(account.rights_locked) || 0);
                    if (locked > 0) {
                        availEl.textContent = fc('share_swap_rights_locked_hint', {
                            free: Math.floor(avail),
                            locked: Math.ceil(locked)
                        }) || ('可兑 ' + Math.floor(avail) + ' 份（锁定 ' + Math.ceil(locked) + ' 份，次日可兑）');
                    } else {
                        availEl.textContent = '可用 ' + Math.floor(avail) + ' 份';
                    }
                }
            }
JS;

$newUiBlock = <<<'JS'
            const titleEl = document.getElementById('shareSwapTitle');
            if (titleEl) {
                titleEl.textContent = fc('swap_title_pair', {
                    from: shareSwapAssetLabel(shareSwapFrom),
                    to: shareSwapAssetLabel(shareSwapTo)
                }) || (shareSwapAssetLabel(shareSwapFrom) + '兑换' + shareSwapAssetLabel(shareSwapTo));
            }
            const fromLabel = document.getElementById('shareSwapFromLabel');
            if (fromLabel) {
                fromLabel.textContent = fc('swap_from_with_asset', {
                    asset: shareSwapAssetLabel(shareSwapFrom)
                }) || ('转出' + shareSwapAssetLabel(shareSwapFrom));
            }

            const avail = shareSwapAvail(shareSwapFrom);
            const availEl = document.getElementById('shareSwapAvail');
            if (availEl) {
                if (shareSwapFrom === 'balance') {
                    availEl.textContent = fc('swap_avail_balance', { amount: currency + avail.toFixed(2) })
                        || ('可用 ' + currency + avail.toFixed(2));
                } else if (shareSwapFrom === 'hongbao') {
                    availEl.textContent = fc('swap_avail_hongbao', { amount: avail.toFixed(2) })
                        || ('可用 ' + avail.toFixed(2) + ' 红宝');
                } else {
                    const locked = Math.max(0, Number(account.rights_locked) || 0);
                    if (locked > 0) {
                        availEl.textContent = fc('share_swap_rights_locked_hint', {
                            free: Math.floor(avail),
                            locked: Math.ceil(locked)
                        }) || ('可兑 ' + Math.floor(avail) + ' 份（锁定 ' + Math.ceil(locked) + ' 份，次日可兑）');
                    } else {
                        availEl.textContent = fc('swap_avail_rights', { amount: Math.floor(avail) })
                            || ('可用 ' + Math.floor(avail) + ' 份');
                    }
                }
            }
JS;

if (strpos($core, $oldUiBlock) !== false) {
    $core = str_replace($oldUiBlock, $newUiBlock, $core);
    echo "ui_block=1\n";
} elseif (strpos($core, "fc('swap_title_pair'") !== false) {
    echo "ui_block=already\n";
} else {
    echo "ui_block=0\n";
}

$oldHint = <<<'JS'
            const hintEl = document.getElementById('shareSwapHint');
            if (hintEl) {
                if (shareSwapIsBhPair(shareSwapFrom, shareSwapTo)) {
                    hintEl.textContent = fc('profile_ex_min_hint', { min: min }) || ('单次最低 ' + min + '，上限 ' + max);
                } else {
                    hintEl.textContent = fc('profile_ex_max_hint', { max: max }) || ('单笔上限 ' + max);
                }
            }
JS;

$newHint = <<<'JS'
            const hintEl = document.getElementById('shareSwapHint');
            if (hintEl) {
                if (shareSwapIsBhPair(shareSwapFrom, shareSwapTo)) {
                    hintEl.textContent = fc('profile_ex_min_max_hint', { min: min, max: max })
                        || fc('profile_ex_min_hint', { min: min })
                        || ('单次最低 ' + min + '，上限 ' + max);
                } else {
                    hintEl.textContent = fc('profile_ex_max_hint', { max: max }) || ('单笔上限 ' + max);
                }
            }
JS;

if (strpos($core, $oldHint) !== false) {
    $core = str_replace($oldHint, $newHint, $core);
    echo "hint=1\n";
} elseif (strpos($core, "fc('profile_ex_min_max_hint'") !== false) {
    echo "hint=already\n";
} else {
    echo "hint=0\n";
}

$oldRate = <<<'JS'
            const rateEl = document.getElementById('shareSwapRate');
            if (rateEl) {
                rateEl.textContent = '1 ' + shareSwapAssetLabel(shareSwapFrom) + ' = ' +
                    (fromUnit / toUnit).toFixed(4) + ' ' + shareSwapAssetLabel(shareSwapTo);
            }
JS;

$newRate = <<<'JS'
            const rateEl = document.getElementById('shareSwapRate');
            if (rateEl) {
                rateEl.textContent = fc('swap_rate_line', {
                    from: shareSwapAssetLabel(shareSwapFrom),
                    to: shareSwapAssetLabel(shareSwapTo),
                    rate: (fromUnit / toUnit).toFixed(4)
                }) || ('1 ' + shareSwapAssetLabel(shareSwapFrom) + ' = ' +
                    (fromUnit / toUnit).toFixed(4) + ' ' + shareSwapAssetLabel(shareSwapTo));
            }
JS;

if (strpos($core, $oldRate) !== false) {
    $core = str_replace($oldRate, $newRate, $core);
    echo "rate=1\n";
} else {
    echo "rate=" . (strpos($core, "fc('swap_rate_line'") !== false ? 'already' : '0') . "\n";
}

$oldEst = <<<'JS'
            if (estEl) {
                if (shareSwapTo === 'balance') estEl.textContent = currency + credit.toFixed(2);
                else if (shareSwapTo === 'hongbao') estEl.textContent = credit.toFixed(2);
                else estEl.textContent = credit.toFixed(2) + ' 份';
            }

            const submitBtn = document.getElementById('shareSwapSubmitBtn');
            if (submitBtn) {
                submitBtn.disabled = !pair.enabled;
                submitBtn.textContent = '确认兑换';
            }
            const closedEl = document.getElementById('shareSwapClosed');
            if (closedEl) {
                if (pair.enabled) {
                    closedEl.style.display = 'none';
                } else {
                    closedEl.style.display = 'block';
                    closedEl.textContent = shareSwapAssetLabel(shareSwapFrom) + '兑换' + shareSwapAssetLabel(shareSwapTo) + '已关闭';
                }
            }
JS;

$newEst = <<<'JS'
            if (estEl) {
                if (shareSwapTo === 'balance') estEl.textContent = currency + credit.toFixed(2);
                else if (shareSwapTo === 'hongbao') estEl.textContent = credit.toFixed(2);
                else estEl.textContent = fc('swap_est_shares', { amount: credit.toFixed(2) })
                    || (credit.toFixed(2) + ' 份');
            }

            const submitBtn = document.getElementById('shareSwapSubmitBtn');
            if (submitBtn) {
                submitBtn.disabled = !pair.enabled;
                submitBtn.textContent = fc('swap_submit') || '确认兑换';
            }
            const closedEl = document.getElementById('shareSwapClosed');
            if (closedEl) {
                if (pair.enabled) {
                    closedEl.style.display = 'none';
                } else {
                    closedEl.style.display = 'block';
                    closedEl.textContent = fc('swap_pair_closed', {
                        from: shareSwapAssetLabel(shareSwapFrom),
                        to: shareSwapAssetLabel(shareSwapTo)
                    }) || (shareSwapAssetLabel(shareSwapFrom) + '兑换' + shareSwapAssetLabel(shareSwapTo) + '已关闭');
                }
            }
JS;

if (strpos($core, $oldEst) !== false) {
    $core = str_replace($oldEst, $newEst, $core);
    echo "est_submit=1\n";
} else {
    echo "est_submit=" . (strpos($core, "fc('swap_submit')") !== false && strpos($core, "submitBtn.textContent = fc('swap_submit')") !== false ? 'already' : '0') . "\n";
}

// Hook refresh into updateDynamicCopy
$marker = '/* share-swap-i18n-refresh */';
if (strpos($core, $marker) === false) {
    $needle = "            /* home-i18n-refresh */\n";
    $inject = "            /* home-i18n-refresh */\n"
        . "            " . $marker . "\n"
        . "            if (typeof refreshProfileExchangeUi === 'function') {\n"
        . "                try { refreshProfileExchangeUi({ preserveAmount: true }); } catch (eSwap) {}\n"
        . "            }\n";
    if (strpos($core, $needle) !== false) {
        $core = str_replace($needle, $inject, $core);
        echo "dynamic_hook=1\n";
    } else {
        // fallback: append before end of updateDynamicCopy after uid hint
        $alt = "            if (typeof updateUidStatusHint === 'function') {\n"
            . "                try { updateUidStatusHint(typeof account !== 'undefined' ? account : null); } catch (e3) {}\n"
            . "            }\n"
            . "        }";
        $altInject = "            if (typeof updateUidStatusHint === 'function') {\n"
            . "                try { updateUidStatusHint(typeof account !== 'undefined' ? account : null); } catch (e3) {}\n"
            . "            }\n"
            . "            " . $marker . "\n"
            . "            if (typeof refreshProfileExchangeUi === 'function') {\n"
            . "                try { refreshProfileExchangeUi({ preserveAmount: true }); } catch (eSwap) {}\n"
            . "            }\n"
            . "        }";
        if (strpos($core, $alt) !== false) {
            $core = str_replace($alt, $altInject, $core);
            echo "dynamic_hook=1alt\n";
        } else {
            echo "dynamic_hook=0\n";
        }
    }
} else {
    echo "dynamic_hook=already\n";
}

file_put_contents($corePath, $core);

$index = file_get_contents($root . '/public/888/index.php');
$index = preg_replace('/\$assetVer\s*=\s*[\'"][^\'"]+[\'"]/', "\$assetVer = '202607252000'", $index, 1);
file_put_contents($root . '/public/888/index.php', $index);
echo "assetVer=202607252000\n";

require $root . '/scripts/generate_i18n_locales.php';

// verify
$js = file_get_contents($root . '/public/888/i18n/locales/en-PH.js');
foreach (['swap_title_pair', 'swap_avail_balance', 'swap_submit', 'swap_from_with_asset', 'profile_ex_max_hint'] as $k) {
    $ok = preg_match('/"' . preg_quote($k, '/') . '":"([^"]*)"/', $js, $m);
    $v = $ok ? $m[1] : 'MISS';
    $zh = preg_match('/[\x{4e00}-\x{9fff}]/u', $v) ? 'ZH' : 'OK';
    echo "en $k|$zh|$v\n";
}
echo 'core_has_pair=' . (strpos(file_get_contents($corePath), "fc('swap_title_pair'") !== false ? '1' : '0') . "\n";
echo "DONE\n";
