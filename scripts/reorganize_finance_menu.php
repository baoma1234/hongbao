<?php
/**
 * 新建顶级菜单「财务管理」，迁移支付相关菜单，拆分充值/提现通道
 *
 *   php scripts/reorganize_finance_menu.php
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
$now = time();

function ruleId(PDO $pdo, $name)
{
    global $rule;
    return (int)$pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($name) . " LIMIT 1")->fetchColumn();
}

$insert = $pdo->prepare(
    "INSERT INTO {$rule} (type,pid,name,title,icon,url,`condition`,remark,ismenu,menutype,createtime,updatetime,weigh,status)"
    . " VALUES ('file',?,?,?,?,?,?,?,?,?,?,?,?,?)"
);

// 1) 顶级「财务管理」与 auth 同级
$financeId = ruleId($pdo, 'finance');
$authWeigh = (int)$pdo->query("SELECT weigh FROM {$rule} WHERE name='auth' LIMIT 1")->fetchColumn();
$weigh = $authWeigh > 0 ? max(1, $authWeigh - 1) : 98;
if ($financeId <= 0) {
    $insert->execute([
        0, 'finance', '财务管理', 'fa fa-rmb', '', '', '支付总商户、充提通道与充提订单',
        1, null, $now, $now, $weigh, 'normal',
    ]);
    $financeId = (int)$pdo->lastInsertId();
    echo "OK finance #{$financeId} weigh={$weigh}\n";
} else {
    $pdo->prepare("UPDATE {$rule} SET pid=0, title='财务管理', icon='fa fa-rmb', ismenu=1, weigh=?, status='normal', updatetime=? WHERE id=?")
        ->execute([$weigh, $now, $financeId]);
    echo "FIX finance #{$financeId}\n";
}

// 2) 迁移：支付总商户、充值列表、提现列表
$move = [
    'fanshub/paymerchant'    => ['支付总商户', 'fa fa-building', 90],
    'fanshub/rechargeorder'  => ['充值列表', 'fa fa-plus-circle', 60],
    'fanshub/withdraworder'  => ['提现列表', 'fa fa-minus-circle', 50],
];
foreach ($move as $name => $meta) {
    $id = ruleId($pdo, $name);
    if ($id <= 0) {
        echo "MISS {$name}\n";
        continue;
    }
    $pdo->prepare("UPDATE {$rule} SET pid=?, title=?, icon=?, ismenu=1, menutype='addtabs', status='normal', weigh=?, updatetime=? WHERE id=?")
        ->execute([$financeId, $meta[0], $meta[1], $meta[2], $now, $id]);
    echo "MOVE {$name} -> finance title={$meta[0]}\n";
}

// 3) 隐藏旧「充提通道」合集菜单
$oldPay = ruleId($pdo, 'fanshub/paychannel');
if ($oldPay > 0) {
    $pdo->prepare("UPDATE {$rule} SET ismenu=0, status='hidden', title='充提通道(已拆分)', updatetime=? WHERE id=?")
        ->execute([$now, $oldPay]);
    echo "HIDE fanshub/paychannel #{$oldPay}\n";
}

// 4) 新建充值通道 / 提现通道
$channelMenus = [
    'fanshub/rechargechannel' => [
        'title' => '充值通道',
        'icon'  => 'fa fa-arrow-circle-down',
        'weigh' => 80,
        'children' => [
            ['fanshub/rechargechannel/index', '查看'],
            ['fanshub/rechargechannel/add', '添加'],
            ['fanshub/rechargechannel/edit', '编辑'],
            ['fanshub/rechargechannel/del', '删除'],
            ['fanshub/rechargechannel/multi', '批量'],
            ['fanshub/rechargechannel/balance', '查余额'],
        ],
    ],
    'fanshub/withdrawchannel' => [
        'title' => '提现通道',
        'icon'  => 'fa fa-arrow-circle-up',
        'weigh' => 70,
        'children' => [
            ['fanshub/withdrawchannel/index', '查看'],
            ['fanshub/withdrawchannel/add', '添加'],
            ['fanshub/withdrawchannel/edit', '编辑'],
            ['fanshub/withdrawchannel/del', '删除'],
            ['fanshub/withdrawchannel/multi', '批量'],
            ['fanshub/withdrawchannel/balance', '查余额'],
        ],
    ],
];

foreach ($channelMenus as $name => $meta) {
    $mid = ruleId($pdo, $name);
    if ($mid <= 0) {
        $insert->execute([
            $financeId, $name, $meta['title'], $meta['icon'], '', '', '',
            1, 'addtabs', $now, $now, $meta['weigh'], 'normal',
        ]);
        $mid = (int)$pdo->lastInsertId();
        echo "OK {$name} #{$mid}\n";
    } else {
        $pdo->prepare("UPDATE {$rule} SET pid=?, title=?, icon=?, ismenu=1, menutype='addtabs', status='normal', weigh=?, updatetime=? WHERE id=?")
            ->execute([$financeId, $meta['title'], $meta['icon'], $meta['weigh'], $now, $mid]);
        echo "FIX {$name} #{$mid}\n";
    }
    foreach ($meta['children'] as $c) {
        $cid = ruleId($pdo, $c[0]);
        if ($cid > 0) {
            $pdo->prepare("UPDATE {$rule} SET pid=?, title=?, ismenu=0, status='normal', updatetime=? WHERE id=?")
                ->execute([$mid, $c[1], $now, $cid]);
            continue;
        }
        $insert->execute([$mid, $c[0], $c[1], 'fa fa-circle-o', '', '', '', 0, null, $now, $now, 0, 'normal']);
        echo "  + {$c[0]}\n";
    }
}

// 5) 超级管理组授权
$likeNames = [
    'finance',
    'fanshub/paymerchant%',
    'fanshub/rechargeorder%',
    'fanshub/withdraworder%',
    'fanshub/rechargechannel%',
    'fanshub/withdrawchannel%',
];
$ids = [];
foreach ($likeNames as $pat) {
    if (strpos($pat, '%') !== false) {
        $q = $pdo->query("SELECT id FROM {$rule} WHERE name LIKE " . $pdo->quote($pat));
    } else {
        $q = $pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($pat));
    }
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $ids[] = (int)$id;
    }
}
$ids = array_unique($ids);
$g = $pdo->query("SELECT rules FROM fa_auth_group WHERE id=1")->fetch(PDO::FETCH_ASSOC);
if ($g && $ids) {
    $have = array_flip(array_filter(explode(',', (string)$g['rules'])));
    $missing = [];
    foreach ($ids as $rid) {
        if (!isset($have[$rid])) {
            $missing[] = $rid;
        }
    }
    if ($missing) {
        $new = trim((string)$g['rules'] . ',' . implode(',', $missing), ',');
        $pdo->prepare('UPDATE fa_auth_group SET rules=? WHERE id=?')->execute([$new, 1]);
        echo "GRANTED +" . count($missing) . "\n";
    } else {
        echo "GRANT ok (no missing)\n";
    }
}

// 6) 清菜单缓存
$cacheDir = $root . '/runtime/cache';
$n = 0;
if (is_dir($cacheDir)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cacheDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && substr($f->getFilename(), -4) === '.php') {
            @unlink($f->getPathname());
            $n++;
        }
    }
}
echo "cache cleared ~{$n} files\n";

echo "\nDONE. 请 Ctrl+F5 刷新后台。\n";
echo "新结构：财务管理 → 支付总商户 / 充值通道 / 提现通道 / 充值列表 / 提现列表\n";
