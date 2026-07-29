<?php
/**
 * 同步红包规则配置到 DB + IM runtime：
 * - 最低金额 10、个数 5-10、VIP最少 5、过期 60s
 * - 平台抽水 3%、代理返佣默认 1%
 * - 群 rp_agent_rebate_rate 列默认改为 0.01；旧默认 0.0050 的群升为 0.01
 */
$root = dirname(__DIR__);
$env = parse_ini_file($root . '/.env', true);
$d = $env['database'];
$pdo = new PDO(
    "mysql:host={$d['hostname']};dbname={$d['database']};charset=utf8mb4",
    $d['username'],
    $d['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$prefix = $d['prefix'] ?? 'fa_';
$now = time();

$cfgTable = $prefix . 'chat_red_packet_config';
$defaults = [
    ['min_amount', '10.00', '普通群最低金额'],
    ['min_count', '5', '普通群最少个数'],
    ['max_count', '10', '普通群最多个数'],
    ['vip_min_count', '5', 'VIP群最少个数'],
    ['vip_max_count', '10', 'VIP群最多个数'],
    ['platform_fee_rate', '0.0300', '平台抽水3%'],
    ['agent_rebate_rate_default', '0.0100', '代理默认返佣1%'],
    ['agent_rebate_rate_vip', '0.0100', 'VIP群返佣1%'],
    ['expire_seconds', '60', '过期秒数'],
];
$st = $pdo->prepare(
    "INSERT INTO {$cfgTable} (cfg_key,cfg_value,remark,updatetime) VALUES (?,?,?,?)
     ON DUPLICATE KEY UPDATE cfg_value=VALUES(cfg_value), remark=VALUES(remark), updatetime=VALUES(updatetime)"
);
foreach ($defaults as $row) {
    $st->execute([$row[0], $row[1], $row[2], $now]);
}
echo "OK config keys updated\n";

$groups = $prefix . 'chat_groups';
try {
    $pdo->exec("ALTER TABLE {$groups} MODIFY COLUMN `rp_agent_rebate_rate` decimal(5,4) unsigned NOT NULL DEFAULT '0.0100' COMMENT '代理返佣比例'");
    echo "OK groups column default -> 0.0100\n";
} catch (Throwable $e) {
    echo "WARN alter groups: " . $e->getMessage() . "\n";
}
$upd = $pdo->exec("UPDATE {$groups} SET rp_agent_rebate_rate='0.0100' WHERE rp_agent_rebate_rate='0.0050'");
echo "OK groups rate 0.0050->0.0100 rows={$upd}\n";

// sync runtime file
$rows = $pdo->query("SELECT cfg_key,cfg_value FROM {$cfgTable}")->fetchAll(PDO::FETCH_KEY_PAIR);
$platformUid = (int)($rows['platform_user_id'] ?? 56960815);
$rp = [
    'expire_seconds'            => (int)($rows['expire_seconds'] ?? 60),
    'platform_fee_rate'         => (float)($rows['platform_fee_rate'] ?? 0.03),
    'agent_rebate_rate_default' => (float)($rows['agent_rebate_rate_default'] ?? 0.01),
    'agent_rebate_rate_vip'     => (float)($rows['agent_rebate_rate_vip'] ?? 0.01),
    'platform_user_id'          => $platformUid > 0 ? $platformUid : 56960815,
    'max_count'                 => (int)($rows['max_count'] ?? 10),
    'min_amount'                => (float)($rows['min_amount'] ?? 10),
    'min_count'                 => (int)($rows['min_count'] ?? 5),
    'vip_min_count'             => (int)($rows['vip_min_count'] ?? 5),
    'vip_max_count'             => (int)($rows['vip_max_count'] ?? 10),
    'skin_width'                => (int)($rows['skin_width'] ?? 750),
    'skin_height'               => (int)($rows['skin_height'] ?? 1000),
];
$file = $root . '/im-server/config/red_packet_runtime.php';
$export = var_export(['red_packet' => $rp], true);
file_put_contents($file, "<?php\n// auto-updated by scripts/patch_red_packet_rules_20260725.php\nreturn {$export};\n");
echo "OK runtime {$file}\n";
