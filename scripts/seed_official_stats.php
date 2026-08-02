<?php
/**
 * 初始化官方社群统一成员基数（约 17888）并同步 display_member_count
 * 用法: php scripts/seed_official_stats.php
 */
define('APP_PATH', dirname(__DIR__) . '/application/');
define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);

require dirname(__DIR__) . '/thinkphp/base.php';

\think\App::initCommon();

use app\common\library\FansHubOfficialStats;
use think\Db;

$base = FansHubOfficialStats::DEFAULT_BASE;
try {
    $r = new \Redis();
    $host = '127.0.0.1';
    $port = 6379;
    $pass = '';
    $db = 2;
    $rootEnv = ROOT_PATH . '.env';
    if (is_file($rootEnv)) {
        $ini = @parse_ini_file($rootEnv, true);
        if (is_array($ini) && !empty($ini['redis'])) {
            $host = $ini['redis']['hostname'] ?? $host;
            $port = (int)($ini['redis']['hostport'] ?? $port);
            $pass = (string)($ini['redis']['password'] ?? $pass);
        }
    }
    $imLocal = ROOT_PATH . 'im-server/config/local.php';
    if (is_file($imLocal)) {
        $local = include $imLocal;
        if (is_array($local) && !empty($local['redis'])) {
            $host = $local['redis']['host'] ?? $host;
            $port = (int)($local['redis']['port'] ?? $port);
            $pass = (string)($local['redis']['password'] ?? $pass);
            $db = (int)($local['redis']['db'] ?? $db);
        }
    }
    if ($r->connect($host, $port, 2)) {
        if ($pass !== '') {
            $r->auth($pass);
        }
        $r->select($db);
        $cur = $r->get('im:official:mbase');
        if ($cur === false || $cur === null || (int)$cur < 10000) {
            $r->set('im:official:mbase', $base);
            echo "Redis im:official:mbase => {$base}\n";
        } else {
            $base = (int)$cur;
            echo "Redis im:official:mbase keep => {$base}\n";
        }
    } else {
        echo "Redis connect failed, using DEFAULT_BASE {$base}\n";
    }
} catch (Throwable $e) {
    echo 'Redis: ' . $e->getMessage() . "\n";
}

FansHubOfficialStats::syncDisplayMemberCount($base);
$n = (int)Db::name('chat_groups')->where('status', 'in', [1, 3])->where('is_recommend', 1)->count();
echo "Synced display_member_count={$base} on {$n} official groups\n";

if (method_exists(\app\common\library\FansHubService::class, 'clearOfficialCommunityCache')) {
    \app\common\library\FansHubService::clearOfficialCommunityCache();
    echo "Cleared official community cache\n";
}

echo "OK\n";
