<?php
/**
 * 种子：确保总商户存在，并创建指定钱包的充+提通道
 *
 *   php scripts/seed_wanhuipay_channel.php [--site=https://域名] [--channel=Bobi]
 *   php scripts/seed_wanhuipay_channel.php --all
 *
 * 凭证优先：DB 总商户 → runtime/wanhuipay.credentials.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = dirname(__DIR__);
define('APP_PATH', $root . '/application/');
define('ROOT_PATH', $root . DIRECTORY_SEPARATOR);
define('RUNTIME_PATH', $root . '/runtime/');
define('EXTEND_PATH', $root . '/extend/');
define('VENDOR_PATH', $root . '/vendor/');
define('CONF_PATH', APP_PATH); // think5

require $root . '/thinkphp/base.php';

// 启动应用以初始化 Db 配置
\think\App::initCommon();

use app\common\library\FansHubWanhuitongGateway;
use think\Db;

function argvOpt($name, $default = '')
{
    global $argv;
    foreach ($argv as $a) {
        if ($a === '--' . $name) {
            return '1';
        }
        if (strpos($a, '--' . $name . '=') === 0) {
            return substr($a, strlen($name) + 3);
        }
    }
    return $default;
}

$credFile = $root . '/runtime/wanhuipay.credentials.php';
$cred = is_file($credFile) ? include $credFile : [];
if (!is_array($cred)) {
    $cred = [];
}

$site = rtrim(argvOpt('site', $cred['site'] ?? ''), '/');
$only = argvOpt('channel', $cred['payment_channel'] ?? 'Bobi');
$all = argvOpt('all', '') === '1';

$mch = trim((string)($cred['merchant_no'] ?? ''));
$priv = trim((string)($cred['private_key'] ?? ''));
$pub = trim((string)($cred['platform_public_key'] ?? ''));

$merchant = Db::name('fans_pay_merchant')->where('gateway', 'wanhuitong')->order('id', 'asc')->find();
$now = time();
if ($merchant) {
    $upd = ['updatetime' => $now];
    if ($mch !== '') {
        $upd['merchant_no'] = $mch;
    }
    if ($priv !== '') {
        $upd['private_key'] = $priv;
    }
    if ($pub !== '') {
        $upd['platform_public_key'] = $pub;
    }
    if ($site !== '') {
        $upd['site'] = $site;
    }
    Db::name('fans_pay_merchant')->where('id', $merchant['id'])->update($upd);
    $merchant = Db::name('fans_pay_merchant')->where('id', $merchant['id'])->find();
    echo "merchant #{$merchant['id']} {$merchant['merchant_no']}\n";
} else {
    if ($mch === '' || $priv === '' || $pub === '') {
        fwrite(STDERR, "no merchant in DB and credentials incomplete\n");
        exit(1);
    }
    $id = Db::name('fans_pay_merchant')->insertGetId([
        'name'                => '万汇通主商户',
        'gateway'             => 'wanhuitong',
        'merchant_no'         => $mch,
        'private_key'         => $priv,
        'platform_public_key' => $pub,
        'site'                => $site,
        'callback_ips'        => '18.162.71.242,95.40.141.160',
        'status'              => 'normal',
        'remark'              => 'seed',
        'createtime'          => $now,
        'updatetime'          => $now,
    ]);
    $merchant = Db::name('fans_pay_merchant')->where('id', $id)->find();
    echo "created merchant #{$id}\n";
}

$wallets = $all ? array_keys(FansHubWanhuitongGateway::paymentChannels()) : [$only];
foreach ($wallets as $code) {
    $code = trim((string)$code);
    if ($code === '') {
        continue;
    }
    foreach (['recharge', 'withdraw'] as $type) {
        $status = ($code === 'Bobi') ? 'normal' : 'hidden';
        $r = FansHubWanhuitongGateway::ensureWalletChannel($merchant, $code, $type, [
            'status' => $status,
        ]);
        echo ($r['created'] ? 'NEW' : 'SKIP') . " #{$r['id']} {$type} {$code}\n";
    }
}

echo "done. merchant_id={$merchant['id']}\n";
if (empty($merchant['site'])) {
    echo "warn: 总商户未填 site（公网域名），notify_url 可能不是绝对地址\n";
}
