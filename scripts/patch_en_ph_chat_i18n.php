<?php
$file = dirname(__DIR__) . '/public/888/i18n/locales/en-PH.js';
$s = file_get_contents($file);
if (strpos($s, '"tab_bar_messages"') !== false) {
    echo "already patched\n";
    exit(0);
}
$inject = ',"tab_bar_messages":"Messages","home_quick_messages":"✉️ Messages","home_quick_messages_sub":"Support · Groups · Red packets","chat_title":"Messages","chat_empty":"Sign in to connect messaging","chat_empty_no_conv":"No chats yet\\nSupport agents appear here automatically","chat_new_private":"Chat","chat_back":"‹ Back","chat_send":"Send","chat_input_placeholder":"Type a message…","chat_admin_only_hint":"You can only message official support. Groups are created by admin.","chat_admin_welcome":"Hello, I am official support. Feel free to message me anytime."';
$pos = strrpos($s, '};');
if ($pos === false) {
    fwrite(STDERR, "bad file\n");
    exit(1);
}
file_put_contents($file, substr($s, 0, $pos) . $inject . substr($s, $pos));
echo "en-PH patched\n";
