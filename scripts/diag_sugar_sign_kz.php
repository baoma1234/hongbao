<?php
$key = '9eT8zVu3z1ubzUPxkpFlDuZpX6A1q42';

$cases = [
    'with_pages_doc_order' => 'playername=fhdx888&pages=1&pageLength=10&sKey=' . $key,
    'with_pages_only'      => 'playername=fhdx888&pages=1&pageLength=10&sKey=' . $key,
    'no_pages'             => 'playername=fhdx888&sKey=' . $key,
    'ksort'                => 'pageLength=10&pages=1&playername=fhdx888&sKey=' . $key,
];

$expectWith = '6df47d40052c55e487092859d1c81d28';
$expectNo   = null; // compute e4fa from KZ mask

foreach ($cases as $label => $str) {
    $sign = strtolower(md5($str));
    echo $label . PHP_EOL;
    echo '  string=' . $str . PHP_EOL;
    echo '  sign=' . $sign . PHP_EOL;
    echo '  match_6df4=' . ($sign === $expectWith ? 'YES' : 'no') . PHP_EOL;
    echo PHP_EOL;
}

define('APP_PATH', __DIR__ . '/../application/');
define('ROOT_PATH', dirname(__DIR__) . '/');
define('RUNTIME_PATH', dirname(__DIR__) . '/runtime/');
define('EXTEND_PATH', dirname(__DIR__) . '/extend/');
define('VENDOR_PATH', dirname(__DIR__) . '/vendor/');
require dirname(__DIR__) . '/thinkphp/base.php';
\think\App::initCommon();

$d1 = \app\common\library\SugarCrm::buildSignDataStatic(
    ['playername' => 'fhdx888', 'pages' => 1, 'pageLength' => 10],
    $key,
    ['playername', 'pages', 'pageLength']
);
$d2 = \app\common\library\SugarCrm::buildSignDataStatic(
    ['playername' => 'fhdx888'],
    $key,
    ['playername']
);
echo "buildSign with pages: {$d1['sign']} string={$d1['sign_string']}\n";
echo "buildSign no pages:   {$d2['sign']} string={$d2['sign_string']}\n";

$crm = \app\common\library\SugarCrm::instance();
$res = $crm->getMemberList(['playername' => 'fhdx888', 'pages' => 1, 'pageLength' => 10], ['trigger' => 'diag_kz']);
echo 'live_api=' . json_encode($res, JSON_UNESCAPED_UNICODE) . PHP_EOL;
