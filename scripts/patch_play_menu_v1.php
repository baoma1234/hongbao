<?php
/**
 * 玩法大全：独立一级菜单，收纳鱼虾蟹 + 红包/尾数牛牛玩法配置
 * php scripts/patch_play_menu_v1.php
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

function ensureTopMenu(PDO $pdo, string $rule, array $row, int $now): int
{
    $stmt = $pdo->prepare("SELECT id FROM `{$rule}` WHERE `name`=? LIMIT 1");
    $stmt->execute([$row['name']]);
    $id = (int)$stmt->fetchColumn();
    if ($id > 0) {
        $pdo->prepare("UPDATE `{$rule}` SET `pid`=?, `title`=?, `icon`=?, `ismenu`=1, `weigh`=?, `status`='normal', `updatetime`=? WHERE `id`=?")
            ->execute([$row['pid'], $row['title'], $row['icon'], $row['weigh'], $now, $id]);
        echo "UPD  {$row['name']} (#{$id})\n";
        return $id;
    }
    $pdo->prepare("INSERT INTO `{$rule}`
        (`type`,`pid`,`name`,`title`,`icon`,`url`,`condition`,`remark`,`ismenu`,`menutype`,`createtime`,`updatetime`,`weigh`,`status`)
        VALUES ('file',?,?,?,?, '','',?,1,'addtabs',?,?,?,'normal')")
        ->execute([
            $row['pid'], $row['name'], $row['title'], $row['icon'],
            $row['remark'] ?? '', $now, $now, $row['weigh'],
        ]);
    $id = (int)$pdo->lastInsertId();
    echo "ADD  {$row['name']} (#{$id})\n";
    return $id;
}

function moveMenu(PDO $pdo, string $rule, string $name, int $pid, int $weigh, int $now, ?string $title = null): bool
{
    $stmt = $pdo->prepare("SELECT id FROM `{$rule}` WHERE `name`=? LIMIT 1");
    $stmt->execute([$name]);
    $id = (int)$stmt->fetchColumn();
    if ($id <= 0) {
        echo "MISS {$name}\n";
        return false;
    }
    $sql = "UPDATE `{$rule}` SET `pid`=?, `weigh`=?, `ismenu`=1, `updatetime`=?";
    $bind = [$pid, $weigh, $now];
    if ($title !== null) {
        $sql .= ", `title`=?";
        $bind[] = $title;
    }
    $sql .= " WHERE `id`=?";
    $bind[] = $id;
    $pdo->prepare($sql)->execute($bind);
    echo "MOVE {$name} -> pid={$pid} weigh={$weigh}" . ($title ? " title={$title}" : '') . "\n";
    return true;
}

$playId = ensureTopMenu($pdo, $rule, [
    'pid'    => 0,
    'name'   => 'fanshub_play',
    'title'  => '玩法大全',
    'icon'   => 'fa fa-gamepad',
    'weigh'  => 45,
    'remark' => '鱼虾蟹、红包、尾数牛牛等玩法',
], $now);

$playMenus = [
    'fanshub/yxxpool'         => [90, null],
    'fanshub/yxxround'        => [85, null],
    'fanshub/yxxgroup'        => [80, null],
    'fanshub/yxxdailybet'     => [75, null],
    'fanshub/redpacketconfig' => [70, '红包全局配置'],
    'fanshub/niuniuconfig'    => [65, '红包尾数牛牛配置'],
    'fanshub/niuniu'          => [60, '尾数牛牛对局'],
    'fanshub/niuniuauto'      => [55, '尾数牛牛自动对局'],
];

$movedIds = [$playId];
foreach ($playMenus as $name => $meta) {
    if (moveMenu($pdo, $rule, $name, $playId, $meta[0], $now, $meta[1])) {
        $stmt = $pdo->prepare("SELECT id FROM `{$rule}` WHERE `name`=? LIMIT 1");
        $stmt->execute([$name]);
        $mid = (int)$stmt->fetchColumn();
        if ($mid > 0) {
            $movedIds[] = $mid;
        }
    }
}

// 已拥有任一被移动菜单或 fanshub / fanshub_im 的角色组，自动加上「玩法大全」
$fanshubId = (int)$pdo->query("SELECT id FROM `{$rule}` WHERE name='fanshub' LIMIT 1")->fetchColumn();
$imId = (int)$pdo->query("SELECT id FROM `{$rule}` WHERE name='fanshub_im' LIMIT 1")->fetchColumn();
$seedIds = array_filter(array_unique(array_merge($movedIds, [$fanshubId, $imId])));

$groups = $pdo->query("SELECT id, rules FROM `{$group}`")->fetchAll(PDO::FETCH_ASSOC);
foreach ($groups as $g) {
    $rules = trim((string)$g['rules']);
    if ($rules === '' || $rules === '*') {
        continue;
    }
    $ids = array_filter(array_map('intval', explode(',', $rules)));
    $hasAny = false;
    foreach ($seedIds as $sid) {
        if ($sid > 0 && in_array($sid, $ids, true)) {
            $hasAny = true;
            break;
        }
    }
    if (!$hasAny || in_array($playId, $ids, true)) {
        continue;
    }
    $ids[] = $playId;
    $pdo->prepare("UPDATE `{$group}` SET rules=? WHERE id=?")->execute([implode(',', $ids), $g['id']]);
    echo "AUTH group #{$g['id']} +玩法大全\n";
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

echo "DONE play menu v1\n";
echo "  玩法大全 → 鱼虾蟹(4) + 红包全局配置 + 尾数牛牛(3)\n";
