<?php
/**
 * 接龙/拼手气拆分迁移（PDO）：
 * - 写入接龙独立配置（从当前拼手气配置复制）
 * - 原接龙群切到 type=5
 * - 自动任务 packet_type 2→5
 * - 同步 IM runtime
 *
 * 用法：php scripts/migrate_relay_lucky_split.php
 */
declare(strict_types=1);

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

echo "=== migrate relay/lucky split ===\n";

$rows = [];
try {
    $rows = $pdo->query("SELECT cfg_key, cfg_value FROM {$cfgTable}")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Throwable $e) {
    echo "config table read fail: " . $e->getMessage() . "\n";
}

$get = function ($k, $def) use ($rows) {
    return array_key_exists($k, $rows) ? (string)$rows[$k] : (string)$def;
};

$seed = [
    ['relay_expire_seconds', $get('relay_expire_seconds', $get('expire_seconds', '60')), '接龙过期秒数'],
    ['relay_min_amount', $get('relay_min_amount', $get('min_amount', '10.00')), '接龙最低金额'],
    ['relay_min_count', $get('relay_min_count', $get('min_count', '5')), '接龙普通群最少个数'],
    ['relay_max_count', $get('relay_max_count', $get('max_count', '10')), '接龙普通群最多个数'],
    ['relay_vip_min_count', $get('relay_vip_min_count', $get('vip_min_count', '5')), '接龙VIP最少个数'],
    ['relay_vip_max_count', $get('relay_vip_max_count', $get('vip_max_count', '10')), '接龙VIP最多个数'],
    ['relay_platform_fee_rate', $get('relay_platform_fee_rate', $get('platform_fee_rate', '0.0300')), '接龙平台抽水'],
    ['relay_agent_rebate_rate_default', $get('relay_agent_rebate_rate_default', $get('agent_rebate_rate_default', '0.0100')), '接龙代理返佣'],
    ['relay_agent_rebate_rate_vip', $get('relay_agent_rebate_rate_vip', $get('agent_rebate_rate_vip', '0.0100')), '接龙代理返佣VIP'],
    ['relay_invite_rebate_rate', $get('relay_invite_rebate_rate', $get('invite_rebate_rate', '0.0050')), '接龙邀请返佣'],
    ['relay_platform_user_id', $get('relay_platform_user_id', $get('platform_user_id', '56960815')), '接龙平台收款用户'],
];

$st = $pdo->prepare(
    "INSERT INTO {$cfgTable} (cfg_key,cfg_value,remark,updatetime) VALUES (?,?,?,?)
     ON DUPLICATE KEY UPDATE cfg_value=VALUES(cfg_value), remark=VALUES(remark), updatetime=VALUES(updatetime)"
);
foreach ($seed as $row) {
    $st->execute([$row[0], $row[1], $row[2], $now]);
}
echo "relay config seeded\n";

// refresh map
$rows = $pdo->query("SELECT cfg_key, cfg_value FROM {$cfgTable}")->fetchAll(PDO::FETCH_KEY_PAIR);

$groupsTable = $prefix . 'chat_groups';
$gStmt = $pdo->query("SELECT id,name,rp_enabled_types,rp_robot_only,rp_fixed_amount FROM {$groupsTable}");
$gUpdated = 0;
$relayGroupIds = [];
$upd = $pdo->prepare("UPDATE {$groupsTable} SET rp_enabled_types=?, updatetime=? WHERE id=?");
while ($g = $gStmt->fetch(PDO::FETCH_ASSOC)) {
    $id = (int)$g['id'];
    $types = array_values(array_filter(array_map('trim', explode(',', (string)($g['rp_enabled_types'] ?? '')))));
    $has2 = in_array('2', $types, true);
    $has5 = in_array('5', $types, true);
    $robotOnly = (int)($g['rp_robot_only'] ?? 0) === 1;
    $fixed = round((float)($g['rp_fixed_amount'] ?? 0), 2) > 0;
    $other = array_intersect($types, ['1', '3', '4']);
    $isRelayGroup = $has2 && ($robotOnly || $fixed || (!$has5 && count($other) === 0));
    if (!$isRelayGroup) {
        continue;
    }
    $types = array_values(array_filter($types, function ($t) {
        return $t !== '2';
    }));
    if (!in_array('5', $types, true)) {
        $types[] = '5';
    }
    sort($types, SORT_NUMERIC);
    $upd->execute([implode(',', $types), $now, $id]);
    $relayGroupIds[] = $id;
    $gUpdated++;
    echo "group #{$id} -> " . implode(',', $types) . "\n";
}
echo "groups updated: {$gUpdated}\n";

$taskUpdated = 0;
try {
    $taskTable = $prefix . 'chat_rp_auto_task';
    if ($relayGroupIds) {
        $in = implode(',', array_map('intval', $relayGroupIds));
        $taskUpdated = $pdo->exec(
            "UPDATE {$taskTable} SET packet_type=5, updatetime={$now} WHERE packet_type=2 AND group_id IN ({$in})"
        );
    } else {
        // 无识别到接龙群时，不强制改全部任务，避免误伤拼手气群
        $taskUpdated = 0;
    }
} catch (Throwable $e) {
    echo "auto task update skip: " . $e->getMessage() . "\n";
}
echo "auto tasks 2->5: {$taskUpdated}\n";

// sync runtime (subset + relay)
$rp = [
    'expire_seconds' => max(1, (int)($rows['expire_seconds'] ?? 60)),
    'mine_expire_seconds' => max(1, (int)($rows['mine_expire_seconds'] ?? 180)),
    'platform_fee_rate' => (float)($rows['platform_fee_rate'] ?? 0.03),
    'agent_rebate_rate_default' => (float)($rows['agent_rebate_rate_default'] ?? 0.01),
    'agent_rebate_rate_vip' => (float)($rows['agent_rebate_rate_vip'] ?? 0.01),
    'invite_rebate_rate' => (float)($rows['invite_rebate_rate'] ?? 0.005),
    'platform_user_id' => (int)($rows['platform_user_id'] ?? 56960815),
    'group_robot_user_id' => (int)($rows['group_robot_user_id'] ?? 74282747),
    'mine_compensate_rate_5' => max(0.01, (float)($rows['mine_compensate_rate_5'] ?? 1.5)),
    'mine_compensate_rate_7' => max(0.01, (float)($rows['mine_compensate_rate_7'] ?? 1.2)),
    'mine_compensate_rate_9' => max(0.01, (float)($rows['mine_compensate_rate_9'] ?? 1.0)),
    'mine_platform_fee_rate' => (float)($rows['mine_platform_fee_rate'] ?? ($rows['platform_fee_rate'] ?? 0.03)),
    'mine_agent_rebate_rate_default' => (float)($rows['mine_agent_rebate_rate_default'] ?? ($rows['agent_rebate_rate_default'] ?? 0.01)),
    'mine_agent_rebate_rate_vip' => (float)($rows['mine_agent_rebate_rate_vip'] ?? ($rows['agent_rebate_rate_vip'] ?? 0.01)),
    'mine_invite_rebate_rate' => (float)($rows['mine_invite_rebate_rate'] ?? ($rows['invite_rebate_rate'] ?? 0.005)),
    'mine_platform_user_id' => (int)($rows['mine_platform_user_id'] ?? ($rows['platform_user_id'] ?? 56960815)),
    'user_rp_expire_seconds' => max(1, (int)($rows['user_rp_expire_seconds'] ?? 1800)),
    'user_rp_min_amount' => (float)($rows['user_rp_min_amount'] ?? ($rows['min_amount'] ?? 10)),
    'user_rp_min_count' => max(1, (int)($rows['user_rp_min_count'] ?? 1)),
    'user_rp_max_count' => max(1, (int)($rows['user_rp_max_count'] ?? 100)),
    'user_rp_platform_fee_rate' => (float)($rows['user_rp_platform_fee_rate'] ?? ($rows['platform_fee_rate'] ?? 0.03)),
    'user_rp_agent_rebate_rate_default' => (float)($rows['user_rp_agent_rebate_rate_default'] ?? ($rows['agent_rebate_rate_default'] ?? 0.01)),
    'user_rp_agent_rebate_rate_vip' => (float)($rows['user_rp_agent_rebate_rate_vip'] ?? ($rows['agent_rebate_rate_vip'] ?? 0.01)),
    'user_rp_invite_rebate_rate' => (float)($rows['user_rp_invite_rebate_rate'] ?? ($rows['invite_rebate_rate'] ?? 0.005)),
    'user_rp_platform_user_id' => (int)($rows['user_rp_platform_user_id'] ?? ($rows['platform_user_id'] ?? 56960815)),
    'relay_expire_seconds' => max(1, (int)($rows['relay_expire_seconds'] ?? ($rows['expire_seconds'] ?? 60))),
    'relay_min_amount' => (float)($rows['relay_min_amount'] ?? ($rows['min_amount'] ?? 10)),
    'relay_min_count' => max(1, (int)($rows['relay_min_count'] ?? ($rows['min_count'] ?? 5))),
    'relay_max_count' => max(1, (int)($rows['relay_max_count'] ?? ($rows['max_count'] ?? 10))),
    'relay_vip_min_count' => max(1, (int)($rows['relay_vip_min_count'] ?? ($rows['vip_min_count'] ?? 5))),
    'relay_vip_max_count' => max(1, (int)($rows['relay_vip_max_count'] ?? ($rows['vip_max_count'] ?? 10))),
    'relay_platform_fee_rate' => (float)($rows['relay_platform_fee_rate'] ?? ($rows['platform_fee_rate'] ?? 0.03)),
    'relay_agent_rebate_rate_default' => (float)($rows['relay_agent_rebate_rate_default'] ?? ($rows['agent_rebate_rate_default'] ?? 0.01)),
    'relay_agent_rebate_rate_vip' => (float)($rows['relay_agent_rebate_rate_vip'] ?? ($rows['agent_rebate_rate_vip'] ?? 0.01)),
    'relay_invite_rebate_rate' => (float)($rows['relay_invite_rebate_rate'] ?? ($rows['invite_rebate_rate'] ?? 0.005)),
    'relay_platform_user_id' => (int)($rows['relay_platform_user_id'] ?? ($rows['platform_user_id'] ?? 56960815)),
    'max_count' => max(1, (int)($rows['max_count'] ?? 10)),
    'min_amount' => (float)($rows['min_amount'] ?? 10),
    'min_count' => (int)($rows['min_count'] ?? 5),
    'vip_min_count' => (int)($rows['vip_min_count'] ?? 5),
    'vip_max_count' => (int)($rows['vip_max_count'] ?? 10),
    'skin_width' => (int)($rows['skin_width'] ?? 750),
    'skin_height' => (int)($rows['skin_height'] ?? 1000),
];
$file = $root . '/im-server/config/red_packet_runtime.php';
$export = var_export(['red_packet' => $rp], true);
file_put_contents($file, "<?php\n// auto-generated by migrate_relay_lucky_split — do not edit\nreturn {$export};\n");
echo "runtime synced: {$file}\n";
echo "DONE. Please restart IM workers.\n";
