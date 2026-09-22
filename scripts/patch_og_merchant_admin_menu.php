<?php
/**
 * 后台菜单：三方游戏 → OG视讯商户配置
 * php scripts/patch_og_merchant_admin_menu.php
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
$prefix = $d['prefix'] ?? 'fa_';
$now = time();
$rule = $prefix . 'auth_rule';
$insert = $pdo->prepare(
    "INSERT INTO {$rule} (type,pid,name,title,icon,url,`condition`,remark,ismenu,menutype,createtime,updatetime,weigh,status)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
);

function ensureMenu(PDO $pdo, $insert, $rule, $pid, $name, $title, $icon, $ismenu, $weigh, $now, $remark = '')
{
    $id = $pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($name) . " LIMIT 1")->fetchColumn();
    if ($id) {
        $pdo->prepare("UPDATE {$rule} SET title=?, icon=?, pid=?, ismenu=?, weigh=?, updatetime=?, remark=? WHERE id=?")
            ->execute([$title, $icon, (int)$pid, (int)$ismenu, (int)$weigh, $now, $remark, (int)$id]);
        echo "OK   update menu {$name}\n";
        return (int)$id;
    }
    $insert->execute([
        'file', (int)$pid, $name, $title, $icon, '', '', $remark,
        (int)$ismenu, null, $now, $now, (int)$weigh, 'normal',
    ]);
    echo "OK   menu {$name}\n";
    return (int)$pdo->lastInsertId();
}

// 顶级：三方游戏
$topId = ensureMenu(
    $pdo,
    $insert,
    $rule,
    0,
    'fanshub_thirdgame',
    '三方游戏',
    'fa fa-globe',
    1,
    35,
    $now,
    '第三方游戏商户与对接'
);

$ogId = ensureMenu(
    $pdo,
    $insert,
    $rule,
    $topId,
    'fanshub/ogmerchant',
    'OG视讯商户配置',
    'fa fa-video-camera',
    1,
    10,
    $now,
    'OG视讯商户凭证与网关'
);
ensureMenu($pdo, $insert, $rule, $ogId, 'fanshub/ogmerchant/index', '查看', 'fa fa-circle-o', 0, 0, $now);
ensureMenu($pdo, $insert, $rule, $ogId, 'fanshub/ogmerchant/save', '保存', 'fa fa-circle-o', 0, 0, $now);

$group = $pdo->query("SELECT id,rules FROM {$prefix}auth_group WHERE id=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($group) {
    $rules = array_filter(explode(',', (string)$group['rules']));
    $need = $pdo->query(
        "SELECT id FROM {$rule} WHERE name IN (
            'fanshub_thirdgame',
            'fanshub/ogmerchant','fanshub/ogmerchant/index','fanshub/ogmerchant/save'
        )"
    )->fetchAll(PDO::FETCH_COLUMN);
    $changed = false;
    foreach ($need as $rid) {
        $rid = (string)$rid;
        if ($group['rules'] === '*') {
            break;
        }
        if (!in_array($rid, $rules, true)) {
            $rules[] = $rid;
            $changed = true;
        }
    }
    if ($changed) {
        $pdo->prepare("UPDATE {$prefix}auth_group SET rules=? WHERE id=1")->execute([implode(',', $rules)]);
        echo "OK   auth_group#1 rules updated\n";
    } else {
        echo "SKIP auth_group#1 (already * or has rules)\n";
    }
}

// 清菜单缓存
try {
    $redisHost = $env['redis']['host'] ?? '127.0.0.1';
    $redisPort = (int)($env['redis']['port'] ?? 6379);
    $redis = new Redis();
    if (@$redis->connect($redisHost, $redisPort, 1.5)) {
        $keys = $redis->keys('*__menu__*');
        if ($keys) {
            $redis->del($keys);
            echo 'OK   cleared ' . count($keys) . " menu cache key(s)\n";
        }
    }
} catch (Throwable $e) {
    echo "SKIP redis menu cache: " . $e->getMessage() . "\n";
}

echo "DONE OG merchant admin menu\n";
