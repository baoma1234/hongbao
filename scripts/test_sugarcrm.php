<?php
/**
 * 测试 SugarCRM 请求并写入 runtime/log/sugarcrm/
 * php scripts/test_sugarcrm.php [playername]
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = dirname(__DIR__);
define('APP_PATH', $root . '/application/');
define('ROOT_PATH', $root . DIRECTORY_SEPARATOR);
define('RUNTIME_PATH', $root . '/runtime/');
define('EXTEND_PATH', $root . '/extend/');
define('VENDOR_PATH', $root . '/vendor/');
define('CONF_PATH', APP_PATH);

require $root . '/thinkphp/base.php';
\think\App::initCommon();

$playername = $argv[1] ?? 'test';
$crm = \app\common\library\SugarCrm::instance();
$res = $crm->findByPlayername($playername, ['trigger' => 'cli_test', 'user_id' => 0]);
echo 'result: ';
var_export($res);
echo PHP_EOL;
echo 'log: runtime/log/sugarcrm/sugarcrm_' . date('Ymd') . '.log' . PHP_EOL;
