<?php
/**
 * 福利大厅 v6：合并 H5 文案默认配置 + 后台恢复默认权限
 * php scripts/patch_fanshub_v6.php
 */
$root = dirname(__DIR__);
define('APP_PATH', $root . '/application/');
require $root . '/thinkphp/base.php';
\think\App::initCommon();

$path = APP_PATH . 'extra' . DS . 'fanshub.php';
$config = is_file($path) ? include $path : [];
if (!is_array($config)) {
    $config = [];
}
$saved = isset($config['h5_copy']) && is_array($config['h5_copy']) ? $config['h5_copy'] : [];
$before = count($saved);
$config['h5_copy'] = \app\common\library\FansHubService::mergeH5CopyDefaults($saved);
$after = count($config['h5_copy']);
if (!\app\common\library\FansHubService::saveFanshubConfig($config)) {
    fwrite(STDERR, "save fanshub.php failed\n");
    exit(1);
}
echo "OK    h5_copy merged {$before} -> {$after}\n";

$env = parse_ini_file($root . '/.env', true);
$d = $env['database'];
$pdo = new PDO(
    "mysql:host={$d['hostname']};dbname={$d['database']};charset=utf8mb4",
    $d['username'],
    $d['password'],
    [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4']
);
$rule = ($d['prefix'] ?? 'fa_') . 'auth_rule';
$permName = 'fanshub/config/resetcopy';
$exists = $pdo->query("SELECT id FROM `{$rule}` WHERE name=" . $pdo->quote($permName) . " LIMIT 1")->fetchColumn();
if ($exists) {
    echo "SKIP  {$permName}\n";
} else {
    $parentId = $pdo->query("SELECT id FROM `{$rule}` WHERE name='fanshub/config' LIMIT 1")->fetchColumn();
    if ($parentId) {
        $now = time();
        $stmt = $pdo->prepare("INSERT INTO `{$rule}` (`type`,`pid`,`name`,`title`,`icon`,`url`,`condition`,`remark`,`ismenu`,`menutype`,`createtime`,`updatetime`,`weigh`,`status`) VALUES ('file',?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$parentId, $permName, '恢复默认文案', 'fa fa-circle-o', '', '', '', 0, null, $now, $now, 0, 'normal']);
        echo "OK    {$permName}\n";
    } else {
        echo "WARN  fanshub/config menu not found\n";
    }
}

echo "Done.\n";
