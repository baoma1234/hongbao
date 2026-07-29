<?php
/**
 * 用法: php im-server/scripts/debug_auth.php <明文token>
 */
require dirname(__DIR__) . '/vendor/autoload.php';

$cfg = require dirname(__DIR__) . '/config/app.php';
\Im\Support\Db::init($cfg['db']);

$token = isset($argv[1]) ? trim((string)$argv[1]) : '';
if ($token === '') {
    fwrite(STDERR, "usage: php debug_auth.php <token>\n");
    exit(1);
}

$auth = new \Im\Service\AuthService($cfg);
$uid = $auth->userIdByToken($token);
$algo = $cfg['auth']['hashalgo'] ?? '';
$key = $cfg['auth']['key'] ?? '';
$enc = hash_hmac($algo, $token, $key);

echo "plain_len=" . strlen($token) . "\n";
echo "enc=" . $enc . "\n";
echo "user_id=" . $uid . "\n";

$row = \Im\Support\Db::fetch(
    'SELECT user_id, expiretime FROM ' . \Im\Support\Db::table('user_token') . ' WHERE token = ? LIMIT 1',
    [$enc]
);
echo 'db_hit=' . ($row ? 'Y uid=' . $row['user_id'] : 'N') . "\n";
