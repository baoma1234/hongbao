<?php
/**
 * 大狗短信连通性探测（不写业务库）
 * php scripts/probe_dagou_sms.php
 */
$root = dirname(__DIR__);
$cfg = include $root . '/application/extra/fanshub.php';
$gateway = rtrim((string)($cfg['sms_dagou_gateway'] ?? ''), '/');
$gateway = preg_replace('#/api/sms$#i', '', $gateway);
$gateway = preg_replace('#/api/get_balance$#i', '', $gateway);
$gateway = rtrim((string)$gateway, '/');
$uname = (string)($cfg['sms_dagou_uname'] ?? '');
$apikey = (string)($cfg['sms_dagou_apikey'] ?? '');
$enabled = !empty($cfg['sms_dagou_enabled']);
$mock = !empty($cfg['sms_mock_enabled']);

function makeSign(array $data, $apikey)
{
    ksort($data);
    $signStr = '';
    foreach ($data as $k => $val) {
        if ($k === 'sign' || $k === 'sign_type' || $val === null || $val === '') {
            continue;
        }
        $signStr .= $k . '=' . $val . '&';
    }
    $signStr = substr($signStr, 0, -1) . '&key=' . $apikey;
    return [strtoupper(md5($signStr)), $signStr];
}

echo "enabled=" . ($enabled ? '1' : '0') . " mock=" . ($mock ? '1' : '0') . "\n";
echo "gateway_base={$gateway}\n";
echo "uname={$uname}\n";

$params = [
    'uname'     => $uname,
    'timestamp' => (string)time(),
];
list($sign, $raw) = makeSign($params, $apikey);
$params['sign'] = $sign;
$url = $gateway . '/api/get_balance';
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($params),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
        'Accept: application/json',
    ],
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
]);
$body = curl_exec($ch);
$err = curl_error($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "balance_url={$url}\n";
echo "http={$code} curl={$err}\n";
echo "body=" . substr((string)$body, 0, 800) . "\n";
echo "sign_raw_masked=" . preg_replace('/key=.+$/', 'key=***', $raw) . "\n";
