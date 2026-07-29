<?php
require dirname(__DIR__) . '/im-server/vendor/autoload.php';
$cfg = require dirname(__DIR__) . '/im-server/config/app.php';
if (is_file(dirname(__DIR__) . '/im-server/config/local.php')) {
    $local = require dirname(__DIR__) . '/im-server/config/local.php';
    $cfg = array_replace_recursive($cfg, is_array($local) ? $local : []);
}
\Im\Support\Db::init($cfg['db']);
$svc = new \Im\Service\MessageService();
$ref = new ReflectionClass($svc);
$m = $ref->getMethod('prepareOutgoing');
$m->setAccessible(true);
$cases = [
    ['[流泪]', 6, ['pack' => 'wechat', 'code' => '流泪', 'url' => '/888/stickers/wechat/face/流泪.png']],
    ['[表情]', 6, ['pack' => 'custom', 'code' => '表情0720225219', 'url' => '/uploads/stickers/7/1e2d9dc684c88ef28bd8509738f96144.jpg']],
];
foreach ($cases as $i => $c) {
    try {
        $out = $m->invoke($svc, $c[0], $c[1], $c[2]);
        echo "case{$i} OK type={$out[1]} url={$out[2]['url']}\n";
    } catch (Throwable $e) {
        echo "case{$i} ERR " . $e->getMessage() . "\n";
    }
}
