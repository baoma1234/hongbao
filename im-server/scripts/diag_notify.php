<?php
require __DIR__ . '/../vendor/autoload.php';

$cfg = require __DIR__ . '/../config/app.php';
Im\Support\Db::init($cfg['db']);
Im\Support\RedisClient::init($cfg['redis']);

echo "=== IM notify diagnostic ===\n";
echo 'redis: ' . json_encode($cfg['redis'], JSON_UNESCAPED_UNICODE) . "\n";

try {
    $r = Im\Support\RedisClient::conn();
    echo "redis_connect: ok\n";
    $key = Im\Support\RedisClient::key('notify_queue');
    echo "notify_queue_len: " . $r->lLen($key) . "\n";
    $online = $r->sMembers(Im\Support\RedisClient::key('online'));
    echo 'online_users: ' . json_encode($online) . "\n";
} catch (Throwable $e) {
    echo 'redis_error: ' . $e->getMessage() . "\n";
}

$last = Im\Support\Db::fetch(
    'SELECT * FROM ' . Im\Support\Db::table('chat_messages') . ' ORDER BY id DESC LIMIT 1'
);
if ($last) {
    echo "last_message_id: {$last['id']} type={$last['conversation_type']} from={$last['from_user_id']} to={$last['to_user_id']}\n";
}

$agents = Im\Support\Db::fetchAll(
    'SELECT user_id,label FROM ' . Im\Support\Db::table('chat_agent_accounts') . ' WHERE status=1'
);
echo 'agents: ' . json_encode($agents, JSON_UNESCAPED_UNICODE) . "\n";

$messages = new Im\Service\MessageService();
$readReady = (new ReflectionMethod($messages, 'readTableReady')) ;
$readReady->setAccessible(true);
echo 'read_table_ready: ' . ($readReady->invoke($messages) ? 'yes' : 'no') . "\n";

if ($last && (int)$last['conversation_type'] === 1) {
    $to = (int)$last['to_user_id'];
    $from = (int)$last['from_user_id'];
    foreach ([$to, $from] as $uid) {
        if ($uid <= 0) continue;
        $conv = (string)$last['conversation_id'];
        $unread = $messages->countUnread($uid, 1, $conv);
        echo "unread uid={$uid} conv={$conv}: {$unread}\n";
    }
}

// test publish + local ws
if ($last) {
    $msg = $messages->normalizeMessage($last);
    $ok = Im\Support\NotifyPublisher::publish('private.message', $msg, false, $cfg);
    echo 'test_publish: ' . ($ok ? 'ok' : 'fail') . "\n";
    try {
        $r = Im\Support\RedisClient::conn();
        echo 'notify_queue_len_after: ' . $r->lLen(Im\Support\RedisClient::key('notify_queue')) . "\n";
    } catch (Throwable $e) {}
}
