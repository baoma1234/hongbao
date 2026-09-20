<?php
/**
 * 安装红宝雨机器人：表 + 菜单（紧挨红包自动发抢下方）
 * php scripts/install_rp_rain.php
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

$sql = file_get_contents($root . '/sql/chat_rp_rain_task.sql');
$pdo->exec($sql);
echo "OK table fa_chat_rp_rain_task\n";

$rule = 'fa_auth_rule';
$now = time();

$auto = $pdo->query("SELECT id, pid, weigh FROM {$rule} WHERE name='fanshub/redpacketauto' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$pid = $auto ? (int)$auto['pid'] : 0;
$weigh = $auto ? max(1, (int)$auto['weigh'] - 1) : 57;
if ($pid <= 0) {
    $pid = (int)$pdo->query("SELECT id FROM {$rule} WHERE name='fanshub_play' LIMIT 1")->fetchColumn();
}
if ($pid <= 0) {
    $pid = (int)$pdo->query("SELECT id FROM {$rule} WHERE name='fanshub_im' LIMIT 1")->fetchColumn();
}
if ($pid <= 0) {
    fwrite(STDERR, "parent menu missing\n");
    exit(1);
}

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
        echo "UPD  {$name} (#{$id}) pid={$pid} weigh={$weigh}\n";
        return $id;
    }
    $insert->execute([(int)$pid, $name, $title, $icon, $remark, (int)$ismenu, $now, $now, (int)$weigh]);
    $id = (int)$pdo->lastInsertId();
    echo "ADD  {$name} (#{$id})\n";
    return $id;
}

$rainId = ensureMenu(
    $pdo, $insert, $rule, $pid,
    'fanshub/redpacketrain', '红宝雨机器人', 'fa fa-umbrella', 1, $weigh, $now,
    '按分钟定时发包，机器人限量抢，超时领完'
);
foreach ([
    'index' => '查看',
    'add' => '添加',
    'edit' => '编辑',
    'del' => '删除',
    'multi' => '批量',
    'runonce' => '立即执行',
] as $act => $title) {
    ensureMenu($pdo, $insert, $rule, $rainId, 'fanshub/redpacketrain/' . $act, $title, 'fa fa-circle-o', 0, 0, $now);
}

$ids = $pdo->query("SELECT id FROM {$rule} WHERE name LIKE 'fanshub/redpacketrain%'")->fetchAll(PDO::FETCH_COLUMN);
$seed = $auto ? [(string)$auto['id']] : [];
$groups = $pdo->query("SELECT id, rules FROM fa_auth_group")->fetchAll(PDO::FETCH_ASSOC);
foreach ($groups as $g) {
    $rules = trim((string)$g['rules']);
    if ($rules === '' || $rules === '*') {
        continue;
    }
    $have = array_filter(explode(',', $rules));
    if ($seed && !array_intersect($seed, $have)) {
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

// FastAdmin 侧栏用 Redis 缓存 __menu__；PDO 直写 auth_rule 不会触发 AuthRule::afterWrite
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
        $key = $prefix . '__menu__';
        $n = (int)$rr->del($key);
        echo "CLEARED redis {$key} (db={$select}) del={$n}\n";
    }
} catch (Throwable $e) {
    echo "WARN clear menu redis: " . $e->getMessage() . "\n";
}

echo "DONE rain menu under pid={$pid} weigh={$weigh} (auto weigh=" . ($auto['weigh'] ?? '?') . ")\n";
