<?php
define('APP_PATH', dirname(__DIR__) . '/application/');
require dirname(__DIR__) . '/thinkphp/base.php';
\think\App::initCommon();

$cfg = \think\Config::get('fanshub') ?: [];
echo "member_level_enabled=" . (!empty($cfg['member_level_enabled']) ? '1' : '0') . "\n";
echo "api_sign_enabled=" . (!empty($cfg['api_sign_enabled']) ? '1' : '0') . "\n";
echo "device_fp_limit_enabled=" . (!empty($cfg['device_fp_limit_enabled']) ? '1' : '0') . "\n";
echo "invite_ip_limit_enabled=" . (!empty($cfg['invite_ip_limit_enabled']) ? '1' : '0') . "\n";
echo "main_uid_verify_enabled=" . (!empty($cfg['main_uid_verify_enabled']) ? '1' : '0') . "\n";
echo "main_uid_verify_local=" . (!empty($cfg['main_uid_verify_local']) ? '1' : '0') . "\n";
echo "main_uid_verify_match_phone=" . (!empty($cfg['main_uid_verify_match_phone']) ? '1' : '0') . "\n";

$pub = \app\common\library\FansHubService::publicConfig();
echo "public api_sign_enabled=" . (!empty($pub['api_sign_enabled']) ? '1' : '0') . "\n";
echo "public api_sign_secret_len=" . strlen($pub['api_sign_secret'] ?? '') . "\n";
echo "public member_level_enabled=" . (!empty($pub['member_level_enabled']) ? '1' : '0') . "\n";

$check = \app\common\library\FansHubService::productionChecklist();
echo "checklist level={$check['level']} fail={$check['counts']['fail']} warn={$check['counts']['warn']}\n";
