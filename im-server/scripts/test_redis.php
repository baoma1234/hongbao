<?php
require __DIR__ . '/../vendor/autoload.php';

$cfg = require __DIR__ . '/../config/app.php';
$redisCfg = $cfg['redis'];

$candidates = [
    $redisCfg['password'] ?? '',
    'zJ3EkWE47y',
    'redis',
    '123456',
];

foreach ($candidates as $pwd) {
    try {
        $r = new Redis();
        $r->connect($redisCfg['host'], (int)$redisCfg['port'], 2.0);
        if ($pwd !== '') {
            $r->auth($pwd);
        }
        $r->select((int)($redisCfg['db'] ?? 2));
        echo 'OK password=' . ($pwd === '' ? '(empty)' : $pwd) . PHP_EOL;
        echo 'online=' . json_encode($r->sMembers(($redisCfg['prefix'] ?? 'im:') . 'online')) . PHP_EOL;
        echo 'notify_len=' . $r->lLen(($redisCfg['prefix'] ?? 'im:') . 'notify_queue') . PHP_EOL;
        exit(0);
    } catch (Throwable $e) {
        echo 'FAIL pwd=' . ($pwd === '' ? '(empty)' : $pwd) . ' err=' . $e->getMessage() . PHP_EOL;
    }
}
exit(1);
