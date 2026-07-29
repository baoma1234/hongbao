<?php
$root = dirname(__DIR__);
$env = parse_ini_file($root . '/.env', true);
$d = $env['database'];
$pdo = new PDO(
    "mysql:host={$d['hostname']};dbname={$d['database']};charset=utf8mb4",
    $d['username'],
    $d['password']
);
$rows = $pdo->query('SELECT cfg_key,cfg_value FROM fa_chat_red_packet_config')->fetchAll(PDO::FETCH_KEY_PAIR);
$rp = [
    'expire_seconds'            => (int)($rows['expire_seconds'] ?? 60),
    'platform_fee_rate'         => (float)($rows['platform_fee_rate'] ?? 0.03),
    'agent_rebate_rate_default' => (float)($rows['agent_rebate_rate_default'] ?? 0.01),
    'agent_rebate_rate_vip'     => (float)($rows['agent_rebate_rate_vip'] ?? 0.01),
    'platform_user_id'          => (int)($rows['platform_user_id'] ?? 1),
    'max_count'                 => (int)($rows['max_count'] ?? 10),
    'min_amount'                => (float)($rows['min_amount'] ?? 10),
    'min_count'                 => (int)($rows['min_count'] ?? 5),
    'vip_min_count'             => (int)($rows['vip_min_count'] ?? 5),
    'vip_max_count'             => (int)($rows['vip_max_count'] ?? 10),
    'skin_width'                => (int)($rows['skin_width'] ?? 750),
    'skin_height'               => (int)($rows['skin_height'] ?? 1000),
];
$file = $root . '/im-server/config/red_packet_runtime.php';
file_put_contents($file, "<?php\nreturn " . var_export(['red_packet' => $rp], true) . ";\n");
echo "OK {$file}\n";
