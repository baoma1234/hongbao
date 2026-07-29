<?php
$f = dirname(__DIR__) . '/public/888/i18n/locales/zh-CN.js';
$s = file_get_contents($f);
if (!preg_match('/window\.FANSHUB_LOCALES\["zh-CN"\]=(.+);?\s*$/s', $s, $m)) {
    fwrite(STDERR, "parse fail\n");
    exit(1);
}
$j = json_decode(rtrim($m[1], "; \r\n"), true);
if (!$j) {
    fwrite(STDERR, json_last_error_msg() . "\n");
    exit(1);
}
$j['tab_bar_messages'] = '消息';
$j['home_quick_messages'] = '✉️ 消息中心';
$j['home_quick_messages_sub'] = '私聊 · 群聊 · 红包';
$j['chat_title'] = '消息';
$j['chat_new_private'] = '私聊';
$j['chat_new_group'] = '建群';
$j['chat_empty'] = '登录后自动连接消息服务';
$j['chat_back'] = '‹ 返回';
$j['chat_input_placeholder'] = '输入消息…';
$j['chat_send'] = '发送';
$out = 'window.FANSHUB_LOCALES["zh-CN"]=' . json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ";\n";
file_put_contents($f, $out);
echo "ok keys=" . count($j) . "\n";
