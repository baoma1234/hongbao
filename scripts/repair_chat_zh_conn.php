<?php
/**
 * Repair garbled ZH conn keys + regenerate locales (opcache-safe).
 */
$root = dirname(__DIR__);

function u($s) {
    return json_decode('"' . $s . '"');
}

$zhPath = $root . '/application/extra/fanshub_h5_copy.php';
$zh = include $zhPath;

$zhFix = [
    'chat_conn_connecting' => u('\u8fde\u63a5\u4e2d\u2026'),
    'chat_conn_authing' => u('\u9274\u6743\u4e2d\u2026'),
    'chat_conn_auth_fail' => u('\u9274\u6743\u5931\u8d25\uff0c\u8bf7\u91cd\u65b0\u767b\u5f55'),
    'chat_conn_not_login' => u('\u672a\u767b\u5f55'),
    'chat_conn_unreachable' => u('\u65e0\u6cd5\u8fde\u63a5 IM'),
    'chat_conn_reconnecting' => u('\u5df2\u65ad\u5f00\uff0c\u91cd\u8fde\u4e2d\u2026'),
    'chat_conn_error' => u('\u8fde\u63a5\u5f02\u5e38'),
];

$zh = array_merge($zh, $zhFix);
$out = "<?php\nreturn " . var_export($zh, true) . ";\n";
file_put_contents($zhPath, $out);
echo "zh=" . count($zh) . " connecting=" . $zh['chat_conn_connecting'] . "\n";

$enPath = $root . '/application/extra/i18n/en-PH.php';
$en = include $enPath;
echo "en=" . count($en) . " title=" . ($en['chat_community_title'] ?? 'MISS') . "\n";

// Inline generate without relying on require of generate script's include cache issues —
// call via shell with opcache off instead.
