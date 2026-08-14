<?php
/**
 * 福利大厅 v20：福利领取工单重命名 + 邀请码偏移 + 会员等级
 * php scripts/patch_fanshub_v20.php
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

define('APP_PATH', $root . '/application/');
require $root . '/thinkphp/base.php';
\think\App::initCommon();

$path = APP_PATH . 'extra' . DS . 'fanshub.php';
$config = is_file($path) ? include $path : [];
if (!is_array($config)) {
    $config = [];
}

$defaults = [
    'invite_code_offset'     => 100000,
    'member_level_enabled'   => false,
    'default_member_level'   => 1,
    'member_levels'          => [
        1 => ['name' => '普通会员', 'invite_reward' => 1.0],
        2 => ['name' => '银牌会员', 'invite_reward' => 2.0],
        3 => ['name' => '金牌会员', 'invite_reward' => 5.0],
    ],
];

$changed = false;
foreach ($defaults as $key => $val) {
    if (!array_key_exists($key, $config)) {
        $config[$key] = $val;
        $changed = true;
    }
}

if ($changed) {
    if (!\app\common\library\FansHubService::saveFanshubConfig($config)) {
        fwrite(STDERR, "save fanshub.php failed\n");
        exit(1);
    }
    echo "OK    fanshub.php invite/member keys merged\n";
} else {
    echo "SKIP  fanshub.php already has v20 keys\n";
}

$table = 'fa_fans_account';
$col = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'member_level'")->fetch();
if ($col) {
    echo "SKIP  {$table}.member_level exists\n";
} else {
    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `member_level` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '会员等级' AFTER `flow_stage`");
    echo "OK    {$table}.member_level added\n";
}

$stmt = $pdo->prepare("UPDATE fa_auth_rule SET title=?, remark=? WHERE name=?");
$stmt->execute(['福利领取工单', '用户福利领取人工审核工单（密令）', 'fanshub/secret']);
echo ($stmt->rowCount() ? 'OK' : 'SKIP') . "    menu fanshub/secret renamed\n";

echo "DONE  invite link: /fanshub?code=user_id+offset\n";
