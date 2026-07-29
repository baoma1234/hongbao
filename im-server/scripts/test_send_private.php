<?php
require __DIR__ . '/../vendor/autoload.php';

$cfg = require __DIR__ . '/../config/app.php';
$key = $cfg['admin_bridge']['key'] ?? 'change-me-im-admin';

$body = json_encode([
    'admin_key' => $key,
    'agent_user_id' => 1,
    'to_user_id' => 7,
    'content' => 'push_test_' . date('His'),
    'msg_type' => 1,
], JSON_UNESCAPED_UNICODE);

$ch = curl_init('http://127.0.0.1:7273/agent/send_private');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 8,
]);
$raw = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo 'code=' . $code . PHP_EOL . $raw . PHP_EOL;
