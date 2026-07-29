<?php
/**
 * 创建测试充值/提现商户通道
 * php scripts/seed_paychannel_test.php
 */
$root = dirname(__DIR__);
$env = parse_ini_file($root . '/.env', true);
$d = $env['database'];
$pdo = new PDO(
    "mysql:host={$d['hostname']};dbname={$d['database']};charset=utf8mb4",
    $d['username'],
    $d['password'],
    [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4', PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$prefix = $d['prefix'] ?? 'fa_';
$table = $prefix . 'fans_pay_channel';

$host = getenv('SITE_URL') ?: '';
if ($host === '') {
    $host = 'http://127.0.0.1:7111';
}
$host = rtrim($host, '/');

$now = time();
$merchants = [
    [
        'type'         => 'recharge',
        'name'         => '测试充值商户',
        'icon'         => '',
        'tip'          => '测试网关，可模拟支付成功/失败',
        'handler'      => 'merchant',
        'submit_url'   => $host . '/api/pay/testsubmit',
        'merchant_no'  => 'TEST_RC_001',
        'merchant_key' => 'test_recharge_key_2026',
        'pay_type'     => 'alipay',
        'pay_channel'  => 'test_alipay',
        'notify_url'   => '',
        'return_url'   => $host . '/888/#profile',
        'product_name' => '账户充值',
        'config'       => '',
        'min_amount'   => 1,
        'max_amount'   => 50000,
        'weigh'        => 100,
        'status'       => 'normal',
    ],
    [
        'type'         => 'withdraw',
        'name'         => '测试提现商户',
        'icon'         => '',
        'tip'          => '测试网关，可模拟打款成功/失败',
        'handler'      => 'merchant',
        'submit_url'   => $host . '/api/pay/testwithdrawsubmit',
        'merchant_no'  => 'TEST_WD_001',
        'merchant_key' => 'test_withdraw_key_2026',
        'pay_type'     => 'bank',
        'pay_channel'  => 'test_bank',
        'notify_url'   => '',
        'return_url'   => $host . '/888/#profile',
        'product_name' => '账户提现',
        'config'       => '',
        'min_amount'   => 10,
        'max_amount'   => 50000,
        'weigh'        => 100,
        'status'       => 'normal',
    ],
];

foreach ($merchants as $m) {
    $st = $pdo->prepare("SELECT id FROM `{$table}` WHERE handler='merchant' AND type=? AND merchant_no=? LIMIT 1");
    $st->execute([$m['type'], $m['merchant_no']]);
    $id = (int)$st->fetchColumn();
    $m['config'] = json_encode([
        'submit_url'   => $m['submit_url'],
        'merchant_no'  => $m['merchant_no'],
        'merchant_key' => $m['merchant_key'],
        'pay_type'     => $m['pay_type'],
        'pay_channel'  => $m['pay_channel'],
        'notify_url'   => $m['notify_url'],
        'return_url'   => $m['return_url'],
        'product_name' => $m['product_name'],
    ], JSON_UNESCAPED_UNICODE);
    if ($id > 0) {
        $notify = $m['type'] === 'withdraw'
            ? $host . '/api/pay/withdrawnotify?channel_id=' . $id
            : $host . '/api/pay/rechargenotify?channel_id=' . $id;
        $sql = "UPDATE `{$table}` SET name=?, icon=?, tip=?, handler=?, submit_url=?, merchant_no=?, merchant_key=?, pay_type=?, pay_channel=?, notify_url=?, return_url=?, product_name=?, config=?, min_amount=?, max_amount=?, weigh=?, status=?, updatetime=? WHERE id=?";
        $pdo->prepare($sql)->execute([
            $m['name'], $m['icon'], $m['tip'], $m['handler'], $m['submit_url'], $m['merchant_no'], $m['merchant_key'],
            $m['pay_type'], $m['pay_channel'], $notify, $m['return_url'], $m['product_name'], $m['config'],
            $m['min_amount'], $m['max_amount'], $m['weigh'], $m['status'], $now, $id,
        ]);
        echo "UPDATE {$m['type']} channel id={$id} merchant={$m['merchant_no']}\n";
        continue;
    }
    $sql = "INSERT INTO `{$table}` (type,name,icon,tip,handler,submit_url,merchant_no,merchant_key,pay_type,pay_channel,notify_url,return_url,product_name,config,min_amount,max_amount,weigh,status,createtime,updatetime)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    $pdo->prepare($sql)->execute([
        $m['type'], $m['name'], $m['icon'], $m['tip'], $m['handler'], $m['submit_url'], $m['merchant_no'], $m['merchant_key'],
        $m['pay_type'], $m['pay_channel'], $m['notify_url'], $m['return_url'], $m['product_name'], $m['config'],
        $m['min_amount'], $m['max_amount'], $m['weigh'], $m['status'], $now, $now,
    ]);
    $newId = (int)$pdo->lastInsertId();
    $notify = $m['type'] === 'withdraw'
        ? $host . '/api/pay/withdrawnotify?channel_id=' . $newId
        : $host . '/api/pay/rechargenotify?channel_id=' . $newId;
    $pdo->prepare("UPDATE `{$table}` SET notify_url=?, config=? WHERE id=?")->execute([
        $notify,
        json_encode(array_merge(json_decode($m['config'], true), ['notify_url' => $notify]), JSON_UNESCAPED_UNICODE),
        $newId,
    ]);
    echo "INSERT {$m['type']} channel id={$newId} merchant={$m['merchant_no']}\n";
    echo "  notify: {$notify}\n";
}

echo "DONE\n";
