<?php
/**
 * 写入临时测试 token 并校验 AuthService
 */
require dirname(__DIR__) . '/vendor/autoload.php';

$cfg = require dirname(__DIR__) . '/config/app.php';
\Im\Support\Db::init($cfg['db']);

$plain = 'im_auth_test_' . bin2hex(random_bytes(8));
$enc = hash_hmac($cfg['auth']['hashalgo'], $plain, $cfg['auth']['key']);
$uid = 2;
$now = time();
$exp = $now + 86400;

\Im\Support\Db::exec(
    'INSERT INTO ' . \Im\Support\Db::table('user_token') . ' (token, user_id, createtime, expiretime) VALUES (?,?,?,?)',
    [$enc, $uid, $now, $exp]
);

$auth = new \Im\Service\AuthService($cfg);
$got = $auth->userIdByToken($plain);
echo "plain={$plain}\n";
echo "enc={$enc}\n";
echo "expect_uid={$uid} got={$got} " . ($got === $uid ? 'OK' : 'FAIL') . "\n";

// cleanup
\Im\Support\Db::exec(
    'DELETE FROM ' . \Im\Support\Db::table('user_token') . ' WHERE token = ?',
    [$enc]
);
