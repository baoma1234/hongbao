<?php
require __DIR__ . '/../im-server/vendor/autoload.php';
require __DIR__ . '/../im-server/App/Support/TronBlockClient.php';
use Im\Support\TronBlockClient;
try {
    $n = TronBlockClient::getNowBlockNum(8);
    $b = TronBlockClient::getBlockByNum($n, 8);
    echo "OK num={$n} blockID=" . substr($b['block_id'], 0, 16) . "... lucky=" . TronBlockClient::luckyFromBlockId($b['block_id']) . PHP_EOL;
} catch (Throwable $e) {
    echo 'FAIL ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
