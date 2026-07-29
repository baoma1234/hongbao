<?php
/**
 * BS USDT 收银台充值通道（默认配置，凭证稍后写入 runtime/bs.credentials.php）
 *
 *   php scripts/seed_bs_paychannel.php
 *   php scripts/seed_bs_paychannel.php --recharge-only
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

use app\common\library\FansHubBsGateway;
use app\common\library\FansHubPayGateway;
use think\Db;

$rechargeOnly = !in_array('--with-withdraw', $argv ?? [], true);
$cred = FansHubBsGateway::loadCredentials();
$merchantId = trim((string)($cred['merchant_id'] ?? $cred['merchant_no'] ?? ''));
$now = time();
$preset = FansHubBsGateway::defaultChannelPreset('recharge');

$cfg = array_merge($preset, [
    'private_key'         => trim((string)($cred['private_key'] ?? '')),
    'platform_public_key' => trim((string)($cred['platform_public_key'] ?? '')),
    'merchant_key'        => trim((string)($cred['merchant_key'] ?? '')),
    'sign_type'           => strtoupper(trim((string)($cred['sign_type'] ?? 'RSA'))),
    'submit_url'          => FansHubBsGateway::CASHIER_URL,
]);

$types = $rechargeOnly
    ? ['recharge' => 'BS USDT 收银台充值']
    : ['recharge' => 'BS USDT 收银台充值', 'withdraw' => 'BS USDT 代付'];

foreach ($types as $type => $name) {
    $rowCfg = $cfg;
    if ($type === 'withdraw') {
        $rowCfg = array_merge(FansHubBsGateway::defaultChannelPreset('withdraw'), $rowCfg);
    }

    $exists = Db::name('fans_pay_channel')
        ->where(['handler' => 'bs', 'type' => $type, 'pay_channel' => 'USDT_TRC20'])
        ->find();

    $payload = [
        'name'         => $name,
        'tip'          => 'BS 必胜 USDT 收银台',
        'handler'      => 'bs',
        'merchant_no'  => $merchantId,
        'merchant_key' => trim((string)($cred['merchant_key'] ?? '')),
        'pay_channel'  => 'USDT_TRC20',
        'submit_url'   => $type === 'recharge' ? FansHubBsGateway::CASHIER_URL : FansHubBsGateway::REMIT_CREATE_URL,
        'return_url'   => FansHubPayGateway::defaultReturnUrl(),
        'product_name' => $type === 'recharge' ? '账户充值' : '账户提现',
        'config'       => json_encode($rowCfg, JSON_UNESCAPED_UNICODE),
        'min_amount'   => 10,
        'max_amount'   => 50000,
        'weigh'        => 85,
        'status'       => 'hidden',
        'updatetime'   => $now,
    ];

    if ($exists) {
        Db::name('fans_pay_channel')->where('id', $exists['id'])->update($payload);
        $id = (int)$exists['id'];
        echo "UPDATE {$type} channel id={$id}\n";
    } else {
        $payload['type'] = $type;
        $payload['icon'] = '';
        $payload['createtime'] = $now;
        $id = (int)Db::name('fans_pay_channel')->insertGetId($payload);
        echo "CREATE {$type} channel id={$id}\n";
    }

    $notify = FansHubPayGateway::defaultNotifyUrl($id, $type);
    Db::name('fans_pay_channel')->where('id', $id)->update(['notify_url' => $notify, 'updatetime' => time()]);
    echo "       notify_url={$notify}\n";
}

echo "\n收银台网关: " . FansHubBsGateway::CASHIER_URL . "\n";
echo "回调 IP: 8.217.236.95\n";
if ($merchantId === '') {
    echo "merchantId: （未配置，请填写 runtime/bs.credentials.php 或后台通道）\n";
} else {
    echo "merchantId: {$merchantId}\n";
}
echo "凭证模板: runtime/bs.credentials.php.example\n";
echo "配置好后将通道状态改为「显示」即可测试。\n";
