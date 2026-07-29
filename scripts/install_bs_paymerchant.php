<?php
/**
 * 安装 BS 总商户：添加 config 列、从 runtime/bs.credentials.php 导入、关联现有 BS 通道
 *
 *   php scripts/install_bs_paymerchant.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = dirname(__DIR__);
define('APP_PATH', $root . '/application/');
define('RUNTIME_PATH', $root . '/runtime/');
require $root . '/thinkphp/base.php';
\think\App::initCommon();

use app\common\library\FansHubBsGateway;
use think\Db;

$prefix = \think\Config::get('database.prefix') ?: 'fa_';
$table = $prefix . 'fans_pay_merchant';
$cols = Db::query("SHOW COLUMNS FROM `{$table}` LIKE 'config'");
if (!$cols) {
    Db::execute("ALTER TABLE `{$table}` ADD COLUMN `config` text COMMENT '网关扩展配置JSON' AFTER `platform_public_key`");
    echo "OK add config column\n";
}

$cred = FansHubBsGateway::loadCredentials();
$merchantNo = trim((string)($cred['merchant_id'] ?? $cred['merchant_no'] ?? ''));
if ($merchantNo === '') {
    fwrite(STDERR, "no merchant id in bs.credentials.php\n");
    exit(1);
}

$now = time();
$cfg = json_encode([
    'sign_type'              => strtoupper(trim((string)($cred['sign_type'] ?? 'RSA'))),
    'merchant_key'           => trim((string)($cred['merchant_key'] ?? '')),
    'api_version'            => trim((string)($cred['api_version'] ?? '2.0.0')),
    'callback_currency_code' => trim((string)($cred['callback_currency_code'] ?? 'CNY')),
    'currency_code'          => trim((string)($cred['currency_code'] ?? 'CNY')),
    'recharge_mode'          => trim((string)($cred['recharge_mode'] ?? 'cashier')),
    'cashier_language'       => trim((string)($cred['cashier_language'] ?? 'zh')),
], JSON_UNESCAPED_UNICODE);

$row = Db::name('fans_pay_merchant')->where(['gateway' => 'bs', 'merchant_no' => $merchantNo])->find();
$payload = [
    'name'                => 'BS USDT 主商户',
    'gateway'             => 'bs',
    'merchant_no'         => $merchantNo,
    'private_key'         => trim((string)($cred['private_key'] ?? '')),
    'platform_public_key' => trim((string)($cred['platform_public_key'] ?? '')),
    'config'              => $cfg,
    'site'                => rtrim(trim((string)($cred['site'] ?? '')), '/'),
    'callback_ips'        => '8.217.236.95',
    'status'              => 'normal',
    'updatetime'          => $now,
];
if ($row) {
    Db::name('fans_pay_merchant')->where('id', $row['id'])->update($payload);
    $mid = (int)$row['id'];
    echo "UPDATE bs merchant id={$mid}\n";
} else {
    $payload['remark'] = 'import from bs.credentials.php';
    $payload['createtime'] = $now;
    $mid = (int)Db::name('fans_pay_merchant')->insertGetId($payload);
    echo "CREATE bs merchant id={$mid}\n";
}

$channels = Db::name('fans_pay_channel')->where('handler', 'bs')->select();
foreach ($channels as $ch) {
    Db::name('fans_pay_channel')->where('id', $ch['id'])->update([
        'merchant_id'  => $mid,
        'merchant_no'  => $merchantNo,
        'updatetime'   => $now,
    ]);
    echo "link channel #{$ch['id']} ({$ch['type']})\n";
}

echo "merchant_no={$merchantNo}\n";
echo "后台：财务管理 → 支付总商户 → BS 总商户\n";
