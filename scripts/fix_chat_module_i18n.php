<?php
/**
 * Fix 红宝/chat module i18n: EN missing chat_* keys + hardcoded conn status.
 */
$root = dirname(__DIR__);

$zh = include $root . '/application/extra/fanshub_h5_copy.php';
$enPath = $root . '/application/extra/i18n/en-PH.php';
$en = include $enPath;

$zhAdd = [
    'chat_conn_connecting' => '连接中…',
    'chat_conn_authing' => '鉴权中…',
    'chat_conn_auth_fail' => '鉴权失败，请重新登录',
    'chat_conn_not_login' => '未登录',
    'chat_conn_unreachable' => '无法连接 IM',
    'chat_conn_reconnecting' => '已断开，重连中…',
    'chat_conn_error' => '连接异常',
];

$enAdd = [
    'chat_community_title' => 'Hongbao Community',
    'chat_title' => 'Hongbao Community',
    'chat_empty' => 'Sign in to connect messaging',
    'chat_empty_no_conv' => "No conversations yet\nSupport will appear here automatically",
    'chat_tab_chat' => 'Chat',
    'chat_tab_community' => 'Groups',
    'chat_tab_notice' => 'Notices',
    'chat_tab_commission' => 'Commission',
    'chat_recommend_groups' => 'Recommended groups',
    'chat_recommend_empty' => 'No recommended groups',
    'chat_recommend_tag' => 'New group',
    'chat_my_groups' => 'My groups',
    'chat_my_groups_empty' => 'No groups yet',
    'chat_friend_feed' => 'Friend updates',
    'chat_friend_feed_empty' => 'No friend updates',
    'chat_group_online' => '{count} online',
    'chat_group_members' => '{count} members',
    'chat_friend_online' => 'Just now',
    'chat_friend_offline' => 'Away',
    'chat_friend_status_update' => 'Posted an update',
    'chat_new_private' => 'Private chat',
    'chat_new_group' => 'New group',
    'chat_back' => '‹ Back',
    'chat_send' => 'Send',
    'chat_input_placeholder' => 'Type a message…',
    'chat_admin_only_hint' => 'Private chat is only with support; groups are created by staff',
    'chat_admin_welcome' => 'Hi, I’m official support — message me anytime.',
    'chat_conn_ok' => 'Connected',
    'chat_conn_fail' => 'Connection failed',
    'chat_conn_off' => 'Disconnected',
    'chat_conn_connecting' => 'Connecting…',
    'chat_conn_authing' => 'Authenticating…',
    'chat_conn_auth_fail' => 'Auth failed — please sign in again',
    'chat_conn_not_login' => 'Not signed in',
    'chat_conn_unreachable' => 'Cannot reach IM',
    'chat_conn_reconnecting' => 'Disconnected — reconnecting…',
    'chat_conn_error' => 'Connection error',
    'chat_my_id' => 'My ID: {id}',
    'chat_my_id_admin' => 'My ID: {id} · Admin',
    'chat_my_balance' => 'Balance {amount}',
    'chat_my_id_with_balance' => 'My ID: {id} · Balance {amount}',
    'chat_my_id_admin_balance' => 'My ID: {id} · Admin · Balance {amount}',
    'chat_search_placeholder' => 'Search chats / nickname / content',
    'chat_search_empty' => 'No matching chats',
    'chat_cancel' => 'Cancel',
    'chat_scan' => 'Scan',
    'chat_add_friend_btn' => 'Add friend',
    'chat_add_friend_title' => 'Add friend',
    'chat_friend_req_entry' => 'Friend requests',
    'chat_friend_req_title' => 'Friend requests',
    'chat_friend_req_empty' => 'No requests',
    'chat_friend_req_incoming' => 'Received',
    'chat_friend_req_outgoing' => 'Sent',
    'chat_create_group_btn' => 'New group',
    'chat_community_official' => 'Official',
    'chat_friend_list' => 'Friends',
    'chat_notice_latest' => 'Latest',
    'chat_notice_promote' => 'Promote',
    'chat_notice_ads' => 'Ads',
    'chat_notice_rules' => 'Rules',
    'chat_notice_empty' => 'No notices',
    'chat_notice_loading' => 'Loading…',
    'chat_notice_empty_retry' => 'No notices yet — check back later',
    'chat_commission_total' => 'Total commission',
    'chat_commission_withdraw_btn' => 'Withdraw',
    'chat_commission_withdrawable' => 'Withdrawable',
    'chat_commission_today' => 'Today',
    'chat_commission_rebate' => 'Packet rebate',
    'chat_commission_nav_promo' => 'Promo payouts',
    'chat_commission_nav_rebate' => 'Packet rebate',
    'chat_commission_nav_ledger' => 'Earnings',
    'chat_commission_nav_withdraw' => 'Withdrawals',
    'chat_commission_recent' => 'Recent payouts',
    'chat_commission_login_hint' => 'Sign in to view commission',
    'chat_commission_empty_promo' => 'No promo payouts yet',
    'chat_commission_empty_rebate' => 'No packet rebates yet',
    'chat_commission_empty_withdraw' => 'No withdrawals yet',
    'chat_commission_empty_recent' => 'No payouts yet — invite friends to earn',
    'chat_session' => 'Chat',
    'chat_private' => 'Private chat',
    'chat_group_default_name' => 'Group',
    'chat_group_settings' => 'Group settings',
    'chat_group_members_title' => 'Members',
    'chat_add_members_title' => 'Add members',
    'chat_rp_title' => 'Send red packet',
    'chat_qr_scan_hint' => 'Align the QR code inside the frame',
    'chat_qr_pick_album' => 'Choose from album',
    'aria_community_cats' => 'Community categories',
    'aria_notice_cats' => 'Notice categories',
    'aria_search' => 'Search',
    'aria_more' => 'More',
    'aria_back' => 'Back',
];

$zh = array_merge($zh, $zhAdd);
file_put_contents($root . '/application/extra/fanshub_h5_copy.php', "<?php\nreturn " . var_export($zh, true) . ";\n");

// Also fill any other chat_* from zh that still lack EN with a simple pass:
// Prefer explicit enAdd; for remaining chat_* keys keep zh temporarily only if we must —
// better: copy remaining chat_* keys that exist in zh and weren't translated yet using enAdd only for listed.

$en = array_merge($en, $enAdd);

// Auto: for chat_* / aria_* used in messages tab still missing, leave to EN fallback of generate (zh<-en<-locale).
// Ensure all keys we care about are in $en.
file_put_contents($enPath, "<?php\nreturn " . var_export($en, true) . ";\n");
echo "zh=" . count($zh) . " en=" . count($en) . "\n";

// Fix chatT to prefer fc()
$coreChat = $root . '/public/888/js/chat/01-core.js';
$c = file_get_contents($coreChat);
$oldChatT = <<<'JS'
  function chatT(key, extra) {
    var tpl = '';
    var defaults = global.FANSHUB_COPY_DEFAULTS || {};
    if (global.FanshubI18n && typeof global.FanshubI18n.text === 'function') {
      tpl = global.FanshubI18n.text(key, defaults) || '';
    }
    if (!tpl) tpl = defaults[key] || '';
    if (!tpl) return key;
    var vars = extra || {};
    Object.keys(vars).forEach(function (k) {
      tpl = tpl.replace(new RegExp('\\{' + k + '\\}', 'g'), String(vars[k]));
    });
    return tpl;
  }
JS;
$newChatT = <<<'JS'
  function chatT(key, extra) {
    // Prefer fc/COPY (locale + admin merge), same as the rest of H5
    if (typeof global.fc === 'function') {
      var viaFc = global.fc(key, extra || {});
      if (viaFc) return viaFc;
    }
    var tpl = '';
    var defaults = global.FANSHUB_COPY_DEFAULTS || {};
    if (global.FanshubI18n && typeof global.FanshubI18n.text === 'function') {
      tpl = global.FanshubI18n.text(key, defaults) || '';
    }
    if (!tpl) tpl = defaults[key] || '';
    if (!tpl) return key;
    var vars = extra || {};
    Object.keys(vars).forEach(function (k) {
      tpl = tpl.replace(new RegExp('\\{' + k + '\\}', 'g'), String(vars[k]));
    });
    return tpl;
  }
JS;
if (strpos($c, $oldChatT) !== false) {
    $c = str_replace($oldChatT, $newChatT, $c);
    echo "chatT=1\n";
} elseif (strpos($c, 'Prefer fc/COPY') !== false) {
    echo "chatT=already\n";
} else {
    echo "chatT=0\n";
}

$oldRefresh = <<<'JS'
  function refreshConnStatusLabel() {
    if (state.connected) {
      setConnStatus(chatT('chat_conn_ok'), 'ok');
    } else if (state.connecting) {
      setConnStatus(chatT('chat_conn_fail'), '');
    } else {
      setConnStatus(chatT('chat_conn_off'), '');
    }
  }
JS;
$newRefresh = <<<'JS'
  function refreshConnStatusLabel() {
    if (state.connected) {
      setConnStatus(chatT('chat_conn_ok'), 'ok');
    } else if (state.connecting) {
      setConnStatus(chatT('chat_conn_connecting'), '');
    } else {
      setConnStatus(chatT('chat_conn_off'), '');
    }
  }
JS;
if (strpos($c, $oldRefresh) !== false) {
    $c = str_replace($oldRefresh, $newRefresh, $c);
    echo "refresh_conn=1\n";
} else {
    echo "refresh_conn=" . (strpos($c, "chatT('chat_conn_connecting')") !== false ? 'already' : '0') . "\n";
}
file_put_contents($coreChat, $c);

// Patch 04-net.js hardcoded Chinese
$netPath = $root . '/public/888/js/chat/04-net.js';
$net = file_get_contents($netPath);
$repl = [
    "setConnStatus('鉴权失败，请重新登录', 'err');" => "setConnStatus(chatT('chat_conn_auth_fail'), 'err');",
    "setConnStatus('未登录', 'err');" => "setConnStatus(chatT('chat_conn_not_login'), 'err');",
    "setConnStatus('连接中…', '');" => "setConnStatus(chatT('chat_conn_connecting'), '');",
    "setConnStatus('无法连接 IM', 'err');" => "setConnStatus(chatT('chat_conn_unreachable'), 'err');",
    "setConnStatus('鉴权中…', '');" => "setConnStatus(chatT('chat_conn_authing'), '');",
    "setConnStatus('已断开，重连中…', 'err');" => "setConnStatus(chatT('chat_conn_reconnecting'), 'err');",
    "setConnStatus('连接异常', 'err');" => "setConnStatus(chatT('chat_conn_error'), 'err');",
];
$n = 0;
foreach ($repl as $a => $b) {
    if (strpos($net, $a) !== false) {
        $net = str_replace($a, $b, $net);
        $n++;
    }
}
file_put_contents($netPath, $net);
echo "net_hardcoded=$n\n";

// Ensure chatMyId gets refreshed on locale (already in onLocaleChange via updateMoneyLabel)
// Ensure applyPageCopy + FansHubChat.onLocaleChange covers static data-copy (already wired)

$index = file_get_contents($root . '/public/888/index.php');
$index = preg_replace('/\$assetVer\s*=\s*[\'"][^\'"]+[\'"]/', "\$assetVer = '202607252030'", $index, 1);
file_put_contents($root . '/public/888/index.php', $index);
echo "assetVer=202607252030\n";
echo "DONE_WRITE\n";
