<?php
/**
 * 安装红包后台菜单
 * php scripts/install_redpacket_admin_menu.php
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
$rule = 'fa_auth_rule';
$group = 'fa_auth_group';
$now = time();

$insert = $pdo->prepare(
    "INSERT INTO {$rule} (type,pid,name,title,icon,url,`condition`,remark,ismenu,menutype,createtime,updatetime,weigh,status)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
);

function ensureMenu(PDO $pdo, $insert, $rule, $pid, $name, $title, $icon, $ismenu, $weigh, $now, $remark = '')
{
    $id = $pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($name) . " LIMIT 1")->fetchColumn();
    if ($id) {
        echo "SKIP {$name}\n";
        return (int)$id;
    }
    $insert->execute([
        'file', (int)$pid, $name, $title, $icon, '', '', $remark,
        (int)$ismenu, null, $now, $now, (int)$weigh, 'normal',
    ]);
    echo "OK   {$name}\n";
    return (int)$pdo->lastInsertId();
}

function ensurePerm(PDO $pdo, $insert, $rule, $pid, $name, $title, $now)
{
    ensureMenu($pdo, $insert, $rule, $pid, $name, $title, 'fa fa-circle-o', 0, 0, $now);
}

// 父级：优先挂在「即时通讯」下，否则挂 fanshub
$imId = (int)$pdo->query("SELECT id FROM {$rule} WHERE name='fanshub_im' LIMIT 1")->fetchColumn();
$parentId = $imId ?: (int)$pdo->query("SELECT id FROM {$rule} WHERE name='fanshub' LIMIT 1")->fetchColumn();
if ($parentId <= 0) {
    fwrite(STDERR, "fanshub / fanshub_im menu missing\n");
    exit(1);
}

$configId = ensureMenu($pdo, $insert, $rule, $parentId, 'fanshub/redpacketconfig', '红包全局配置', 'fa fa-sliders', 1, 70, $now, '金额/个数/抽水/返点/过期');
ensurePerm($pdo, $insert, $rule, $configId, 'fanshub/redpacketconfig/index', '查看/保存', $now);

$skinId = ensureMenu($pdo, $insert, $rule, $parentId, 'fanshub/redpacketskin', '红包皮肤', 'fa fa-picture-o', 1, 65, $now, '750x1000 皮肤');
foreach (['index' => '查看', 'add' => '添加', 'edit' => '编辑', 'del' => '删除', 'multi' => '批量'] as $act => $title) {
    ensurePerm($pdo, $insert, $rule, $skinId, 'fanshub/redpacketskin/' . $act, $title, $now);
}

$orderId = ensureMenu($pdo, $insert, $rule, $parentId, 'fanshub/redpacket', '红包订单', 'fa fa-gift', 1, 60, $now, '红包列表与运营');
foreach ([
    'index' => '查看',
    'detail' => '详情',
    'retrysettle' => '重试结算',
    'refundnow' => '过期退回',
    'forceclose' => '强制关包',
    'adjusthint' => '异常补账',
] as $act => $title) {
    ensurePerm($pdo, $insert, $rule, $orderId, 'fanshub/redpacket/' . $act, $title, $now);
}

$settleId = ensureMenu($pdo, $insert, $rule, $parentId, 'fanshub/redpacketsettle', '红包结算对账', 'fa fa-balance-scale', 1, 55, $now, '抽水/返点/赔付流水');
foreach (['index' => '查看', 'summary' => '汇总', 'retrybatch' => '批量重试'] as $act => $title) {
    ensurePerm($pdo, $insert, $rule, $settleId, 'fanshub/redpacketsettle/' . $act, $title, $now);
}

// 同步默认配置键
$cfgTable = 'fa_chat_red_packet_config';
$exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($cfgTable))->fetchColumn();
if ($exists) {
    $st = $pdo->prepare(
        "INSERT INTO {$cfgTable} (cfg_key,cfg_value,remark,updatetime) VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE remark=VALUES(remark), updatetime=VALUES(updatetime)"
    );
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
        ['platform_user_id', '1', '平台收款用户'],
        ['skin_width', '750', '皮肤宽'],
        ['skin_height', '1000', '皮肤高'],
    ];
    foreach ($defaults as $row) {
        $st->execute([$row[0], $row[1], $row[2], $now]);
    }
    echo "OK   config keys\n";
}

// 授权给超级管理组
$like = $pdo->query(
    "SELECT id FROM {$rule} WHERE name LIKE 'fanshub/redpacket%' OR name LIKE 'fanshub/redpacketskin%' OR name LIKE 'fanshub/redpacketconfig%' OR name LIKE 'fanshub/redpacketsettle%'"
)->fetchAll(PDO::FETCH_COLUMN);
$g = $pdo->query("SELECT rules FROM {$group} WHERE id=1")->fetch(PDO::FETCH_ASSOC);
if ($g && $like) {
    $have = array_flip(array_filter(explode(',', (string)$g['rules'])));
    $missing = [];
    foreach ($like as $rid) {
        if (!isset($have[$rid])) {
            $missing[] = $rid;
        }
    }
    if ($missing) {
        $new = trim((string)$g['rules'] . ',' . implode(',', $missing), ',');
        $pdo->prepare("UPDATE {$group} SET rules=? WHERE id=1")->execute([$new]);
        echo 'GRANTED group#1 +' . count($missing) . " rules\n";
    }
}

echo "DONE 请清后台缓存后刷新菜单\n";
