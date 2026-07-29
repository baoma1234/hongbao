<?php
require __DIR__ . '/../vendor/autoload.php';

$cfg = require __DIR__ . '/../config/app.php';
Im\Support\Db::init($cfg['db']);
Im\Support\RedisClient::init($cfg['redis']);

$r = Im\Support\RedisClient::conn();
echo "online: " . json_encode($r->sMembers(Im\Support\RedisClient::key('online')), JSON_UNESCAPED_UNICODE) . PHP_EOL;
echo "notify_queue_len: " . $r->lLen(Im\Support\RedisClient::key('notify_queue')) . PHP_EOL;

$workers = $r->sMembers(Im\Support\RedisClient::key('workers'));
echo "workers: " . json_encode($workers) . PHP_EOL;
foreach ($workers ?: [] as $wid) {
    $alive = $r->exists(Im\Support\RedisClient::key('worker:' . $wid . ':alive'));
    $pushLen = $r->lLen(Im\Support\RedisClient::key('w:' . $wid . ':push'));
    echo "worker {$wid} alive={$alive} push_queue={$pushLen}" . PHP_EOL;
}

$last = Im\Support\Db::fetch(
    'SELECT id,conversation_type,conversation_id,from_user_id,to_user_id,content,createtime,status'
    . ' FROM ' . Im\Support\Db::table('chat_messages') . ' ORDER BY id DESC LIMIT 3'
);
echo "last_messages: " . json_encode($last, JSON_UNESCAPED_UNICODE) . PHP_EOL;
