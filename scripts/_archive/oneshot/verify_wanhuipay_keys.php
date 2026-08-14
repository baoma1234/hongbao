<?php
$c = include dirname(__DIR__) . '/runtime/wanhuipay.credentials.php';
$priv = openssl_pkey_get_private($c['private_key']);
$pub = openssl_pkey_get_public($c['platform_public_key']);
echo $priv ? "private_key=OK\n" : ("private_key=FAIL " . openssl_error_string() . "\n");
echo $pub ? "platform_public_key=OK\n" : ("platform_public_key=FAIL " . openssl_error_string() . "\n");
$data = 'amount=1.00&merchant_no=' . $c['merchant_no'];
openssl_sign($data, $sig, $priv, OPENSSL_ALGO_SHA256);
// 平台公钥不能验商户签名；这里只测商户私钥能签
echo $sig ? "sign=OK len=" . strlen(base64_encode($sig)) . "\n" : "sign=FAIL\n";
