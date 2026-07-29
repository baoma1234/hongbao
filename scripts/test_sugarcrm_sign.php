<?php
$cfg = include __DIR__ . '/../application/extra/fanshub.php';
$s = $cfg['sugarcrm_skey'];
echo 'len=' . strlen($s) . PHP_EOL;
echo 'trim_len=' . strlen(trim($s)) . PHP_EOL;
echo 'has_space=' . (preg_match('/\s/', $s) ? 'YES' : 'NO') . PHP_EOL;
echo 'hex=' . bin2hex($s) . PHP_EOL;
echo 'value=[' . $s . ']' . PHP_EOL;

define('APP_PATH', __DIR__ . '/../application/');
define('ROOT_PATH', dirname(__DIR__) . '/');
define('RUNTIME_PATH', dirname(__DIR__) . '/runtime/');
define('EXTEND_PATH', dirname(__DIR__) . '/extend/');
define('VENDOR_PATH', dirname(__DIR__) . '/vendor/');
require dirname(__DIR__) . '/thinkphp/base.php';
\think\App::initCommon();

$d = \app\common\library\SugarCrm::buildSignDataStatic(
    ['playername' => 'fhdx888'],
    trim($s),
    null
);
echo 'sign_base=' . $d['sign_base'] . PHP_EOL;
echo 'sign=' . $d['sign'] . PHP_EOL;

$crm = \app\common\library\SugarCrm::instance();
$res = $crm->getMemberList(['playername' => 'fhdx888'], ['trigger' => 'cli_no_pages']);
echo 'api=' . json_encode($res, JSON_UNESCAPED_UNICODE) . PHP_EOL;
