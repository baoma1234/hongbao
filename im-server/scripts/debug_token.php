<?php
require dirname(__DIR__) . '/vendor/autoload.php';
$cfg = require dirname(__DIR__) . '/config/app.php';
\Im\Support\Db::init($cfg['db']);
$pdo = \Im\Support\Db::pdo();
$cols = $pdo->query('SHOW COLUMNS FROM fa_user_token')->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo $c['Field'] . ' ' . $c['Type'] . PHP_EOL;
}
echo "--- recent ---\n";
$rows = $pdo->query('SELECT token,user_id,expiretime FROM fa_user_token ORDER BY createtime DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
$now = time();
foreach ($rows as $r) {
    echo 'len=' . strlen($r['token']) . ' tok=' . substr($r['token'], 0, 24)
        . ' uid=' . $r['user_id'] . ' exp=' . $r['expiretime'] . ' expired=' . (($r['expiretime'] > 0 && $r['expiretime'] < $now) ? 'Y' : 'N') . PHP_EOL;
}
