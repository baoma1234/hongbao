<?php
require __DIR__ . '/../vendor/autoload.php';

$cfg = require __DIR__ . '/../config/app.php';
Im\Support\Db::init($cfg['db']);

$row = Im\Support\Db::fetch(
    'SELECT * FROM ' . Im\Support\Db::table('chat_messages') . ' ORDER BY id DESC LIMIT 1'
);
if (!$row) {
    echo "no messages\n";
    exit(1);
}

$msg = (new Im\Service\MessageService())->normalizeMessage($row);
$type = (int)$msg['conversation_type'] === 2 ? 'group.message' : 'private.message';
$ok = Im\Support\LocalWsPush::notify($type, $msg, false, $cfg);
echo $ok ? "local_ws_push_ok\n" : "local_ws_push_fail\n";
