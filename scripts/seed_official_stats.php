<?php
/**
 * 官方群：每群不同成员基数 + Redis 同步
 * 用法: php scripts/seed_official_stats.php
 */
define('APP_PATH', dirname(__DIR__) . '/application/');
define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);

require dirname(__DIR__) . '/thinkphp/base.php';

\think\App::initCommon();

use app\common\library\FansHubOfficialStats;
use think\Db;

$n = FansHubOfficialStats::diversifyOfficialMemberBases();
echo "Diversified {$n} official groups\n";

$rows = Db::name('chat_groups')
    ->where('status', 'in', [1, 3])
    ->where('is_recommend', 1)
    ->field('id,name,display_member_count')
    ->select();
foreach ((array)$rows as $g) {
    $gid = (int)$g['id'];
    $base = (int)$g['display_member_count'];
    $mc = FansHubOfficialStats::memberCount($gid, $base);
    $oc = FansHubOfficialStats::onlineCount($gid);
    echo "  #{$gid} {$g['name']} base={$base} show={$mc} online={$oc}\n";
}
echo "OK\n";
