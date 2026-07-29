<?php
ob_start();
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/index.php/api/chatfair/fair?packet_no=RP_X';
$_SERVER['PATH_INFO'] = 'api/chatfair/fair';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = realpath(__DIR__ . '/../public/index.php');
$_GET['s'] = '/api/chatfair/fair';
$_GET['packet_no'] = 'RP_X';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

chdir(__DIR__ . '/../public');
define('APP_PATH', __DIR__ . '/../application/');

try {
    require __DIR__ . '/../thinkphp/start.php';
} catch (Throwable $e) {
    echo "EX: " . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n";
}
$out = ob_get_clean();
echo "OUTLEN=" . strlen($out) . "\n";
echo substr($out, 0, 500) . "\n";
