<?php
/**
 * 安装红包自动发抢：表 + 后台菜单
 * php scripts/install_rp_auto.php
 */
$root = dirname(__DIR__);
$envFile = $root . '/.env';
if (!is_file($envFile)) {
    fwrite(STDERR, ".env missing\n");
    exit(1);
}
$env = parse_ini_file($envFile, true);
$d = $env['database'];
$pdo = new PDO(
    "mysql:host={$d['hostname']};dbname={$d['database']};charset=utf8mb4",
    $d['username'],
    $d['password'],
    [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4', PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$sql = file_get_contents($root . '/sql/chat_rp_auto_task.sql');
$pdo->exec($sql);
echo "OK table fa_chat_rp_auto_task\n";

$rule = 'fa_auth_rule';
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

$imId = (int)$pdo->query("SELECT id FROM {$rule} WHERE name='fanshub_im' LIMIT 1")->fetchColumn();
$parentId = $imId ?: (int)$pdo->query("SELECT id FROM {$rule} WHERE name='fanshub' LIMIT 1")->fetchColumn();
if ($parentId <= 0) {
    fwrite(STDERR, "fanshub menu missing\n");
    exit(1);
}

$autoId = ensureMenu($pdo, $insert, $rule, $parentId, 'fanshub/redpacketauto', '红包自动发抢', 'fa fa-magic', 1, 58, $now, '后台任务：自动发包+机器人抢包');
foreach ([
    'index' => '查看',
    'add' => '添加',
    'edit' => '编辑',
    'del' => '删除',
    'multi' => '批量',
    'runonce' => '立即执行',
] as $act => $title) {
    ensurePerm($pdo, $insert, $rule, $autoId, 'fanshub/redpacketauto/' . $act, $title, $now);
}

// 同步超管组权限
$groups = $pdo->query("SELECT id,rules FROM fa_auth_group")->fetchAll(PDO::FETCH_ASSOC);
$ids = $pdo->query("SELECT id FROM {$rule} WHERE name LIKE 'fanshub/redpacketauto%'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($groups as $g) {
    $rules = array_filter(explode(',', (string)$g['rules']));
    $changed = false;
    foreach ($ids as $rid) {
        if (!in_array((string)$rid, $rules, true)) {
            $rules[] = (string)$rid;
            $changed = true;
        }
    }
    if ($changed) {
        $pdo->prepare("UPDATE fa_auth_group SET rules=? WHERE id=?")->execute([implode(',', $rules), $g['id']]);
        echo "OK sync group {$g['id']}\n";
    }
}

echo "DONE. crontab 建议: */1 * * * * php {$root}/think redpacket:auto\n";
