<?php
/**
 * 登录谷歌验证器：账户表 google_secret + 后台菜单
 * php scripts/patch_google_auth_login.php
 */
$root = dirname(__DIR__);
$env = parse_ini_file($root . '/.env', true);
$d = $env['database'] ?? [];
$prefix = $d['prefix'] ?? 'fa_';
$table = $prefix . 'fans_account';
$ruleTable = $prefix . 'auth_rule';
$groupTable = $prefix . 'auth_group';

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $d['hostname'] ?? '127.0.0.1',
        (int)($d['hostport'] ?? 3306),
        $d['database'] ?? ''
    ),
    $d['username'] ?? 'root',
    $d['password'] ?? '',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
    ]
);

$col = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'google_secret'")->fetch(PDO::FETCH_ASSOC);
if ($col) {
    echo "SKIP  {$table}.google_secret already exists ({$col['Type']})\n";
} else {
    $pdo->exec(
        "ALTER TABLE `{$table}`
         ADD COLUMN `google_secret` varchar(64) NOT NULL DEFAULT '' COMMENT '谷歌验证器密钥(Base32)' AFTER `status`"
    );
    echo "OK    {$table}.google_secret added\n";
}

$menuName = 'fanshub/googleauth';
$st = $pdo->prepare("SELECT id FROM `{$ruleTable}` WHERE name = ? LIMIT 1");
$st->execute([$menuName]);
$rid = (int)($st->fetchColumn() ?: 0);
if ($rid > 0) {
    echo "SKIP  auth rule {$menuName} exists id={$rid}\n";
} else {
    $pid = 0;
    foreach (['fanshub', 'fanshub.config'] as $parent) {
        $st->execute([$parent]);
        $pid = (int)($st->fetchColumn() ?: 0);
        if ($pid > 0) {
            break;
        }
    }
    if ($pid <= 0) {
        fwrite(STDERR, "WARN parent menu missing, skip auth rule\n");
    } else {
        $now = time();
        $ins = $pdo->prepare(
            "INSERT INTO `{$ruleTable}`
            (`type`,`pid`,`name`,`title`,`icon`,`condition`,`remark`,`ismenu`,`menutype`,`extend`,`py`,`pinyin`,`createtime`,`updatetime`,`weigh`,`status`)
            VALUES ('file',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $ins->execute([
            $pid, $menuName, '谷歌验证器', 'fa fa-mobile', '',
            '登录验证码可用谷歌动态码；查看/管理密钥', 1, 'addtabs', '', '', '',
            $now, $now, 5, 'normal',
        ]);
        $rid = (int)$pdo->lastInsertId();
        echo "OK    auth rule {$menuName} id={$rid} pid={$pid}\n";
    }
}

if ($rid > 0) {
    // 挂到已有短信配置权限的角色
    $st->execute(['fanshub.sms']);
    $smsId = (int)($st->fetchColumn() ?: 0);
    if ($smsId <= 0) {
        $st->execute(['fanshub/sms']);
        $smsId = (int)($st->fetchColumn() ?: 0);
    }
    if ($smsId > 0) {
        $groups = $pdo->query("SELECT id, rules FROM `{$groupTable}`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($groups as $g) {
            $rules = trim((string)$g['rules']);
            if ($rules === '*') {
                continue;
            }
            $parts = array_filter(array_map('intval', explode(',', $rules)));
            if (!in_array($smsId, $parts, true)) {
                continue;
            }
            if (in_array($rid, $parts, true)) {
                echo "group {$g['id']}: already has googleauth\n";
                continue;
            }
            $parts[] = $rid;
            $pdo->prepare("UPDATE `{$groupTable}` SET rules = ? WHERE id = ?")
                ->execute([implode(',', $parts), (int)$g['id']]);
            echo "group {$g['id']}: added googleauth\n";
        }
    }
}

echo "done\n";
