<?php
/**
 * 同步好友申请等缺失的 H5 文案到其它语言包 + 导出 copy.defaults.js
 * php scripts/sync_friend_req_i18n.php
 */
$root = dirname(__DIR__);
$app = $root . DIRECTORY_SEPARATOR . 'application';

$keysEn = [
    'chat_friend_req_sent' => 'Request sent. Waiting for approval.',
    'chat_friend_req_accept' => 'Accept',
    'chat_friend_req_reject' => 'Decline',
    'chat_friend_req_cancel' => 'Cancel',
    'chat_friend_req_accepted' => 'Friend request accepted',
    'chat_friend_req_status_pending' => 'Pending',
    'chat_friend_req_status_accepted' => 'Accepted',
    'chat_friend_req_status_rejected' => 'Declined',
    'chat_friend_req_status_cancelled' => 'Cancelled',
    'chat_friend_req_incoming_toast' => '{name} sent you a friend request',
    'chat_friend_need_accept' => 'You can message after they accept',
    'chat_friend_title' => 'Friend {id}',
    'chat_friend_req_fail' => 'Action failed',
];

foreach (['id-ID.php', 'vi-VN.php', 'ms-MY.php', 'km-KH.php'] as $f) {
    $path = $app . DIRECTORY_SEPARATOR . 'extra' . DIRECTORY_SEPARATOR . 'i18n' . DIRECTORY_SEPARATOR . $f;
    if (!is_file($path)) {
        echo "MISS $f\n";
        continue;
    }
    $data = include $path;
    if (!is_array($data)) {
        echo "BAD $f\n";
        continue;
    }
    $changed = false;
    foreach ($keysEn as $k => $v) {
        if (!array_key_exists($k, $data) || $data[$k] === '' || $data[$k] === $k) {
            $data[$k] = $v;
            $changed = true;
        }
    }
    if (!$changed) {
        echo "skip $f\n";
        continue;
    }
    file_put_contents($path, "<?php\n\nreturn " . var_export($data, true) . ";\n");
    echo "patched $f\n";
}

$copy = include $app . DIRECTORY_SEPARATOR . 'extra' . DIRECTORY_SEPARATOR . 'fanshub_h5_copy.php';
if (!is_array($copy)) {
    fwrite(STDERR, "bad fanshub_h5_copy.php\n");
    exit(1);
}
$js = 'window.FANSHUB_COPY_DEFAULTS=' . json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';';
foreach (['888', 'fanshub', 'fanshubtest'] as $dir) {
    $path = $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR . 'copy.defaults.js';
    if (!is_dir(dirname($path))) {
        continue;
    }
    file_put_contents($path, $js);
    echo "wrote $dir/copy.defaults.js keys=" . count($copy) . "\n";
}

passthru disabled — run patch_friend_request_i18n.php separately if needed
echo "done\n";
