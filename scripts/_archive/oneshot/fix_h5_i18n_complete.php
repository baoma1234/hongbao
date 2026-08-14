<?php
/**
 * Comprehensive H5 i18n repair:
 * 1) Restore HTML data-copy / placeholder / aria / title / alt fallbacks from fanshub_h5_copy.php
 * 2) Fix syncCopyFromLocale so server zh overrides don't kill other locales
 * 3) Patch chat notice/commission/RP dynamic strings to chatT
 * 4) Seed fanshub.php h5_copy with new keys
 * 5) Add English UI translations for newly exposed keys
 * 6) Regenerate locales + copy.defaults.js
 */
$root = dirname(__DIR__);
mb_internal_encoding('UTF-8');

$copy = include $root . '/application/extra/fanshub_h5_copy.php';
if (!is_array($copy)) {
    fwrite(STDERR, "bad copy\n");
    exit(1);
}

function h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------- 1) Restore HTML fallbacks ----------
function restoreHtmlFallbacks($html, array $copy)
{
    // data-copy text nodes (skip data-copy-html which may contain markup)
    $html = preg_replace_callback(
        '/<(?<tag>[a-zA-Z0-9]+)(?<attrs>[^>]*\bdata-copy="(?<key>[^"]+)"[^>]*)>(?<body>.*?)<\/\k<tag>>/us',
        function ($m) use ($copy) {
            $key = $m['key'];
            if (!isset($copy[$key])) {
                return $m[0];
            }
            if (preg_match('/\bdata-copy-html\s*=\s*"1"/', $m['attrs'])) {
                // keep structure; replace only if body looks mojibake / empty of proper CJK from key
                $val = $copy[$key];
                // If value contains HTML tags, use as innerHTML
                if (preg_match('/<[^>]+>/', $val)) {
                    return '<' . $m['tag'] . $m['attrs'] . '>' . $val . '</' . $m['tag'] . '>';
                }
                return '<' . $m['tag'] . $m['attrs'] . '>' . $val . '</' . $m['tag'] . '>';
            }
            $val = $copy[$key];
            // Preserve nested tags only if original body had child tags and value is plain
            if (preg_match('/<[a-zA-Z]/', $m['body']) && !preg_match('/<[^>]+>/', $val)) {
                // Don't destroy nested structure for complex nodes; only fix if body has mojibake
                if (!preg_match('/鍏|褰撳|鑲′|鏈|鐢熸|浜掑|馃|�/u', $m['body'])) {
                    return $m[0];
                }
            }
            return '<' . $m['tag'] . $m['attrs'] . '>' . $val . '</' . $m['tag'] . '>';
        },
        $html
    );

    // placeholders
    $html = preg_replace_callback(
        '/(\bdata-copy-placeholder="([^"]+)"[^>]*\bplaceholder=")([^"]*)(")/u',
        function ($m) use ($copy) {
            $key = $m[2];
            if (!isset($copy[$key])) return $m[0];
            return $m[1] . h($copy[$key]) . $m[4];
        },
        $html
    );
    $html = preg_replace_callback(
        '/(\bplaceholder=")([^"]*)("[^>]*\bdata-copy-placeholder="([^"]+)")/u',
        function ($m) use ($copy) {
            $key = $m[4];
            if (!isset($copy[$key])) return $m[0];
            return $m[1] . h($copy[$key]) . $m[3];
        },
        $html
    );

    // aria-label
    $html = preg_replace_callback(
        '/(\bdata-copy-aria="([^"]+)"[^>]*\baria-label=")([^"]*)(")/u',
        function ($m) use ($copy) {
            $key = $m[2];
            if (!isset($copy[$key])) return $m[0];
            return $m[1] . h($copy[$key]) . $m[4];
        },
        $html
    );
    $html = preg_replace_callback(
        '/(\baria-label=")([^"]*)("[^>]*\bdata-copy-aria="([^"]+)")/u',
        function ($m) use ($copy) {
            $key = $m[4];
            if (!isset($copy[$key])) return $m[0];
            return $m[1] . h($copy[$key]) . $m[3];
        },
        $html
    );

    // title
    $html = preg_replace_callback(
        '/(\bdata-copy-title="([^"]+)"[^>]*\btitle=")([^"]*)(")/u',
        function ($m) use ($copy) {
            $key = $m[2];
            if (!isset($copy[$key])) return $m[0];
            return $m[1] . h($copy[$key]) . $m[4];
        },
        $html
    );
    $html = preg_replace_callback(
        '/(\btitle=")([^"]*)("[^>]*\bdata-copy-title="([^"]+)")/u',
        function ($m) use ($copy) {
            $key = $m[4];
            if (!isset($copy[$key])) return $m[0];
            return $m[1] . h($copy[$key]) . $m[3];
        },
        $html
    );

    // alt
    $html = preg_replace_callback(
        '/(\bdata-copy-alt="([^"]+)"[^>]*\balt=")([^"]*)(")/u',
        function ($m) use ($copy) {
            $key = $m[2];
            if (!isset($copy[$key])) return $m[0];
            return $m[1] . h($copy[$key]) . $m[4];
        },
        $html
    );
    $html = preg_replace_callback(
        '/(\balt=")([^"]*)("[^>]*\bdata-copy-alt="([^"]+)")/u',
        function ($m) use ($copy) {
            $key = $m[4];
            if (!isset($copy[$key])) return $m[0];
            return $m[1] . h($copy[$key]) . $m[3];
        },
        $html
    );

    return $html;
}

$partials = glob($root . '/public/888/partials/*.php') ?: [];
$htmlFixed = 0;
foreach ($partials as $path) {
    $before = file_get_contents($path);
    $after = restoreHtmlFallbacks($before, $copy);
    // Specific home fixes for dynamic nodes without data-copy that are mojibake
    if (basename($path) === 'tab-home.php') {
        // open account badge spans often injected; ensure label wiring
        if (strpos($after, 'data-copy="open_account_btn"') === false && preg_match('/没有账号|鍒版棤|馃挸 娌/u', $after)) {
            // leave — usually set by JS
        }
    }
    if ($after !== $before) {
        file_put_contents($path, $after);
        $htmlFixed++;
        echo 'html_restored ' . basename($path) . "\n";
    }
}
echo "html_files_fixed=$htmlFixed\n";

// ---------- 2) Fix syncCopyFromLocale ----------
$corePath = $root . '/public/888/js/app-core.js';
$core = file_get_contents($corePath);
$oldSync = <<<'JS'
        function syncCopyFromLocale(serverCopy) {
            if (serverCopy && typeof serverCopy === 'object') {
                lastServerCopy = serverCopy;
            }
            const pack = (window.FanshubI18n && FanshubI18n.currentPack()) || {};
            COPY = Object.assign({}, pack);
            if (lastServerCopy) {
                Object.keys(lastServerCopy).forEach(function(k) {
                    if (lastServerCopy[k] != null && lastServerCopy[k] !== '') {
                        COPY[k] = lastServerCopy[k];
                    }
                });
            }
            COPY_VARS = buildCopyVars({});
        }
JS;
$newSync = <<<'JS'
        function syncCopyFromLocale(serverCopy) {
            if (serverCopy && typeof serverCopy === 'object') {
                lastServerCopy = serverCopy;
            }
            const pack = (window.FanshubI18n && FanshubI18n.currentPack()) || {};
            const defaults = window.FANSHUB_COPY_DEFAULTS || {};
            const locale = (window.FanshubI18n && FanshubI18n.locale) || 'zh-CN';
            const isZh = String(locale).indexOf('zh') === 0;
            // defaults <- locale pack; admin server copy only overrides Chinese source
            COPY = Object.assign({}, defaults, pack);
            if (lastServerCopy) {
                Object.keys(lastServerCopy).forEach(function(k) {
                    var v = lastServerCopy[k];
                    if (v == null || v === '') return;
                    if (isZh) {
                        COPY[k] = v;
                    } else if (!Object.prototype.hasOwnProperty.call(pack, k) || pack[k] === '' || pack[k] == null) {
                        COPY[k] = v;
                    }
                });
            }
            COPY_VARS = buildCopyVars({});
        }
JS;
if (strpos($core, $oldSync) !== false) {
    $core = str_replace($oldSync, $newSync, $core);
    echo "syncCopyFromLocale=patched\n";
} else {
    echo "syncCopyFromLocale=pattern_miss\n";
}

// Replace known mojibake/hardcoded fallbacks in app-core with ASCII-safe fc()-only (remove garbled || '...')
// Safer: only strip obviously mojibake fallbacks after fc()
$core = preg_replace("/\|\|\s*'[^']*(?:鍏|褰撳|鑲′|鏈|鐢熸|浜掑|鍏抽|鏀朵)[^']*'/u", '', $core);
file_put_contents($corePath, $core);
echo "app-core_moji_fallbacks_stripped\n";

// ---------- 3) Patch chat 06-notice.js to use chatT ----------
$noticePath = $root . '/public/888/js/chat/06-notice.js';
$notice = file_get_contents($noticePath);

// Ensure new keys exist
$extraKeys = [
    'chat_commission_nav_promo' => '推广结算',
    'chat_commission_nav_rebate' => '红包返佣',
    'chat_commission_nav_withdraw' => '提现记录',
    'chat_commission_recent' => '最近结算',
    'chat_commission_empty_promo' => '暂无推广结算记录',
    'chat_commission_empty_rebate' => '暂无红包返佣记录',
    'chat_commission_empty_withdraw' => '暂无提现记录',
    'chat_commission_empty_recent' => '暂无结算记录，邀请好友即可获得',
    'chat_commission_login_hint' => '登录后查看佣金明细',
    'chat_notice_loading' => '加载中…',
    'chat_notice_empty_retry' => '暂无公告，稍后再来看看',
    'wallet_module_fail' => '钱包模块加载失败',
    'wallet_open_profile_hint' => '请先打开「我的」使用钱包',
    'chat_rp_sending' => '发送中…',
    'chat_confirm_add' => '确认添加 ({count} 人)',
    'chat_group_members_count' => '{count} 名成员',
    'chat_session' => '会话',
    'chat_private' => '私聊',
    'chat_group_default_name' => '群聊',
    'chat_rp_blessing_default' => '恭喜发财',
    'chat_rp_submit' => '塞钱进红包',
    'chat_no_messages' => '暂无消息，发一句打个招呼吧',
    'chat_no_claims' => '暂无人领取',
    'chat_no_members' => '暂无成员',
    'chat_no_candidates' => '暂无可添加用户',
    'chat_copy_member_id' => '复制会员ID',
    'chat_member_id_prefix' => '会员ID {id}',
    'chat_group_notice_prefix' => '群公告：{notice}',
    'chat_group_notice_empty' => '暂无群公告',
    'chat_role_member' => '成员',
    'chat_member_action_title' => '{name} · 操作',
];
foreach ($extraKeys as $k => $v) {
    if (!isset($copy[$k])) {
        $copy[$k] = $v;
    }
}

$noticeRepls = [
    "if (title) title.textContent = '推广结算';\n      renderCommissionRows(data.promo_recent, '暂无推广结算记录');"
        => "if (title) title.textContent = chatT('chat_commission_nav_promo');\n      renderCommissionRows(data.promo_recent, chatT('chat_commission_empty_promo'));",
    "if (title) title.textContent = '红包返佣';\n      renderCommissionRows(data.rebate_recent, '暂无红包返佣记录');"
        => "if (title) title.textContent = chatT('chat_commission_nav_rebate');\n      renderCommissionRows(data.rebate_recent, chatT('chat_commission_empty_rebate'));",
    "if (title) title.textContent = '提现记录';\n      renderCommissionRows(data.withdraw_recent, '暂无提现记录');"
        => "if (title) title.textContent = chatT('chat_commission_nav_withdraw');\n      renderCommissionRows(data.withdraw_recent, chatT('chat_commission_empty_withdraw'));",
    "if (title) title.textContent = '最近结算';\n      renderCommissionRows(data.recent, '暂无结算记录，邀请好友即可获得');"
        => "if (title) title.textContent = chatT('chat_commission_recent');\n      renderCommissionRows(data.recent, chatT('chat_commission_empty_recent'));",
    "listEl.innerHTML = '<div class=\"chat-empty chat-empty-glass\">登录后查看佣金明细</div>';"
        => "listEl.innerHTML = '<div class=\"chat-empty chat-empty-glass\">' + noticeEscape(chatT('chat_commission_login_hint')) + '</div>';",
    "listEl.innerHTML = '<div class=\"chat-empty chat-empty-glass\">加载中…</div>';"
        => "listEl.innerHTML = '<div class=\"chat-empty chat-empty-glass\">' + noticeEscape(chatT('chat_notice_loading')) + '</div>';",
    "box.innerHTML = '<div class=\"chat-empty chat-empty-glass\">加载中…</div>';"
        => "box.innerHTML = '<div class=\"chat-empty chat-empty-glass\">' + noticeEscape(chatT('chat_notice_loading')) + '</div>';",
    "box.innerHTML = '<div class=\"chat-empty chat-empty-glass\">暂无公告，稍后再来看看</div>';"
        => "box.innerHTML = '<div class=\"chat-empty chat-empty-glass\">' + noticeEscape(chatT('chat_notice_empty_retry')) + '</div>';",
    "showFanshubToast('钱包模块加载失败', 'error');"
        => "showFanshubToast(chatT('wallet_module_fail'), 'error');",
    "showFanshubToast('请先打开「我的」使用钱包', 'info');"
        => "showFanshubToast(chatT('wallet_open_profile_hint'), 'info');",
];
$nOk = 0;
foreach ($noticeRepls as $a => $b) {
    if (strpos($notice, $a) !== false) {
        $notice = str_replace($a, $b, $notice);
        $nOk++;
    } else {
        echo "notice_miss: " . substr($a, 0, 50) . "\n";
    }
}

// Hook notice into onLocaleChange via 05-community wrapper end — patch 04-net onLocaleChange to refresh commission/notice if present
$netPath = $root . '/public/888/js/chat/04-net.js';
$net = file_get_contents($netPath);
$oldOl = <<<'JS'
  function onLocaleChange() {
    updateMoneyLabel();
    refreshConnStatusLabel();
    renderList();
    var balEl = $('chatRpBalance');
    if (balEl && state.money != null && !isNaN(state.money)) {
      balEl.textContent = moneyText(state.money);
    }
  }
JS;
$newOl = <<<'JS'
  function onLocaleChange() {
    updateMoneyLabel();
    refreshConnStatusLabel();
    renderList();
    var balEl = $('chatRpBalance');
    if (balEl && state.money != null && !isNaN(state.money)) {
      balEl.textContent = moneyText(state.money);
    }
    if (typeof setCommissionListMode === 'function') {
      try { setCommissionListMode(state.commissionListMode || 'recent'); } catch (e1) {}
    }
    if (typeof refreshCommissionPanel === 'function') {
      try { refreshCommissionPanel(true); } catch (e2) {}
    }
    if (typeof renderNoticeFeed === 'function') {
      try { renderNoticeFeed(); } catch (e3) {}
    }
  }
JS;
if (strpos($net, $oldOl) !== false) {
    $net = str_replace($oldOl, $newOl, $net);
    file_put_contents($netPath, $net);
    echo "onLocaleChange=patched\n";
} else {
    echo "onLocaleChange=miss\n";
}
file_put_contents($noticePath, $notice);
echo "notice_repls=$nOk\n";

// Patch 03-rp.js / 04-net confirm add / 02-room key strings
$rpPath = $root . '/public/888/js/chat/03-rp.js';
if (is_file($rpPath)) {
    $rp = file_get_contents($rpPath);
    $rpPairs = [
        "blessEl.textContent = t || '恭喜发财';" => "blessEl.textContent = t || chatT('chat_rp_blessing_default');",
        "btn.textContent = '塞钱进红包';" => "btn.textContent = chatT('chat_rp_submit');",
        "submitBtn.textContent = '发送中…';" => "submitBtn.textContent = chatT('chat_rp_sending');",
        "submitBtn.textContent = '塞钱进红包';" => "submitBtn.textContent = chatT('chat_rp_submit');",
    ];
    foreach ($rpPairs as $a => $b) {
        if (strpos($rp, $a) !== false) {
            $rp = str_replace($a, $b, $rp);
            echo "rp_ok\n";
        }
    }
    file_put_contents($rpPath, $rp);
}

$roomPath = $root . '/public/888/js/chat/02-room.js';
if (is_file($roomPath)) {
    $room = file_get_contents($roomPath);
    $roomPairs = [
        "box.innerHTML = '<div class=\"chat-empty\">暂无消息，发一句打个招呼吧</div>';" => "box.innerHTML = '<div class=\"chat-empty\">' + escapeHtml(chatT('chat_no_messages')) + '</div>';",
        "list.innerHTML = '<div class=\"chat-empty\">暂无人领取</div>';" => "list.innerHTML = '<div class=\"chat-empty\">' + escapeHtml(chatT('chat_no_claims')) + '</div>';",
        "box.innerHTML = '<div class=\"chat-empty\">暂无成员</div>';" => "box.innerHTML = '<div class=\"chat-empty\">' + escapeHtml(chatT('chat_no_members')) + '</div>';",
        "box.innerHTML = '<div class=\"chat-empty\">暂无可添加用户</div>';" => "box.innerHTML = '<div class=\"chat-empty\">' + escapeHtml(chatT('chat_no_candidates')) + '</div>';",
        "copyBtn.textContent = '复制会员ID';" => "copyBtn.textContent = chatT('chat_copy_member_id');",
        "titleEl.textContent = state.room.title || (state.room.type === 2 ? '群聊' : '私聊');"
            => "titleEl.textContent = state.room.title || (state.room.type === 2 ? chatT('chat_group_default_name') : chatT('chat_private'));",
    ];
    foreach ($roomPairs as $a => $b) {
        if (strpos($room, $a) !== false) {
            $room = str_replace($a, $b, $room);
            echo "room_ok\n";
        } else {
            echo "room_miss " . substr($a, 0, 40) . "\n";
        }
    }
    // confirm add button patterns
    $room = preg_replace(
        "/btn\.textContent\s*=\s*'确认添加\s*\('\s*\+\s*selectedCount\s*\+\s*'\s*人\)';/",
        "btn.textContent = chatT('chat_confirm_add', { count: selectedCount });",
        $room
    );
    $room = preg_replace(
        "/metaEl\.textContent\s*=\s*cnt\s*\+\s*'\s*名成员'/",
        "metaEl.textContent = chatT('chat_group_members_count', { count: cnt })",
        $room
    );
    file_put_contents($roomPath, $room);
}

// ---------- 4) Save updated copy ----------
function exportPhpArray($arr)
{
    return "<?php\nreturn " . var_export($arr, true) . ";\n";
}
file_put_contents($root . '/application/extra/fanshub_h5_copy.php', exportPhpArray($copy));
echo "copy_total=" . count($copy) . "\n";

// ---------- 5) English translations for UI chrome ----------
$enPath = $root . '/application/extra/i18n/en-PH.php';
$en = is_file($enPath) ? include $enPath : [];
if (!is_array($en)) $en = [];
$enAdd = [
    'footer_line1' => '📊 This platform is the [official 555.bio active-fan reward & promotion survey hall]',
    'footer_line2' => 'Safety pledge: no deposit entry on this board. Shares and dividends are internal activity rewards. Withdrawals are manually approved by the official VIP center and credited to your 555.bio main account. Final interpretation belongs to 555.bio.',
    'footer_line3' => '© 2026 555.bio Open-Marketing Platform. Terms | Compliance',
    'uid_label' => '🔑 Step 1: Enter the account you registered on the 555.bio cash site',
    'uid_placeholder' => 'e.g. 555bio (must use the same phone number), otherwise staff cannot credit it',
    'uid_submit_btn' => 'Submit account for review',
    'uid_hint_idle' => 'Enter a game account (digits or letters). Each account can be submitted once.',
    'uid_hint_pending' => 'Under review — please wait for staff to credit points',
    'settle_title_low' => '🏦 Apply for VIP priority green channel',
    'settle_sub_low' => 'Even if the amount is short, contact VIP support to top up',
    'open_account_btn' => '💳 No account? Tap [555.bio express open] (get {open_account_rights} shares)',
    'open_account_badge_fallback' => 'Get {open_account_rights} market shares',
    'swap_title' => 'Asset exchange',
    'swap_avail' => 'Available —',
    'swap_from_label' => 'From',
    'swap_unit_share' => 'sh',
    'swap_asset_rights' => 'Shares',
    'swap_asset_balance' => 'Dividend',
    'swap_asset_hongbao' => 'Hongbao',
    'swap_all_btn' => 'All',
    'swap_min_hint' => 'Min 1 per swap',
    'swap_to_label' => 'To',
    'swap_rate_label' => 'Rate',
    'swap_est_label' => 'Est. credit',
    'swap_submit' => 'Confirm swap',
    'swap_aria_from' => 'From asset',
    'swap_aria_flip' => 'Flip direction',
    'swap_aria_to' => 'To asset',
    'page_hero_exchange_title' => '⚡ VIP Flash Exchange',
    'page_hero_exchange_sub' => 'Adjust shares · Live estimate · Push withdraw threshold',
    'page_hero_master_title' => '👑 Master Hall · Phase 2',
    'page_hero_master_sub' => 'Honor ladder · 7-day spark blitz · Team revive radar',
    'chat_community_title' => 'Hongbao Community',
    'chat_tab_chat' => 'Chat',
    'chat_tab_community' => 'Groups',
    'chat_tab_notice' => 'Notice',
    'chat_tab_commission' => 'Commission',
    'chat_scan' => 'Scan',
    'chat_cancel' => 'Cancel',
    'chat_community_official' => 'Official groups',
    'chat_friend_list' => 'Friends',
    'chat_my_groups' => 'My groups',
    'chat_notice_latest' => 'Latest',
    'chat_notice_promote' => 'Promote',
    'chat_notice_ads' => 'Ads',
    'chat_notice_rules' => 'Rules',
    'chat_commission_total' => 'Total commission',
    'chat_commission_withdraw_btn' => 'Withdraw',
    'chat_commission_withdrawable' => 'Withdrawable',
    'chat_commission_today' => 'Today',
    'chat_commission_rebate' => 'Packet rebate',
    'chat_commission_nav_promo' => 'Promo settlement',
    'chat_commission_nav_rebate' => 'Packet rebate',
    'chat_commission_nav_ledger' => 'Earnings',
    'chat_commission_nav_withdraw' => 'Withdrawals',
    'chat_commission_recent' => 'Recent settlement',
    'chat_commission_login_hint' => 'Log in to view commission details',
    'chat_commission_empty_promo' => 'No promo settlements yet',
    'chat_commission_empty_rebate' => 'No packet rebates yet',
    'chat_commission_empty_withdraw' => 'No withdrawals yet',
    'chat_commission_empty_recent' => 'No settlements yet — invite friends to earn',
    'chat_rp_title' => 'Send red packet',
    'chat_rp_submit' => 'Pack the money',
    'chat_rp_sending' => 'Sending…',
    'chat_group_settings' => 'Group settings',
    'chat_create_group_title' => 'Create group',
    'profile_vip_badge' => 'Official member',
    'profile_quick_qr' => 'QR code',
    'profile_quick_scan' => 'Scan',
    'profile_quick_recharge' => 'Top up',
    'profile_quick_withdraw' => 'Withdraw',
    'profile_section_asset' => 'Asset services',
    'profile_section_security' => 'Account & security',
    'profile_foot_note' => 'Hongbao Official · Member Center',
    'profile_recharge_title' => 'Top up',
    'profile_withdraw_title' => 'Withdraw',
    'profile_qr_title' => 'My QR code',
    'brand_name' => 'Hongbao',
];
$enBefore = count($en);
$en = array_merge($en, $enAdd);
file_put_contents($enPath, exportPhpArray($en));
echo "en_added=" . (count($en) - $enBefore) . " en_total=" . count($en) . "\n";

// ---------- 6) Seed fanshub.php ----------
$cfgPath = $root . '/application/extra/fanshub.php';
if (is_file($cfgPath)) {
    $cfg = include $cfgPath;
    if (!is_array($cfg)) $cfg = [];
    $saved = isset($cfg['h5_copy']) && is_array($cfg['h5_copy']) ? $cfg['h5_copy'] : [];
    // merge: keep admin overrides, fill missing from defaults
    foreach ($copy as $k => $v) {
        if (!isset($saved[$k]) || $saved[$k] === '' || $saved[$k] === null) {
            $saved[$k] = $v;
        }
    }
    $cfg['h5_copy'] = $saved;
    file_put_contents($cfgPath, exportPhpArray($cfg));
    echo "fanshub_h5_copy=" . count($saved) . "\n";
}

echo "DONE_CORE\n";
