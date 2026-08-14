<?php
/**
 * 注入好友申请多语言
 * php scripts/patch_friend_request_i18n.php
 */
$keysZh = [
    'chat_add_friend_hint' => '仅支持通过手机号精确查找；对方通过后才能发消息',
    'chat_add_friend_submit' => '查找并申请',
    'chat_add_friend_confirm' => '向 {name} 发送好友申请？',
    'chat_add_friend_ok' => '已添加好友',
    'chat_friend_req_entry' => '好友申请',
    'chat_friend_req_title' => '好友申请',
    'chat_friend_req_incoming' => '收到的',
    'chat_friend_req_outgoing' => '发出的',
    'chat_friend_req_empty' => '暂无申请',
    'chat_friend_req_sent' => '申请已发送，等待对方通过',
    'chat_friend_req_accept' => '通过',
    'chat_friend_req_reject' => '拒绝',
    'chat_friend_req_cancel' => '取消',
    'chat_friend_req_accepted' => '已通过好友申请',
    'chat_friend_req_fail' => '操作失败',
    'chat_friend_req_status_pending' => '待处理',
    'chat_friend_req_status_accepted' => '已通过',
    'chat_friend_req_status_rejected' => '已拒绝',
    'chat_friend_req_status_cancelled' => '已取消',
    'chat_friend_req_incoming_toast' => '{name} 请求添加你为好友',
    'chat_friend_need_accept' => '对方通过后才能发消息',
];

$keysEn = [
    'chat_add_friend_hint' => 'Search by phone. Messaging unlocks after they accept.',
    'chat_add_friend_submit' => 'Find & Request',
    'chat_add_friend_confirm' => 'Send friend request to {name}?',
    'chat_add_friend_ok' => 'Friend added',
    'chat_friend_req_entry' => 'Friend Requests',
    'chat_friend_req_title' => 'Friend Requests',
    'chat_friend_req_incoming' => 'Received',
    'chat_friend_req_outgoing' => 'Sent',
    'chat_friend_req_empty' => 'No requests',
    'chat_friend_req_sent' => 'Request sent. Waiting for approval.',
    'chat_friend_req_accept' => 'Accept',
    'chat_friend_req_reject' => 'Decline',
    'chat_friend_req_cancel' => 'Cancel',
    'chat_friend_req_accepted' => 'Friend request accepted',
    'chat_friend_req_fail' => 'Action failed',
    'chat_friend_req_status_pending' => 'Pending',
    'chat_friend_req_status_accepted' => 'Accepted',
    'chat_friend_req_status_rejected' => 'Declined',
    'chat_friend_req_status_cancelled' => 'Cancelled',
    'chat_friend_req_incoming_toast' => '{name} sent you a friend request',
    'chat_friend_need_accept' => 'You can message after they accept',
];

$root = dirname(__DIR__);
$filesZh = [
    $root . '/public/888/copy.defaults.js',
    $root . '/public/888/i18n/locales/zh-CN.js',
];
$filesEn = [
    $root . '/public/888/i18n/locales/en-PH.js',
    $root . '/public/888/i18n/locales/id-ID.js',
    $root . '/public/888/i18n/locales/vi-VN.js',
    $root . '/public/888/i18n/locales/ms-MY.js',
    $root . '/public/888/i18n/locales/km-KH.js',
];

function patchKeys($file, $keys) {
    if (!is_file($file)) {
        echo "MISS $file\n";
        return;
    }
    $raw = file_get_contents($file);
    $before = $raw;
    foreach ($keys as $k => $v) {
        $esc = addcslashes($v, "\\\"\n\r");
        if (preg_match('/"' . preg_quote($k, '/') . '"\s*:/', $raw)) {
            $raw = preg_replace(
                '/"' . preg_quote($k, '/') . '"\s*:\s*"(?:\\\\.|[^"\\\\])*"/',
                '"' . $k . '":"' . $esc . '"',
                $raw,
                1
            );
        } else {
            $raw = preg_replace(
                '/("brand_name"\s*:\s*"(?:\\\\.|[^"\\\\])*")/',
                '$1,"' . $k . '":"' . $esc . '"',
                $raw,
                1
            );
        }
    }
    if ($raw !== $before) {
        file_put_contents($file, $raw);
        echo 'patched ' . basename($file) . "\n";
    } else {
        echo 'skip ' . basename($file) . "\n";
    }
}

foreach ($filesZh as $f) {
    patchKeys($f, $keysZh);
}
foreach ($filesEn as $f) {
    patchKeys($f, $keysEn);
}

// rebuild locales.bundle.js from individual locales if possible
$bundle = $root . '/public/888/i18n/locales.bundle.js';
$codes = ['zh-CN', 'en-PH', 'id-ID', 'vi-VN', 'ms-MY', 'km-KH'];
$obj = [];
foreach ($codes as $code) {
    $path = $root . '/public/888/i18n/locales/' . $code . '.js';
    if (!is_file($path)) continue;
    $t = file_get_contents($path);
    if (preg_match('/FANSHUB_LOCALES\["' . preg_quote($code, '/') . '"\]\s*=\s*(\{.*\});/s', $t, $m)) {
        $decoded = json_decode($m[1], true);
        if (is_array($decoded)) {
            $obj[$code] = $decoded;
        }
    } elseif (preg_match('/=\s*(\{.*\});/s', $t, $m)) {
        $decoded = json_decode($m[1], true);
        if (is_array($decoded)) {
            $obj[$code] = $decoded;
        }
    }
}
if ($obj) {
    // Also patch zh into bundle via keys if decode failed for some
    file_put_contents(
        $bundle,
        'window.FANSHUB_LOCALES = ' . json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';'
    );
    echo "patched locales.bundle.js\n";
} else {
    patchKeys($bundle, $keysZh);
}

echo "done\n";
