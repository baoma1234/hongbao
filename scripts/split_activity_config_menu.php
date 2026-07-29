<?php
/**
 * 活动配置拆分为父级 + 子菜单
 * php scripts/split_activity_config_menu.php
 */
$root = dirname(__DIR__);
$env = is_file($root . '/.env') ? parse_ini_file($root . '/.env', true) : [];
$d = $env['database'] ?? [];
$host = $d['hostname'] ?? '127.0.0.1';
$port = $d['hostport'] ?? '3306';
$db = $d['database'] ?? '';
$user = $d['username'] ?? 'root';
$pass = $d['password'] ?? '';
$prefix = $d['prefix'] ?? 'fa_';

if ($db === '') {
    fwrite(STDERR, "database not configured\n");
    exit(1);
}

$pdo = new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$rule = $prefix . 'auth_rule';
$group = $prefix . 'auth_group';
$now = time();

function ensureMenu(PDO $pdo, string $rule, array $row, int $now): int
{
    $stmt = $pdo->prepare("SELECT id FROM `{$rule}` WHERE `name`=? LIMIT 1");
    $stmt->execute([$row['name']]);
    $id = (int)$stmt->fetchColumn();
    if ($id > 0) {
        $upd = $pdo->prepare("UPDATE `{$rule}` SET `pid`=?, `title`=?, `icon`=?, `ismenu`=?, `weigh`=?, `status`='normal', `menutype`='addtabs', `updatetime`=? WHERE `id`=?");
        $upd->execute([
            $row['pid'], $row['title'], $row['icon'], $row['ismenu'], $row['weigh'], $now, $id,
        ]);
        echo "UPD  {$row['name']} (#{$id})\n";
        return $id;
    }
    $ins = $pdo->prepare("INSERT INTO `{$rule}`
        (`type`,`pid`,`name`,`title`,`icon`,`url`,`condition`,`remark`,`ismenu`,`menutype`,`createtime`,`updatetime`,`weigh`,`status`)
        VALUES ('file',?,?,?,?, '','',?,?, 'addtabs',?,?,?,'normal')");
    $ins->execute([
        $row['pid'], $row['name'], $row['title'], $row['icon'],
        $row['remark'] ?? '', $row['ismenu'], $now, $now, $row['weigh'],
    ]);
    $id = (int)$pdo->lastInsertId();
    echo "ADD  {$row['name']} (#{$id})\n";
    return $id;
}

function ensurePerm(PDO $pdo, string $rule, int $pid, string $name, string $title, int $now): void
{
    $stmt = $pdo->prepare("SELECT id FROM `{$rule}` WHERE `name`=? LIMIT 1");
    $stmt->execute([$name]);
    if ((int)$stmt->fetchColumn() > 0) {
        echo "SKIP perm {$name}\n";
        return;
    }
    $ins = $pdo->prepare("INSERT INTO `{$rule}`
        (`type`,`pid`,`name`,`title`,`icon`,`url`,`condition`,`remark`,`ismenu`,`menutype`,`createtime`,`updatetime`,`weigh`,`status`)
        VALUES ('file',?,?,?,'fa fa-circle-o','','','',0,NULL,?,?,0,'normal')");
    $ins->execute([$pid, $name, $title, $now, $now]);
    echo "ADD  perm {$name}\n";
}

$fanshubId = (int)$pdo->query("SELECT id FROM `{$rule}` WHERE name='fanshub' LIMIT 1")->fetchColumn();
if ($fanshubId <= 0) {
    fwrite(STDERR, "fanshub root missing\n");
    exit(1);
}

// 父级：活动配置（保留原 fanshub.config 节点，挂在福利大厅下）
$parentId = ensureMenu($pdo, $rule, [
    'pid' => $fanshubId,
    'name' => 'fanshub.config',
    'title' => '活动配置',
    'icon' => 'fa fa-cog',
    'ismenu' => 1,
    'weigh' => 80,
    'remark' => '活动参数分栏配置',
], $now);

$children = [
    ['fanshub.config/basic', '基础参数', 'fa fa-sliders', 90],
    ['fanshub.config/exchange', '资产闪兑', 'fa fa-exchange', 80],
    ['fanshub.config/invite', '邀请分享', 'fa fa-share-alt', 70],
    ['fanshub.config/copy', 'H5文案', 'fa fa-file-text-o', 60],
    ['fanshub.config/market', '大盘控盘', 'fa fa-line-chart', 50],
    ['fanshub.config/security', '安全校验', 'fa fa-shield', 40],
    ['fanshub.config/i18n', '多语言', 'fa fa-language', 30],
];

$childIds = [];
foreach ($children as $c) {
    $childIds[] = ensureMenu($pdo, $rule, [
        'pid' => $parentId,
        'name' => $c[0],
        'title' => $c[1],
        'icon' => $c[2],
        'ismenu' => 1,
        'weigh' => $c[3],
        'remark' => '',
    ], $now);
}

// 短信配置挂到活动配置下
$smsId = (int)$pdo->query("SELECT id FROM `{$rule}` WHERE name='fanshub.sms' LIMIT 1")->fetchColumn();
if ($smsId > 0) {
    $pdo->prepare("UPDATE `{$rule}` SET pid=?, weigh=?, title=?, updatetime=? WHERE id=?")
        ->execute([$parentId, 20, '短信配置', $now, $smsId]);
    echo "MOVE fanshub.sms -> 活动配置\n";
    $childIds[] = $smsId;
}

// 权限节点（非菜单）
ensurePerm($pdo, $rule, $parentId, 'fanshub.config/index', '配置总览', $now);
ensurePerm($pdo, $rule, $parentId, 'fanshub.config/save', '保存', $now);
ensurePerm($pdo, $rule, $parentId, 'fanshub.config/resetcopy', '恢复默认文案', $now);
ensurePerm($pdo, $rule, $parentId, 'fanshub.config/checklist', '生产检查', $now);
ensurePerm($pdo, $rule, $parentId, 'fanshub.config/testuidverify', '测试UID校验', $now);
ensurePerm($pdo, $rule, $parentId, 'fanshub.config/resetjackpot', '重置奖池', $now);
ensurePerm($pdo, $rule, $parentId, 'fanshub.config/savei18n', '保存多语言', $now);

// 授权：拥有原活动配置的角色组，补齐新子菜单
$parentRow = $pdo->query("SELECT id FROM `{$rule}` WHERE name='fanshub.config' LIMIT 1")->fetchColumn();
$allNewIds = array_filter(array_map('intval', array_merge([(int)$parentRow], $childIds)));
$extra = $pdo->query("SELECT id FROM `{$rule}` WHERE name LIKE 'fanshub.config/%'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($extra as $eid) {
    $allNewIds[] = (int)$eid;
}
$allNewIds = array_values(array_unique($allNewIds));

$groups = $pdo->query("SELECT id, rules FROM `{$group}`")->fetchAll(PDO::FETCH_ASSOC);
foreach ($groups as $g) {
    $rules = trim((string)$g['rules']);
    if ($rules === '' || $rules === '*') {
        continue;
    }
    $ids = array_filter(array_map('intval', explode(',', $rules)));
    if (!in_array((int)$parentId, $ids, true) && !in_array($fanshubId, $ids, true)) {
        continue;
    }
    $changed = false;
    foreach ($allNewIds as $nid) {
        if ($nid > 0 && !in_array($nid, $ids, true)) {
            $ids[] = $nid;
            $changed = true;
        }
    }
    if ($changed) {
        $pdo->prepare("UPDATE `{$group}` SET rules=? WHERE id=?")->execute([implode(',', $ids), $g['id']]);
        echo "AUTH group #{$g['id']} updated\n";
    }
}

$cacheDir = $root . '/runtime/cache';
if (is_dir($cacheDir)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cacheDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        if ($f->isFile()) {
            @unlink($f->getPathname());
        }
    }
    echo "CLEARED runtime/cache\n";
}

echo "DONE. 活动配置子菜单:\n";
echo "  基础参数 / 资产闪兑 / 邀请分享 / H5文案 / 大盘控盘 / 安全校验 / 多语言 / 短信配置\n";
