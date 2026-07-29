<?php
$root = dirname(__DIR__);
define('APP_PATH', $root . '/application/');
define('RUNTIME_PATH', $root . '/runtime/');
require $root . '/thinkphp/base.php';
\think\App::initCommon();

use app\common\library\FansHubBsGateway;
use app\common\library\FansHubPayGateway;
use think\Db;

$cred = FansHubBsGateway::loadCredentials();
$merchantId = trim((string)($cred['merchant_id'] ?? $cred['merchant_no'] ?? ''));
$site = rtrim(trim((string)($cred['site'] ?? '')), '/');
if ($site === '') {
    $site = rtrim((string)\think\Config::get('site.invite_base_url'), '/');
}

$types = [
    'recharge' => [
        'submit_url' => FansHubBsGateway::CASHIER_URL,
        'return_url' => $site !== '' ? $site . '/888/#profile' : FansHubPayGateway::defaultReturnUrl(),
        'notify'     => function ($id) use ($site) {
            return $site !== ''
                ? $site . '/api/pay/rechargenotify?channel_id=' . $id
                : FansHubPayGateway::defaultNotifyUrl($id, 'recharge');
        },
    ],
    'withdraw' => [
        'submit_url' => FansHubBsGateway::REMIT_CREATE_URL,
        'return_url' => '',
        'notify'     => function ($id) use ($site) {
            return $site !== ''
                ? $site . '/api/pay/withdrawnotify?channel_id=' . $id
                : FansHubPayGateway::defaultNotifyUrl($id, 'withdraw');
        },
    ],
];

foreach ($types as $type => $meta) {
    $row = Db::name('fans_pay_channel')->where(['handler' => 'bs', 'type' => $type])->order('id', 'desc')->find();
    if (!$row) {
        fwrite(STDERR, "skip: no bs {$type} channel\n");
        continue;
    }

    $id = (int)$row['id'];
    $cfg = json_decode((string)($row['config'] ?? ''), true);
    if (!is_array($cfg)) {
        $cfg = FansHubBsGateway::defaultChannelPreset($type);
    }
    $cfg['private_key'] = trim((string)($cred['private_key'] ?? ''));
    $cfg['platform_public_key'] = trim((string)($cred['platform_public_key'] ?? ''));
    $cfg['merchant_public_key'] = trim((string)($cred['merchant_public_key'] ?? ''));
    if ($type === 'withdraw') {
        $cfg['withdraw_url'] = FansHubBsGateway::REMIT_CREATE_URL;
    }

    $notify = $meta['notify']($id);
    $update = [
        'merchant_no'  => $merchantId,
        'notify_url'   => $notify,
        'submit_url'   => $meta['submit_url'],
        'config'       => json_encode($cfg, JSON_UNESCAPED_UNICODE),
        'status'       => 'normal',
        'updatetime'   => time(),
    ];
    if ($meta['return_url'] !== '') {
        $update['return_url'] = $meta['return_url'];
    }
    Db::name('fans_pay_channel')->where('id', $id)->update($update);

    echo "OK channel #{$id} ({$type})\n";
    echo "  merchantId={$merchantId}\n";
    echo "  notify_url={$notify}\n";
    echo "  submit_url={$meta['submit_url']}\n";
    echo "  status=normal\n";
}
