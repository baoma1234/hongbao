<?php
require dirname(__DIR__) . '/im-server/vendor/autoload.php';
$c = require dirname(__DIR__) . '/im-server/config/app.php';
\Im\Support\Db::init($c['db']);
$rows = \Im\Support\Db::fetchAll('SELECT id,user_id FROM fa_fans_account ORDER BY id DESC LIMIT 15');
$same = 0;
$diff = 0;
foreach ($rows as $r) {
    $s = ((int)$r['id'] === (int)$r['user_id']);
    if ($s) {
        $same++;
    } else {
        $diff++;
    }
    echo $r['id'] . ' vs ' . $r['user_id'] . ($s ? " SAME\n" : " DIFF\n");
}
echo "same={$same} diff={$diff}\n";
