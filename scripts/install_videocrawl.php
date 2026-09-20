<?php
/**
 * 安装视频采集管理：表 + 菜单（即时通讯下，靠近视频发送）
 * php scripts/install_videocrawl.php
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

$sql = file_get_contents($root . '/sql/chat_video_crawl_task.sql');
foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
    if ($stmt === '') {
        continue;
    }
    $pdo->exec($stmt);
}
echo "OK tables fa_chat_video_crawl_task / fa_chat_video_crawl_sent\n";

$rule = 'fa_auth_rule';
$now = time();
$imId = (int)$pdo->query("SELECT id FROM {$rule} WHERE name='fanshub_im' LIMIT 1")->fetchColumn();
$parentId = $imId ?: (int)$pdo->query("SELECT id FROM {$rule} WHERE name='fanshub' LIMIT 1")->fetchColumn();
if ($parentId <= 0) {
    fwrite(STDERR, "parent menu missing\n");
    exit(1);
}

$videosend = $pdo->query("SELECT id, weigh FROM {$rule} WHERE name='fanshub/videosend' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$weigh = $videosend ? max(1, (int)$videosend['weigh'] - 1) : 72;

$insert = $pdo->prepare(
    "INSERT INTO {$rule} (type,pid,name,title,icon,url,`condition`,remark,ismenu,menutype,createtime,updatetime,weigh,status)
     VALUES ('file',?,?,?,?, '', '', ?, ?, 'addtabs', ?, ?, ?, 'normal')"
);

function ensureMenu(PDO $pdo, $insert, $rule, $pid, $name, $title, $icon, $ismenu, $weigh, $now, $remark = '')
{
    $id = (int)$pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($name) . " LIMIT 1")->fetchColumn();
    if ($id > 0) {
        $pdo->prepare("UPDATE {$rule} SET pid=?, title=?, icon=?, ismenu=?, weigh=?, status='normal', updatetime=?, remark=? WHERE id=?")
            ->execute([(int)$pid, $title, $icon, (int)$ismenu, (int)$weigh, $now, $remark, $id]);
        echo "UPD  {$name} (#{$id})\n";
        return $id;
    }
    $insert->execute([(int)$pid, $name, $title, $icon, $remark, (int)$ismenu, $now, $now, (int)$weigh]);
    $id = (int)$pdo->lastInsertId();
    echo "ADD  {$name} (#{$id})\n";
    return $id;
}

$menuId = ensureMenu(
    $pdo, $insert, $rule, $parentId,
    'fanshub/videocrawl', '视频采集', 'fa fa-cloud-download', 1, $weigh, $now,
    '倒序采集 json 源并发送到群'
);
foreach ([
    'index' => '查看',
    'add' => '添加',
    'edit' => '编辑',
    'del' => '删除',
    'multi' => '批量',
    'runonce' => '采集一页',
    'resetpage' => '重置进度',
] as $act => $title) {
    ensureMenu($pdo, $insert, $rule, $menuId, 'fanshub/videocrawl/' . $act, $title, 'fa fa-circle-o', 0, 0, $now);
}

$ids = $pdo->query("SELECT id FROM {$rule} WHERE name LIKE 'fanshub/videocrawl%'")->fetchAll(PDO::FETCH_COLUMN);
$vsId = (int)$pdo->query("SELECT id FROM {$rule} WHERE name='fanshub/videosend' LIMIT 1")->fetchColumn();
$groups = $pdo->query("SELECT id, rules FROM fa_auth_group")->fetchAll(PDO::FETCH_ASSOC);
foreach ($groups as $g) {
    $rules = trim((string)$g['rules']);
    if ($rules === '' || $rules === '*') {
        continue;
    }
    $have = array_filter(explode(',', $rules));
    // 已有视频发送或即时通讯菜单的角色组自动加采集权限
    $eligible = ($vsId > 0 && in_array((string)$vsId, $have, true))
        || ($imId > 0 && in_array((string)$imId, $have, true));
    if (!$eligible) {
        continue;
    }
    $changed = false;
    foreach ($ids as $rid) {
        if (!in_array((string)$rid, $have, true)) {
            $have[] = (string)$rid;
            $changed = true;
        }
    }
    if ($changed) {
        $pdo->prepare("UPDATE fa_auth_group SET rules=? WHERE id=?")->execute([implode(',', $have), $g['id']]);
        echo "OK sync group {$g['id']}\n";
    }
}

$g1 = $pdo->query("SELECT id, rules FROM fa_auth_group WHERE id=1")->fetch(PDO::FETCH_ASSOC);
if ($g1 && strpos((string)$g1['rules'], '*') === 0) {
    echo "group#1 has * (all menus)\n";
}

// clear redis menu
try {
    $redisCfg = $env['redis'] ?? [];
    $cacheCfg = $env['cache'] ?? [];
    if (class_exists('Redis')) {
        $rr = new Redis();
        $rr->connect((string)($redisCfg['host'] ?? '127.0.0.1'), (int)($redisCfg['port'] ?? 6379), 2);
        $pass = (string)($redisCfg['password'] ?? '');
        if ($pass !== '') {
            $rr->auth($pass);
        }
        $select = (int)($cacheCfg['select'] ?? 1);
        $prefix = (string)($cacheCfg['prefix'] ?? 'tp:');
        $rr->select($select);
        $n = (int)$rr->del($prefix . '__menu__');
        echo "CLEARED redis {$prefix}__menu__ (db={$select}) del={$n}\n";
    }
} catch (Throwable $e) {
    echo "WARN clear menu redis: " . $e->getMessage() . "\n";
}

echo "DONE. crontab: */5 * * * * php {$root}/think videocrawl:run\n";
