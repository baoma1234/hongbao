<?php
/**
 * 充提分区 + 通道归属 + 钱包地址绑定
 *   php scripts/install_pay_partition.php
 */
$root = dirname(__DIR__);
$env = parse_ini_file($root . '/.env', true);
$d = $env['database'];
$pdo = new PDO(
    "mysql:host={$d['hostname']};port=" . ($d['hostport'] ?? 3306) . ";dbname={$d['database']};charset=utf8mb4",
    $d['username'],
    $d['password'],
    [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4', PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$prefix = $d['prefix'] ?? 'fa_';
$now = time();

function colExists(PDO $pdo, $table, $col)
{
    $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetch();
}

function tableExists(PDO $pdo, $table)
{
    $st = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
    return (bool)$st->fetch();
}

$partTable = $prefix . 'fans_pay_partition';
$chTable = $prefix . 'fans_pay_channel';
$bindTable = $prefix . 'fans_wallet_bind';
$rule = $prefix . 'auth_rule';

// 1) partitions
if (!tableExists($pdo, $partTable)) {
    $pdo->exec("CREATE TABLE `{$partTable}` (
      `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
      `type` enum('recharge','withdraw') NOT NULL DEFAULT 'recharge' COMMENT '适用类型',
      `code` varchar(32) NOT NULL DEFAULT '' COMMENT 'stable: self_service|wallet',
      `name` varchar(64) NOT NULL DEFAULT '' COMMENT '中文名称',
      `name_i18n` text COMMENT '多语言JSON',
      `bind_mode` varchar(32) NOT NULL DEFAULT 'none' COMMENT 'none|conventional|wallet',
      `weigh` int(11) NOT NULL DEFAULT '0',
      `status` enum('normal','hidden') NOT NULL DEFAULT 'normal',
      `createtime` int(10) unsigned DEFAULT NULL,
      `updatetime` int(10) unsigned DEFAULT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uk_type_code` (`type`,`code`),
      KEY `idx_type_status` (`type`,`status`,`weigh`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='充提通道分区'");
    echo "OK create {$partTable}\n";
} else {
    echo "SKIP {$partTable} exists\n";
}

// 2) channel.partition_id
if (!colExists($pdo, $chTable, 'partition_id')) {
    $pdo->exec("ALTER TABLE `{$chTable}` ADD COLUMN `partition_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '分区ID' AFTER `type`");
    $pdo->exec("ALTER TABLE `{$chTable}` ADD KEY `idx_partition` (`type`,`partition_id`,`status`,`weigh`)");
    echo "OK add {$chTable}.partition_id\n";
} else {
    echo "SKIP partition_id\n";
}

// 3) wallet bind
if (!tableExists($pdo, $bindTable)) {
    $pdo->exec("CREATE TABLE `{$bindTable}` (
      `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
      `user_id` int(10) unsigned NOT NULL DEFAULT '0',
      `wallet_type` varchar(64) NOT NULL DEFAULT '' COMMENT 'Nopay/Ersansi/alipay/bank...',
      `bind_mode` varchar(32) NOT NULL DEFAULT 'wallet' COMMENT 'wallet|conventional',
      `account_name` varchar(64) NOT NULL DEFAULT '',
      `account_no` varchar(255) NOT NULL DEFAULT '' COMMENT '地址或账号',
      `account_hash` varchar(64) NOT NULL DEFAULT '' COMMENT '规范化哈希',
      `bank_name` varchar(64) NOT NULL DEFAULT '',
      `extra` text,
      `createtime` int(10) unsigned DEFAULT NULL,
      `updatetime` int(10) unsigned DEFAULT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uk_user_type` (`user_id`,`wallet_type`),
      UNIQUE KEY `uk_type_hash` (`wallet_type`,`account_hash`),
      KEY `idx_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户提现收款绑定'");
    echo "OK create {$bindTable}\n";
} else {
    echo "SKIP {$bindTable} exists\n";
}

// 4) seed partitions
$seeds = [
    ['recharge', 'self_service', '自助充值', 'conventional', 90,
        ['en-PH' => 'Self-service top-up', 'id-ID' => 'Isi ulang mandiri', 'vi-VN' => 'Nạp tự phục vụ', 'ms-MY' => 'Tambah nilai sendiri', 'km-KH' => 'បញ្ចូលទឹកប្រាក់ដោយខ្លួនឯង']],
    ['recharge', 'wallet', '钱包地址', 'wallet', 80,
        ['en-PH' => 'Wallet address', 'id-ID' => 'Alamat dompet', 'vi-VN' => 'Địa chỉ ví', 'ms-MY' => 'Alamat dompet', 'km-KH' => 'អាសយដ្ឋានកាបូប']],
    ['withdraw', 'self_service', '自助提现', 'conventional', 90,
        ['en-PH' => 'Self-service withdraw', 'id-ID' => 'Penarikan mandiri', 'vi-VN' => 'Rút tự phục vụ', 'ms-MY' => 'Pengeluaran sendiri', 'km-KH' => 'ដកប្រាក់ដោយខ្លួនឯង']],
    ['withdraw', 'wallet', '钱包地址', 'wallet', 80,
        ['en-PH' => 'Wallet address', 'id-ID' => 'Alamat dompet', 'vi-VN' => 'Địa chỉ ví', 'ms-MY' => 'Alamat dompet', 'km-KH' => 'អាសយដ្ឋានកាបូប']],
];
$ins = $pdo->prepare("INSERT INTO `{$partTable}` (type,code,name,name_i18n,bind_mode,weigh,status,createtime,updatetime)
    VALUES (?,?,?,?,?,?, 'normal', ?, ?)
    ON DUPLICATE KEY UPDATE name=VALUES(name), name_i18n=VALUES(name_i18n), bind_mode=VALUES(bind_mode), weigh=VALUES(weigh), status='normal', updatetime=VALUES(updatetime)");
foreach ($seeds as $s) {
    $ins->execute([$s[0], $s[1], $s[2], json_encode($s[5], JSON_UNESCAPED_UNICODE), $s[3], $s[4], $now, $now]);
    echo "SEED {$s[0]}/{$s[1]}\n";
}

$map = [];
foreach ($pdo->query("SELECT id,type,code FROM `{$partTable}`") as $row) {
    $map[$row['type'] . ':' . $row['code']] = (int)$row['id'];
}

// 5) auto-assign channels without partition
$walletHandlers = ['wanhuitong', 'bs'];
$rows = $pdo->query("SELECT id,type,handler,pay_channel,name,partition_id FROM `{$chTable}`")->fetchAll(PDO::FETCH_ASSOC);
$upd = $pdo->prepare("UPDATE `{$chTable}` SET partition_id=?, updatetime=? WHERE id=?");
$assigned = 0;
foreach ($rows as $r) {
    if ((int)$r['partition_id'] > 0) {
        continue;
    }
    $type = $r['type'] === 'withdraw' ? 'withdraw' : 'recharge';
    $handler = strtolower((string)$r['handler']);
    $pay = strtolower(trim((string)$r['pay_channel']));
    $walletCodes = [
        'kdou','abpay','cbi','jdpay','sanliuwu','hdpay','mbpay','qianneng','fpay','jiubaba',
        'balingba','ersansi','vippay','upay','okpay','topay','gopay','nopay','goubaopay',
        'agpay','wanbi','biqu','bobi','mpay','usdt_trc20'
    ];
    $isWallet = in_array($handler, $walletHandlers, true) || in_array($pay, $walletCodes, true);
    if ($handler === 'merchant' && strpos($pay, 'test_') === 0) {
        $isWallet = false;
    }
    $code = $isWallet ? 'wallet' : 'self_service';
    $pid = $map[$type . ':' . $code] ?? 0;
    if ($pid > 0) {
        $upd->execute([$pid, $now, (int)$r['id']]);
        $assigned++;
    }
}
echo "ASSIGN channels={$assigned}\n";

// 6) menu under finance
function ruleId(PDO $pdo, $rule, $name)
{
    $st = $pdo->prepare("SELECT id FROM `{$rule}` WHERE name=? LIMIT 1");
    $st->execute([$name]);
    return (int)$st->fetchColumn();
}

$financeId = ruleId($pdo, $rule, 'finance');
if ($financeId <= 0) {
    echo "WARN finance menu missing, skip menu\n";
} else {
    $menuName = 'fanshub/paypartition';
    $mid = ruleId($pdo, $rule, $menuName);
    $insert = $pdo->prepare(
        "INSERT INTO `{$rule}` (type,pid,name,title,icon,url,`condition`,remark,ismenu,menutype,createtime,updatetime,weigh,status)
         VALUES ('file',?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    if ($mid <= 0) {
        $insert->execute([
            $financeId, $menuName, '充提分区', 'fa fa-th-large', '', '', '充值/提现通道分区与多语言名称',
            1, 'addtabs', $now, $now, 85, 'normal',
        ]);
        $mid = (int)$pdo->lastInsertId();
        echo "OK menu {$menuName} #{$mid}\n";
    } else {
        $pdo->prepare("UPDATE `{$rule}` SET pid=?, title='充提分区', icon='fa fa-th-large', ismenu=1, menutype='addtabs', status='normal', weigh=85, updatetime=? WHERE id=?")
            ->execute([$financeId, $now, $mid]);
        echo "FIX menu #{$mid}\n";
    }
    $children = [
        [$menuName . '/index', '查看'],
        [$menuName . '/add', '添加'],
        [$menuName . '/edit', '编辑'],
        [$menuName . '/del', '删除'],
        [$menuName . '/multi', '批量'],
    ];
    foreach ($children as $c) {
        if (ruleId($pdo, $rule, $c[0]) > 0) {
            continue;
        }
        $insert->execute([
            $mid, $c[0], $c[1], 'fa fa-circle-o', '', '', '',
            0, null, $now, $now, 0, 'normal',
        ]);
        echo "OK child {$c[0]}\n";
    }
}

echo "DONE\n";
