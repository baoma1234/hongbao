<?php
/**
 * 福利大厅 v28：主站 UID 提交后台审核（9 位数字）
 * php scripts/patch_fanshub_v28.php
 */
$root = dirname(__DIR__);
$env = parse_ini_file($root . '/.env', true);
$d = $env['database'];
$pdo = new PDO(
    "mysql:host={$d['hostname']};dbname={$d['database']};charset=utf8mb4",
    $d['username'],
    $d['password'],
    [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4']
);

$table = 'fa_fans_account';
$cols = [
    'main_uid_pending'       => "ALTER TABLE `{$table}` ADD COLUMN `main_uid_pending` varchar(64) NOT NULL DEFAULT '' COMMENT '待审主站UID' AFTER `main_uid`",
    'main_uid_audit'         => "ALTER TABLE `{$table}` ADD COLUMN `main_uid_audit` varchar(16) NOT NULL DEFAULT '' COMMENT 'UID审核状态:pending/approved/rejected' AFTER `main_uid_pending`",
    'main_uid_reject_reason' => "ALTER TABLE `{$table}` ADD COLUMN `main_uid_reject_reason` varchar(255) NOT NULL DEFAULT '' COMMENT 'UID拒绝原因' AFTER `main_uid_audit`",
];
foreach ($cols as $name => $sql) {
    $exists = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($name))->fetch();
    if ($exists) {
        echo "SKIP  {$table}.{$name}\n";
        continue;
    }
    $pdo->exec($sql);
    echo "OK    {$table}.{$name}\n";
}

$migrated = $pdo->exec(
    "UPDATE `{$table}` SET `main_uid_audit`='approved' WHERE `main_uid`<>'' AND (`main_uid_audit`='' OR `main_uid_audit` IS NULL)"
);
echo "OK    migrate existing bound UID -> approved ({$migrated} rows)\n";

$rule = 'fa_auth_rule';
$now = time();
$insert = $pdo->prepare('INSERT INTO fa_auth_rule (type,pid,name,title,icon,url,`condition`,remark,ismenu,menutype,createtime,updatetime,weigh,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$accPid = $pdo->query("SELECT id FROM {$rule} WHERE name='fanshub/account' LIMIT 1")->fetchColumn();
if ($accPid) {
    $perms = [
        ['fanshub/account/approveuid', '通过UID'],
        ['fanshub/account/rejectuid', '拒绝UID'],
    ];
    foreach ($perms as $perm) {
        $name = $perm[0];
        $title = $perm[1];
        if ($pdo->query("SELECT id FROM {$rule} WHERE name=" . $pdo->quote($name) . " LIMIT 1")->fetchColumn()) {
            echo "SKIP  {$name}\n";
            continue;
        }
        $insert->execute(['file', $accPid, $name, $title, 'fa fa-circle-o', '', '', '', 0, null, $now, $now, 0, 'normal']);
        echo "OK    {$name}\n";
    }
} else {
    echo "WARN  fanshub/account menu missing\n";
}

define('APP_PATH', $root . '/application/');
require $root . '/thinkphp/base.php';
\think\App::initCommon();

$config = \think\Config::get('fanshub') ?: [];
if (!is_array($config)) {
    $config = [];
}
$config['main_uid_min_length'] = 9;
$config['main_uid_max_length'] = 9;
$config['main_uid_pattern'] = '/^\\d{9}$/';
if (!\app\common\library\FansHubService::saveFanshubConfig($config)) {
    fwrite(STDERR, "FAIL  save fanshub.php\n");
    exit(1);
}
echo "OK    main_uid length/pattern = 9 digits\n";
echo "DONE  UID submit -> admin approve/reject; re-login admin if new perms not visible\n";
