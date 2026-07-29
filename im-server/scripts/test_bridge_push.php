<?php
require __DIR__ . '/../vendor/autoload.php';

$cfg = require __DIR__ . '/../config/app.php';
Im\Support\Db::init($cfg['db']);

$row = Im\Support\Db::fetch(
    'SELECT * FROM ' . Im\Support\Db::table('chat_messages') . ' ORDER BY id DESC LIMIT 1'
);
$msg = (new Im\Service\MessageService())->normalizeMessage($row);
$type = (int)$msg['conversation_type'] === 2 ? 'group.message' : 'private.message';

$body = json_encode([
    'admin_key' => $cfg['admin_bridge']['key'] ?? 'change-me-im-admin',
    'type' => $type,
    'message' => $msg,
], JSON_UNESCAPED_UNICODE);

$ch = curl_init('http://127.0.0.1:7273/internal/push');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 5,
]);
$raw = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo 'http_code=' . $code . ' body=' . $raw . PHP_EOL;

$ok = Im\Support\NotifyPublisher::publish($type, $msg, false, $cfg);
echo 'notify_publisher=' . ($ok ? 'ok' : 'fail') . PHP_EOL;
