<?php
/**
 * 安装 fans_pay_merchant 表、merchant_id 字段、后台菜单
 * 并从 runtime/wanhuipay.credentials.php / 已有通道迁移总商户
 *
 *   php scripts/install_wanhuipay_merchant.php [--site=https://域名] [--batch=Bobi] [--all-wallets]
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = dirname(__DIR__);

function argvOpt($name, $default = '')
{
    global $argv;
    foreach ($argv as $a) {
        if ($a === '--' . $name) {
            return '1';
        }
        if (strpos($a, '--' . $name . '=') === 0) {
            return substr($a, strlen($name) + 3);
        }
    }
    return $default;
}

function loadEnv($file)
{
    $out = [];
    $section = '';
    if (!is_file($file)) {
        return $out;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if ($line[0] === '[' && substr($line, -1) === ']') {
            $section = trim($line, '[]');
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        list($k, $v) = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v, " \t\"'");
        $out[$k] = $v;
        if ($section !== '') {
            $out[$section . '.' . $k] = $v;
        }
    }
    return $out;
}

$env = loadEnv($root . '/.env');
$host = $env['database.hostname'] ?? '127.0.0.1';
$dbn = $env['database.database'] ?? '';
$user = $env['database.username'] ?? 'root';
$pass = $env['database.password'] ?? '';
$port = $env['database.hostport'] ?? '3306';
$prefix = $env['database.prefix'] ?? 'fa_';

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$dbn};charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4']
);

$merchantTable = $prefix . 'fans_pay_merchant';
$channelTable = $prefix . 'fans_pay_channel';
$rule = $prefix . 'auth_rule';
$now = time();

// 1) 建表
$pdo->exec("CREATE TABLE IF NOT EXISTS `{$merchantTable}` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL DEFAULT '' COMMENT '商户备注名',
  `gateway` varchar(32) NOT NULL DEFAULT 'wanhuitong' COMMENT '网关标识',
  `merchant_no` varchar(64) NOT NULL DEFAULT '' COMMENT '平台商户号',
  `private_key` text COMMENT '商户RSA私钥',
  `platform_public_key` text COMMENT '平台RSA公钥',
  `site` varchar(255) NOT NULL DEFAULT '' COMMENT '公网站点根',
  `callback_ips` varchar(255) NOT NULL DEFAULT '18.162.71.242,95.40.141.160',
  `status` enum('normal','hidden') NOT NULL DEFAULT 'normal',
  `remark` varchar(255) NOT NULL DEFAULT '',
  `createtime` int(10) unsigned DEFAULT NULL,
  `updatetime` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_gateway_merchant` (`gateway`,`merchant_no`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='支付总商户'");
echo "OK table {$merchantTable}\n";

// 2) merchant_id 列
$cols = $pdo->query("SHOW COLUMNS FROM `{$channelTable}` LIKE 'merchant_id'")->fetchAll();
if (!$cols) {
    $pdo->exec("ALTER TABLE `{$channelTable}` ADD COLUMN `merchant_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '关联总商户ID' AFTER `handler`");
    echo "OK add merchant_id\n";
} else {
    echo "SKIP merchant_id exists\n";
}

// 3) 菜单
$parentId = (int)$pdo->query("SELECT id FROM {$rule} WHERE name='fanshub' LIMIT 1")->fetchColumn();
if ($parentId <= 0) {
    $parentId = (int)$pdo->query("SELECT id FROM {$rule} WHERE name='fanshub_member' LIMIT 1")->fetchColumn();
}
if ($parentId <= 0) {
    fwrite(STDERR, "fanshub menu missing\n");
    exit(1);
}
$insert = $pdo->prepare(
    "INSERT INTO {$rule} (type,pid,name,title,icon,url,`condition`,remark,ismenu,menutype,createtime,updatetime,weigh,status)"
    . " VALUES ('file',?,?,?,?,?,?,?,?,?,?,?,?,?)"
);
$name = 'fanshub/paymerchant';
$menuId = (int)$pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($name) . " LIMIT 1")->fetchColumn();
if ($menuId <= 0) {
    $insert->execute([
        $parentId, $name, '支付总商户', 'fa fa-building', '', '', '万汇通等总商户凭证',
        1, 'addtabs', $now, $now, 76, 'normal',
    ]);
    $menuId = (int)$pdo->lastInsertId();
    echo "OK menu {$name} #{$menuId}\n";
} else {
    $pdo->prepare("UPDATE {$rule} SET pid=?, title='支付总商户', ismenu=1, status='normal', updatetime=? WHERE id=?")
        ->execute([$parentId, $now, $menuId]);
    echo "FIX menu #{$menuId}\n";
}
foreach ([
    ['fanshub/paymerchant/index', '查看'],
    ['fanshub/paymerchant/add', '添加'],
    ['fanshub/paymerchant/edit', '编辑'],
    ['fanshub/paymerchant/del', '删除'],
    ['fanshub/paymerchant/multi', '批量'],
    ['fanshub/paymerchant/balance', '查余额'],
    ['fanshub/paymerchant/batchchannels', '批量加通道'],
    ['fanshub/paychannel/balance', '通道查余额'],
] as $c) {
    $exists = (int)$pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($c[0]) . " LIMIT 1")->fetchColumn();
    if ($exists > 0) {
        continue;
    }
    $pid = strpos($c[0], 'paychannel') !== false
        ? (int)$pdo->query("SELECT id FROM {$rule} WHERE name='fanshub/paychannel' LIMIT 1")->fetchColumn()
        : $menuId;
    if ($pid <= 0) {
        $pid = $menuId;
    }
    $insert->execute([$pid, $c[0], $c[1], 'fa fa-circle-o', '', '', '', 0, null, $now, $now, 0, 'normal']);
    echo "OK {$c[0]}\n";
}
$ruleIds = $pdo->query("SELECT id FROM {$rule} WHERE name LIKE 'fanshub/paymerchant%' OR name='fanshub/paychannel/balance'")->fetchAll(PDO::FETCH_COLUMN);
$g = $pdo->query("SELECT rules FROM {$prefix}auth_group WHERE id=1")->fetch(PDO::FETCH_ASSOC);
if ($g && $ruleIds) {
    $have = array_flip(array_filter(explode(',', (string)$g['rules'])));
    $missing = [];
    foreach ($ruleIds as $rid) {
        if (!isset($have[$rid])) {
            $missing[] = $rid;
        }
    }
    if ($missing) {
        $new = trim((string)$g['rules'] . ',' . implode(',', $missing), ',');
        $pdo->prepare("UPDATE {$prefix}auth_group SET rules=? WHERE id=1")->execute([$new]);
        echo "GRANTED +" . count($missing) . "\n";
    }
}

// 4) 迁移总商户
$credFile = $root . '/runtime/wanhuipay.credentials.php';
$cred = is_file($credFile) ? include $credFile : [];
$site = rtrim(argvOpt('site', is_array($cred) ? ($cred['site'] ?? '') : ''), '/');
$mch = '';
$priv = '';
$pub = '';
if (is_array($cred)) {
    $mch = trim((string)($cred['merchant_no'] ?? ''));
    $priv = trim((string)($cred['private_key'] ?? ''));
    $pub = trim((string)($cred['platform_public_key'] ?? ''));
}
if ($mch === '') {
    $row = $pdo->query("SELECT merchant_no,config FROM `{$channelTable}` WHERE handler='wanhuitong' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $mch = trim((string)$row['merchant_no']);
        $cfg = json_decode((string)$row['config'], true) ?: [];
        if ($priv === '') {
            $priv = trim((string)($cfg['private_key'] ?? ''));
        }
        if ($pub === '') {
            $pub = trim((string)($cfg['platform_public_key'] ?? ''));
        }
        if ($mch === '') {
            $mch = trim((string)($cfg['merchant_no'] ?? ''));
        }
    }
}

$merchantId = 0;
if ($mch !== '') {
    $st = $pdo->prepare("SELECT id FROM `{$merchantTable}` WHERE gateway='wanhuitong' AND merchant_no=? LIMIT 1");
    $st->execute([$mch]);
    $exist = $st->fetch(PDO::FETCH_ASSOC);
    if ($exist) {
        $merchantId = (int)$exist['id'];
        $pdo->prepare("UPDATE `{$merchantTable}` SET name=?, private_key=IF(?='',private_key,?), platform_public_key=IF(?='',platform_public_key,?), site=IF(?='',site,?), updatetime=? WHERE id=?")
            ->execute(['万汇通主商户', $priv, $priv, $pub, $pub, $site, $site, $now, $merchantId]);
        echo "updated merchant #{$merchantId} {$mch}\n";
    } else {
        $pdo->prepare("INSERT INTO `{$merchantTable}` (name,gateway,merchant_no,private_key,platform_public_key,site,callback_ips,status,remark,createtime,updatetime) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([
                '万汇通主商户', 'wanhuitong', $mch, $priv, $pub, $site,
                '18.162.71.242,95.40.141.160', 'normal', 'migrated', $now, $now,
            ]);
        $merchantId = (int)$pdo->lastInsertId();
        echo "inserted merchant #{$merchantId} {$mch}\n";
    }

    // 挂靠已有通道，并剥离通道内密钥
    $rows = $pdo->query("SELECT id,config,merchant_no,pay_channel FROM `{$channelTable}` WHERE handler='wanhuitong'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $cfg = json_decode((string)$r['config'], true) ?: [];
        $cfg['merchant_id'] = $merchantId;
        $cfg['merchant_no'] = $mch;
        unset($cfg['private_key'], $cfg['platform_public_key'], $cfg['merchant_key'], $cfg['merchant_private_key']);
        $pdo->prepare("UPDATE `{$channelTable}` SET merchant_id=?, merchant_no=?, config=?, updatetime=? WHERE id=?")
            ->execute([$merchantId, $mch, json_encode($cfg, JSON_UNESCAPED_UNICODE), $now, $r['id']]);
        echo "link channel #{$r['id']} {$r['pay_channel']}\n";
    }
} else {
    echo "warn: no merchant_no found to migrate\n";
}

// 5) 可选批量建通道：需要 ThinkPHP 引导较重，这里直接 SQL/简易插入调用同逻辑太散
// 用 --batch=Bobi 或 --all-wallets 时输出提示走后台「批量加通道」
$batch = argvOpt('batch', '');
$all = argvOpt('all-wallets', '');
if ($merchantId > 0 && ($batch !== '' || $all === '1')) {
    require_once $root . '/thinkphp/base.php';
    // 轻量：不启完整 app，直接 include gateway 会依赖 think\Db
    echo "hint: 请后台打开「支付总商户 → 批量加通道」勾选钱包创建；或运行:\n";
    echo "  已迁移总商户 #{$merchantId}，请在后台批量添加钱包通道。\n";
}

echo "DONE. 刷新后台 Ctrl+F5。菜单：支付总商户 → 批量加通道；充提通道选总商户+钱包。\n";
if ($site === '') {
    echo "提示：请编辑总商户填写公网域名 site，以便 notify_url / 反查地址为绝对 HTTPS。\n";
}
