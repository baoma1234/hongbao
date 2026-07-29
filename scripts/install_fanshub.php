<?php
/**
 * 福利大厅安装（可重复执行）
 * php scripts/install_fanshub.php
 */
$root = dirname(__DIR__);
$envFile = $root . '/.env';
$env = is_file($envFile) ? parse_ini_file($envFile, true) : [];
$dbConf = $env['database'] ?? $env;

$host = $dbConf['hostname'] ?? '127.0.0.1';
$port = $dbConf['hostport'] ?? '3306';
$db   = $dbConf['database'] ?? '';
$user = $dbConf['username'] ?? 'root';
$pass = $dbConf['password'] ?? '';
$prefix = $dbConf['prefix'] ?? 'fa_';

if ($db === '') {
    fwrite(STDERR, "database not configured in .env\n");
    exit(1);
}

$dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
]);

function cleanSqlStatement(string $sql): string
{
    $lines = preg_split('/\R/', $sql);
    $out = [];
    foreach ($lines as $line) {
        $trim = ltrim($line);
        if ($trim === '' || strpos($trim, '--') === 0) {
            continue;
        }
        $out[] = $line;
    }
    return trim(implode("\n", $out));
}

function installMenus(PDO $pdo, string $prefix): void
{
    $rule = $prefix . 'auth_rule';
    $now = time();

    $exists = $pdo->query("SELECT COUNT(*) FROM `{$rule}` WHERE `name`='fanshub'")->fetchColumn();
    if ($exists) {
        echo "SKIP  admin menus already exist\n";
        return;
    }

    $pdo->exec("DELETE FROM `{$rule}` WHERE `name` LIKE 'fanshub%'");

    $insert = function (array $row) use ($pdo, $rule, $now) {
        $stmt = $pdo->prepare("INSERT INTO `{$rule}`
            (`type`,`pid`,`name`,`title`,`icon`,`url`,`condition`,`remark`,`ismenu`,`menutype`,`createtime`,`updatetime`,`weigh`,`status`)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $row['type'], $row['pid'], $row['name'], $row['title'], $row['icon'],
            $row['url'] ?? '', $row['condition'] ?? '', $row['remark'] ?? '',
            $row['ismenu'], $row['menutype'] ?? null, $now, $now, $row['weigh'], 'normal',
        ]);
        return (int)$pdo->lastInsertId();
    };

    $rootId = $insert([
        'type' => 'file', 'pid' => 0, 'name' => 'fanshub', 'title' => '福利大厅',
        'icon' => 'fa fa-gift', 'remark' => 'tiyuv7福利活动', 'ismenu' => 1, 'menutype' => 'addtabs', 'weigh' => 48,
    ]);

    $insert([
        'type' => 'file', 'pid' => $rootId, 'name' => 'fanshub/index', 'title' => '运营总览',
        'icon' => 'fa fa-line-chart', 'ismenu' => 1, 'menutype' => 'addtabs', 'weigh' => 11,
    ]);

    $accountId = $insert([
        'type' => 'file', 'pid' => $rootId, 'name' => 'fanshub/account', 'title' => '用户账户',
        'icon' => 'fa fa-user', 'ismenu' => 1, 'menutype' => 'addtabs', 'weigh' => 10,
    ]);
    $ledgerId = $insert([
        'type' => 'file', 'pid' => $rootId, 'name' => 'fanshub/ledger', 'title' => '资产流水',
        'icon' => 'fa fa-list', 'ismenu' => 1, 'menutype' => 'addtabs', 'weigh' => 9,
    ]);
    $secretId = $insert([
        'type' => 'file', 'pid' => $rootId, 'name' => 'fanshub/secret', 'title' => '福利领取工单',
        'icon' => 'fa fa-key', 'remark' => '福利领取人工审核工单', 'ismenu' => 1, 'menutype' => 'addtabs', 'weigh' => 8,
    ]);
    $configId = $insert([
        'type' => 'file', 'pid' => $rootId, 'name' => 'fanshub.config', 'title' => '活动配置',
        'icon' => 'fa fa-cog', 'ismenu' => 1, 'menutype' => 'addtabs', 'weigh' => 7,
    ]);
    $smsId = $insert([
        'type' => 'file', 'pid' => $rootId, 'name' => 'fanshub.sms', 'title' => '短信配置',
        'icon' => 'fa fa-envelope', 'ismenu' => 1, 'menutype' => 'addtabs', 'weigh' => 6,
    ]);
    $memberlevelId = $insert([
        'type' => 'file', 'pid' => $rootId, 'name' => 'fanshub.memberlevel', 'title' => '会员等级配置',
        'icon' => 'fa fa-star', 'ismenu' => 1, 'menutype' => 'addtabs', 'weigh' => 6,
    ]);
    $commentId = $insert([
        'type' => 'file', 'pid' => $rootId, 'name' => 'fanshub/comment', 'title' => '留言管理',
        'icon' => 'fa fa-comments', 'ismenu' => 1, 'menutype' => 'addtabs', 'weigh' => 5,
    ]);
    $inviteMenuId = $insert([
        'type' => 'file', 'pid' => $rootId, 'name' => 'fanshub/invite', 'title' => '邀请记录',
        'icon' => 'fa fa-share-alt', 'ismenu' => 1, 'menutype' => 'addtabs', 'weigh' => 4,
    ]);
    $taskMenuId = $insert([
        'type' => 'file', 'pid' => $rootId, 'name' => 'fanshub/task', 'title' => '任务记录',
        'icon' => 'fa fa-tasks', 'ismenu' => 1, 'menutype' => 'addtabs', 'weigh' => 3,
    ]);
    $checkinMenuId = $insert([
        'type' => 'file', 'pid' => $rootId, 'name' => 'fanshub/checkin', 'title' => '签到记录',
        'icon' => 'fa fa-calendar-check-o', 'remark' => '星火二期签到', 'ismenu' => 1, 'menutype' => 'addtabs', 'weigh' => 3,
    ]);
    $loginlogMenuId = $insert([
        'type' => 'file', 'pid' => $rootId, 'name' => 'fanshub/loginlog', 'title' => '登录日志',
        'icon' => 'fa fa-sign-in', 'ismenu' => 1, 'menutype' => 'addtabs', 'weigh' => 2,
    ]);

    foreach ([
        ['pid' => $accountId, 'name' => 'fanshub/account/index', 'title' => '查看'],
        ['pid' => $accountId, 'name' => 'fanshub/account/edit', 'title' => '编辑'],
        ['pid' => $accountId, 'name' => 'fanshub/account/adjust', 'title' => '人工调账'],
        ['pid' => $accountId, 'name' => 'fanshub/account/promotemaster', 'title' => '晋升团长'],
        ['pid' => $accountId, 'name' => 'fanshub/account/detail', 'title' => '详情'],
        ['pid' => $ledgerId, 'name' => 'fanshub/ledger/index', 'title' => '查看'],
        ['pid' => $secretId, 'name' => 'fanshub/secret/index', 'title' => '查看'],
        ['pid' => $secretId, 'name' => 'fanshub/secret/edit', 'title' => '编辑'],
        ['pid' => $secretId, 'name' => 'fanshub/secret/export', 'title' => '导出'],
        ['pid' => $configId, 'name' => 'fanshub.config/index', 'title' => '查看'],
        ['pid' => $configId, 'name' => 'fanshub.config/save', 'title' => '保存'],
        ['pid' => $configId, 'name' => 'fanshub.config/resetcopy', 'title' => '恢复默认文案'],
        ['pid' => $configId, 'name' => 'fanshub.config/checklist', 'title' => '上线检查'],
        ['pid' => $configId, 'name' => 'fanshub.config/testuidverify', 'title' => 'UID校验测试'],
        ['pid' => $configId, 'name' => 'fanshub.config/resetjackpot', 'title' => '重置奖池'],
        ['pid' => $configId, 'name' => 'fanshub.config/i18n', 'title' => '多语言编辑'],
        ['pid' => $configId, 'name' => 'fanshub.config/savei18n', 'title' => '保存多语言'],
        ['pid' => $smsId, 'name' => 'fanshub.sms/index', 'title' => '查看'],
        ['pid' => $smsId, 'name' => 'fanshub.sms/save', 'title' => '保存'],
        ['pid' => $smsId, 'name' => 'fanshub.sms/testdagousms', 'title' => '大狗测试'],
        ['pid' => $smsId, 'name' => 'fanshub.sms/dagoubalance', 'title' => '大狗余额'],
        ['pid' => $smsId, 'name' => 'fanshub.sms/testunisms', 'title' => '国际测试'],
        ['pid' => $smsId, 'name' => 'fanshub.sms/unabalance', 'title' => '国际余额'],
        ['pid' => $memberlevelId, 'name' => 'fanshub.memberlevel/index', 'title' => '查看'],
        ['pid' => $memberlevelId, 'name' => 'fanshub.memberlevel/save', 'title' => '保存'],
        ['pid' => $commentId, 'name' => 'fanshub/comment/index', 'title' => '查看'],
        ['pid' => $commentId, 'name' => 'fanshub/comment/edit', 'title' => '编辑'],
        ['pid' => $commentId, 'name' => 'fanshub/comment/del', 'title' => '删除'],
        ['pid' => $commentId, 'name' => 'fanshub/comment/approve', 'title' => '通过'],
        ['pid' => $commentId, 'name' => 'fanshub/comment/reject', 'title' => '拒绝'],
        ['pid' => $commentId, 'name' => 'fanshub/comment/export', 'title' => '导出'],
        ['pid' => $inviteMenuId, 'name' => 'fanshub/invite/index', 'title' => '查看'],
        ['pid' => $inviteMenuId, 'name' => 'fanshub/invite/export', 'title' => '导出'],
        ['pid' => $inviteMenuId, 'name' => 'fanshub/invite/leaderboard', 'title' => '邀请排行榜', 'ismenu' => 1, 'icon' => 'fa fa-trophy', 'weigh' => 1],
        ['pid' => $ledgerId, 'name' => 'fanshub/ledger/export', 'title' => '导出'],
        ['pid' => $taskMenuId, 'name' => 'fanshub/task/export', 'title' => '导出'],
        ['pid' => $accountId, 'name' => 'fanshub/account/export', 'title' => '导出'],
        ['pid' => $taskMenuId, 'name' => 'fanshub/task/index', 'title' => '查看'],
        ['pid' => $checkinMenuId, 'name' => 'fanshub/checkin/index', 'title' => '查看'],
        ['pid' => $checkinMenuId, 'name' => 'fanshub/checkin/export', 'title' => '导出'],
        ['pid' => $loginlogMenuId, 'name' => 'fanshub/loginlog/index', 'title' => '查看'],
        ['pid' => $loginlogMenuId, 'name' => 'fanshub/loginlog/export', 'title' => '导出'],
    ] as $item) {
        $insert([
            'type' => 'file', 'pid' => $item['pid'], 'name' => $item['name'], 'title' => $item['title'],
            'icon' => $item['icon'] ?? 'fa fa-circle-o',
            'ismenu' => $item['ismenu'] ?? 0,
            'menutype' => !empty($item['ismenu']) ? 'addtabs' : null,
            'weigh' => $item['weigh'] ?? 0,
        ]);
    }

    echo "OK    admin menus installed\n";
}

$sqlFile = $root . '/sql/fanshub.sql';
$sql = file_get_contents($sqlFile);
$sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql);

preg_match_all('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS[\s\S]*?;\s*/i', $sql, $matches);
$statements = $matches[0] ?? [];

$ok = 0;
$warn = 0;

$pdo->exec('SET NAMES utf8mb4');

foreach ($statements as $statement) {
    $statement = trim($statement);
    if ($statement === '') {
        continue;
    }
    try {
        $pdo->exec($statement);
        $ok++;
        echo "OK    " . substr(strtok($statement, "\n"), 0, 72) . "\n";
    } catch (PDOException $e) {
        $warn++;
        echo "WARN  {$e->getMessage()}\n";
    }
}

try {
    installMenus($pdo, $prefix);
} catch (PDOException $e) {
    $warn++;
    echo "WARN  {$e->getMessage()}\n";
}

require $root . '/thinkphp/base.php';
\think\App::initCommon();
$fanshubPath = $root . '/application/extra/fanshub.php';
$fanshubCfg = is_file($fanshubPath) ? include $fanshubPath : [];
if (!is_array($fanshubCfg)) {
    $fanshubCfg = [];
}
$savedCopy = isset($fanshubCfg['h5_copy']) && is_array($fanshubCfg['h5_copy']) ? $fanshubCfg['h5_copy'] : [];
$fanshubCfg['h5_copy'] = \app\common\library\FansHubService::mergeH5CopyDefaults($savedCopy);
if (\app\common\library\FansHubService::saveFanshubConfig($fanshubCfg)) {
    echo "OK    h5_copy defaults merged (" . count($fanshubCfg['h5_copy']) . " keys)\n";
    if (\app\common\library\FansHubService::exportH5CopyDefaultsJs()) {
        echo "OK    copy.defaults.js exported\n";
    } else {
        $warn++;
        echo "WARN  copy.defaults.js export failed\n";
    }
} else {
    $warn++;
    echo "WARN  h5_copy merge failed\n";
}

echo "\nDone. tables={$ok}, warn={$warn}\n";
if ($warn === 0) {
    echo "Install is OK.\n";
}
