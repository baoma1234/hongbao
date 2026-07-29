<?php
/**
 * 自测万汇通 RSA2 签名串与验签（不依赖外网）
 * 用法: php scripts/test_wanhuitong_rsa2.php
 */
require dirname(__DIR__) . '/thinkphp/base.php';

use app\common\library\FansHubWanhuitongGateway;

$config = [
    'digest_alg'       => 'sha256',
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
];
$res = openssl_pkey_new($config);
if (!$res) {
    fwrite(STDERR, "openssl_pkey_new failed\n");
    exit(1);
}
openssl_pkey_export($res, $privPem);
$details = openssl_pkey_get_details($res);
$pubPem = $details['key'];

$params = [
    'merchant_no'       => 'M1000012345',
    'amount'            => '50.00',
    'payment_channel'   => 'Bobi',
    'timestamp'         => '2025-12-06 14:30:00',
    'merchant_order_no' => 'ORDER_20251206143000123',
    'nonce_str'         => 'aB3fG9xK2mN8pQ7r',
    'notify_url'        => 'https://api.merchant.com/notify',
    'attach'            => '',
    'sign_type'         => 'RSA2',
    'extra'             => json_encode(['user_id' => 189], JSON_UNESCAPED_UNICODE),
];

$signString = FansHubWanhuitongGateway::buildSignString($params);
echo "sign_string=\n{$signString}\n\n";

$sign = FansHubWanhuitongGateway::sign($params, $privPem);
echo "sign=" . substr($sign, 0, 40) . "...\n";

$params['sign'] = $sign;
$ok = FansHubWanhuitongGateway::verify($params, $pubPem);
echo $ok ? "VERIFY_OK\n" : "VERIFY_FAIL\n";
exit($ok ? 0 : 2);
