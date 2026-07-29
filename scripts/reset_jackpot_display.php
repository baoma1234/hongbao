<?php
/**
 * 把「累计创造价值」缓存重置到配置基数（默认 100 万）
 */
define('APP_PATH', dirname(__DIR__) . '/application/');
require dirname(__DIR__) . '/thinkphp/base.php';
\think\App::initCommon();

$base = (float)\app\common\library\FansHubService::config('jackpot_base', 1000000);
\app\common\library\FansHubService::resetJackpotCache($base);
$now = \app\common\library\FansHubService::getJackpotAmount(false);
echo "jackpot_base={$base}\n";
echo "jackpot_current={$now}\n";
echo "partner_daily=" . \app\common\library\FansHubService::config('market_daily_grow_min')
    . '~' . \app\common\library\FansHubService::config('market_daily_grow_max') . "\n";
echo "value_daily=" . \app\common\library\FansHubService::config('jackpot_grow_min')
    . '~' . \app\common\library\FansHubService::config('jackpot_grow_max') . "\n";
echo "ceiling=" . \app\common\library\FansHubService::config('jackpot_ceiling', 100000000) . "\n";
