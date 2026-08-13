<?php
/**
 * 拆分福利大厅菜单：会员运营 / 即时通讯 独立一级菜单
 * php scripts/reorganize_fanshub_menus.php
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
        $upd = $pdo->prepare("UPDATE `{$rule}` SET `pid`=?, `title`=?, `icon`=?, `ismenu`=?, `weigh`=?, `status`='normal', `updatetime`=? WHERE `id`=?");
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

function moveMenu(PDO $pdo, string $rule, string $name, int $pid, int $weigh, int $now, ?string $title = null): void
{
    $sql = "UPDATE `{$rule}` SET `pid`=?, `weigh`=?, `updatetime`=?";
    $bind = [$pid, $weigh, $now];
    if ($title !== null) {
        $sql .= ", `title`=?";
        $bind[] = $title;
    }
    $sql .= " WHERE `name`=?";
    $bind[] = $name;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($bind);
    echo "MOVE {$name} -> pid={$pid} weigh={$weigh}\n";
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
        VALUES ('file',?,?,?,'fa fa-circle','','','',0,NULL,?,?,0,'normal')");
    $ins->execute([$pid, $name, $title, $now, $now]);
    echo "ADD  perm {$name}\n";
}

$fanshubId = (int)$pdo->query("SELECT id FROM `{$rule}` WHERE name='fanshub' LIMIT 1")->fetchColumn();
if ($fanshubId <= 0) {
    fwrite(STDERR, "fanshub root missing\n");
    exit(1);
}

// 一级：福利大厅（瘦身）
$pdo->prepare("UPDATE `{$rule}` SET title=?, icon=?, weigh=?, updatetime=? WHERE id=?")->execute([
    '福利大厅', 'fa fa-gift', 48, $now, $fanshubId,
]);

$memberId = ensureMenu($pdo, $rule, [
    'pid' => 0, 'name' => 'fanshub_member', 'title' => '会员运营',
    'icon' => 'fa fa-users', 'ismenu' => 1, 'weigh' => 47, 'remark' => '账户/流水/审核',
], $now);

$imId = ensureMenu($pdo, $rule, [
    'pid' => 0, 'name' => 'fanshub_im', 'title' => '即时通讯',
    'icon' => 'fa fa-comments', 'ismenu' => 1, 'weigh' => 46, 'remark' => 'IM 代聊与群组',
], $now);

// 留在福利大厅（活动配置为父级；短信挂在活动配置下，见 split_activity_config_menu.php）
$keepWelfare = [
    'fanshub/index' => 100,
    'fanshub/secret' => 90,
    'fanshub.config' => 80,
    'fanshub.memberlevel' => 70,
    'fanshub/checkin' => 60,
    'fanshub/task' => 50,
    'fanshub/invite' => 40,
    'fanshub/comment' => 30,
];
foreach ($keepWelfare as $name => $weigh) {
    moveMenu($pdo, $rule, $name, $fanshubId, $weigh, $now);
}
$configParentId = (int)$pdo->query("SELECT id FROM `{$rule}` WHERE name='fanshub.config' LIMIT 1")->fetchColumn();
if ($configParentId > 0) {
    moveMenu($pdo, $rule, 'fanshub.sms', $configParentId, 20, $now, '短信配置');
}

// 会员运营
$memberMenus = [
    'fanshub/account' => [90, null],
    'fanshub/ledger' => [80, null],
    'fanshub/uidaudit' => [70, null],
    'fanshub/loginlog' => [60, null],
];
foreach ($memberMenus as $name => $meta) {
    moveMenu($pdo, $rule, $name, $memberId, $meta[0], $now, $meta[1]);
}

// 即时通讯
moveMenu($pdo, $rule, 'fanshub/imagent', $imId, 90, $now, 'IM代聊');
moveMenu($pdo, $rule, 'fanshub/imgroup', $imId, 80, $now, 'IM群组');
// 查看聊天记录：可见菜单挂在「即时通讯」下
ensurePerm($pdo, $rule, $imId, 'fanshub/imagent/messages', '查看聊天记录', $now);
$msgMenuId = (int)$pdo->query("SELECT id FROM `{$rule}` WHERE name='fanshub/imagent/messages' LIMIT 1")->fetchColumn();
if ($msgMenuId > 0) {
    $pdo->prepare("UPDATE `{$rule}` SET pid=?, title='查看聊天记录', icon='fa fa-history', ismenu=1, weigh=85, status='normal', updatetime=? WHERE id=?")
        ->execute([$imId, $now, $msgMenuId]);
}

// 发红包权限挂到 imagent 下
$imagentId = (int)$pdo->query("SELECT id FROM `{$rule}` WHERE name='fanshub/imagent' LIMIT 1")->fetchColumn();
if ($imagentId > 0) {
    ensurePerm($pdo, $rule, $imagentId, 'fanshub/imagent/sendredpacket', '发红包', $now);
    ensurePerm($pdo, $rule, $imagentId, 'fanshub/imagent/send', '代发消息', $now);
    ensurePerm($pdo, $rule, $imagentId, 'fanshub/imagent/conversations', '会话列表', $now);
    ensurePerm($pdo, $rule, $imagentId, 'fanshub/imagent/history', '会话历史', $now);
}

// 把新一级菜单授权给已拥有 fanshub 的角色组
$groups = $pdo->query("SELECT id, rules FROM `{$group}`")->fetchAll(PDO::FETCH_ASSOC);
foreach ($groups as $g) {
    $rules = trim((string)$g['rules']);
    if ($rules === '' || $rules === '*') {
        continue;
    }
    $ids = array_filter(array_map('intval', explode(',', $rules)));
    if (!in_array($fanshubId, $ids, true)) {
        continue;
    }
    $changed = false;
    foreach ([$memberId, $imId] as $nid) {
        if ($nid > 0 && !in_array($nid, $ids, true)) {
            $ids[] = $nid;
            $changed = true;
        }
    }
    if ($imagentId > 0) {
        $rpId = (int)$pdo->query("SELECT id FROM `{$rule}` WHERE name='fanshub/imagent/sendredpacket' LIMIT 1")->fetchColumn();
        if ($rpId > 0 && in_array($imagentId, $ids, true) && !in_array($rpId, $ids, true)) {
            $ids[] = $rpId;
            $changed = true;
        }
    }
    if ($changed) {
        $pdo->prepare("UPDATE `{$group}` SET rules=? WHERE id=?")->execute([implode(',', $ids), $g['id']]);
        echo "AUTH group #{$g['id']} updated\n";
    }
}

// 清菜单缓存
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

echo "DONE. New layout:\n";
echo "  福利大厅 → 总览/工单/活动配置(子功能)/等级/签到/任务/邀请\n";
echo "  会员运营 → 账户/流水/游戏账号审核/登录日志\n";
echo "  即时通讯 → IM代聊/IM群组\n";
