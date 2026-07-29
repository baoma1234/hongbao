<?php
/**
 * 种子：久远支付（充值）+ 久远代付（提现）通道
 *
 *   php scripts/seed_jiuyuan_paychannel.php --base=https://平台域名 --mchid=商户号 --key=密钥 --bankcode=932
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

function argv($name, $default = '')
{
    global $argv;
    foreach ($argv as $a) {
        if (strpos($a, '--' . $name . '=') === 0) {
            return substr($a, strlen($name) + 3);
        }
    }
    $env = getenv('JIUYUAN_' . strtoupper(str_replace('-', '_', $name)));
    return ($env !== false && $env !== '') ? $env : $default;
}

function loadEnv($file)
{
    $out = [];
    if (!is_file($file)) {
        return $out;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        list($k, $v) = explode('=', $line, 2);
        $out[trim($k)] = trim($v, " \t\"'");
    }
    return $out;
}

$env = loadEnv(dirname(__DIR__) . '/.env');
$host = $env['database.hostname'] ?? '127.0.0.1';
$dbn = $env['database.database'] ?? 'fastadmin';
$user = $env['database.username'] ?? 'root';
$pass = $env['database.password'] ?? '';
$port = $env['database.hostport'] ?? '3306';
$prefix = $env['database.prefix'] ?? 'fa_';
$charset = $env['database.charset'] ?? 'utf8mb4';

$base = rtrim(argv('base', ''), '/');
$mchid = argv('mchid', '');
$key = argv('key', '');
$bankcode = argv('bankcode', '932');
$payChanel = argv('pay_chanel', '102');
$site = rtrim(argv('site', ''), '/');

if ($base === '' || $mchid === '' || $key === '') {
    fwrite(STDERR, "用法：php scripts/seed_jiuyuan_paychannel.php --base=https://pay.xxx.com --mchid=10001 --key=xxxxx [--bankcode=932] [--pay_chanel=102] [--site=https://你的站点]\n");
    exit(1);
}

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$dbn};charset={$charset}",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function upsert(PDO $pdo, $prefix, array $row, $site)
{
    $table = $prefix . 'fans_pay_channel';
    $st = $pdo->prepare("SELECT id, notify_url FROM `{$table}` WHERE type=? AND handler='jiuyuan' AND name=? LIMIT 1");
    $st->execute([$row['type'], $row['name']]);
    $exist = $st->fetch(PDO::FETCH_ASSOC);
    $now = time();
    if ($exist) {
        $id = (int)$exist['id'];
        $sql = "UPDATE `{$table}` SET submit_url=?, merchant_no=?, merchant_key=?, pay_type=?, pay_channel=?, return_url=?, product_name=?, config=?, tip=?, min_amount=?, max_amount=?, weigh=?, status=?, updatetime=? WHERE id=?";
        $pdo->prepare($sql)->execute([
            $row['submit_url'], $row['merchant_no'], $row['merchant_key'], $row['pay_type'], $row['pay_channel'],
            $row['return_url'], $row['product_name'], $row['config'], $row['tip'],
            $row['min_amount'], $row['max_amount'], $row['weigh'], $row['status'], $now, $id,
        ]);
        echo "updated #{$id} {$row['type']} {$row['name']}\n";
    } else {
        $sql = "INSERT INTO `{$table}` (type,name,icon,tip,handler,submit_url,merchant_no,merchant_key,pay_type,pay_channel,notify_url,return_url,product_name,config,min_amount,max_amount,weigh,status,createtime,updatetime) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $pdo->prepare($sql)->execute([
            $row['type'], $row['name'], '', $row['tip'], 'jiuyuan',
            $row['submit_url'], $row['merchant_no'], $row['merchant_key'], $row['pay_type'], $row['pay_channel'],
            '', $row['return_url'], $row['product_name'], $row['config'],
            $row['min_amount'], $row['max_amount'], $row['weigh'], $row['status'], $now, $now,
        ]);
        $id = (int)$pdo->lastInsertId();
        echo "inserted #{$id} {$row['type']} {$row['name']}\n";
    }
    $action = $row['type'] === 'withdraw' ? 'withdrawnotify' : 'rechargenotify';
    $notify = ($site !== '' ? $site : '') . '/api/pay/' . $action . '?channel_id=' . $id;
    if ($site === '') {
        echo "  warn: 未传 --site，请到后台填写 notify_url：/api/pay/{$action}?channel_id={$id}\n";
        return $id;
    }
    $cfg = json_decode($row['config'], true) ?: [];
    $cfg['notify_url'] = $notify;
    $pdo->prepare("UPDATE `{$table}` SET notify_url=?, config=?, updatetime=? WHERE id=?")
        ->execute([$notify, json_encode($cfg, JSON_UNESCAPED_UNICODE), $now, $id]);
    echo "  notify_url => {$notify}\n";
    return $id;
}

$returnUrl = ($site !== '' ? $site : '') . '/888/#profile';
$common = [
    'submit_url'   => $base,
    'merchant_no'  => $mchid,
    'merchant_key' => $key,
    'return_url'   => $returnUrl,
    'min_amount'   => 10,
    'max_amount'   => 50000,
    'weigh'        => 100,
    'status'       => 'normal',
];

upsert($pdo, $prefix, array_merge($common, [
    'type'         => 'recharge',
    'name'         => '久远支付',
    'tip'          => '久远收银台（bankcode ' . $bankcode . '）',
    'pay_type'     => $bankcode,
    'pay_channel'  => '0',
    'product_name' => '账户充值',
    'min_amount'   => 10,
    'config'       => json_encode([
        'gateway' => 'jiuyuan', 'submit_url' => $base, 'merchant_no' => $mchid, 'merchant_key' => $key,
        'pay_type' => $bankcode, 'pay_channel' => '0', 'return_url' => $returnUrl, 'product_name' => '账户充值',
    ], JSON_UNESCAPED_UNICODE),
]), $site);

upsert($pdo, $prefix, array_merge($common, [
    'type'         => 'withdraw',
    'name'         => '久远代付',
    'tip'          => '久远代付（chanel ' . $payChanel . '）',
    'pay_type'     => '',
    'pay_channel'  => $payChanel,
    'product_name' => '账户提现',
    'min_amount'   => 50,
    'config'       => json_encode([
        'gateway' => 'jiuyuan', 'submit_url' => $base, 'merchant_no' => $mchid, 'merchant_key' => $key,
        'pay_type' => '', 'pay_channel' => $payChanel, 'return_url' => $returnUrl, 'product_name' => '账户提现',
    ], JSON_UNESCAPED_UNICODE),
]), $site);

echo "done.\n";
