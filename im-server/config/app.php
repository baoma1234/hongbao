<?php
/**
 * IM 服务配置（可被同目录 local.php 覆盖）
 * local.php 示例: return ['db' => [...], 'redis' => [...]];
 */
 // app.php 在 im-server/config/，项目根目录上两级
$rootEnv = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
$db = [
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'database' => 'caijin_com_7111',
    'username' => 'root',
    'password' => '',
    'charset'  => 'utf8mb4',
    'prefix'   => 'fa_',
];
$redis = [
    'host'     => '127.0.0.1',
    'port'     => 6379,
    'password' => '',
    'db'       => 2,
    'prefix'   => 'im:',
];

$ini = [];
if (is_file($rootEnv)) {
    $ini = @parse_ini_file($rootEnv, true);
    if (!is_array($ini)) {
        $ini = [];
    }
}
if (!empty($ini['database'])) {
    $d = $ini['database'];
    $db['host'] = $d['hostname'] ?? $db['host'];
    $db['port'] = (int)($d['hostport'] ?? $db['port']);
    $db['database'] = $d['database'] ?? $db['database'];
    $db['username'] = $d['username'] ?? $db['username'];
    $db['password'] = $d['password'] ?? $db['password'];
    $db['prefix'] = $d['prefix'] ?? $db['prefix'];
}
if (!empty($ini['redis'])) {
    $r = $ini['redis'];
    $redis['host'] = $r['host'] ?? $redis['host'];
    $redis['port'] = (int)($r['port'] ?? $redis['port']);
    $redis['password'] = $r['password'] ?? $redis['password'];
    $redis['db'] = (int)($r['select'] ?? $r['db'] ?? $redis['db']);
    $redis['prefix'] = $r['prefix'] ?? $redis['prefix'];
}

$local = __DIR__ . '/local.php';
$override = is_file($local) ? include $local : [];
if (!is_array($override)) {
    $override = [];
}
$rpRuntime = __DIR__ . '/red_packet_runtime.php';
if (is_file($rpRuntime)) {
    $rp = include $rpRuntime;
    if (is_array($rp)) {
        $override = array_replace_recursive($override, $rp);
    }
}

return array_replace_recursive([
    'websocket' => [
        'listen'   => 'websocket://0.0.0.0:7272',
        // Windows 只能 1；Linux 建议设为 CPU 核数（如 4～8）以支撑约 5000 在线
        'count'    => (PHP_OS_FAMILY === 'Windows') ? 1 : 4,
        'name'     => 'FansHubIM',
        'heartbeat'=> 50,
    ],
    'push' => [
        'drain_interval' => 0.05, // 消费跨进程队列间隔（秒）
        'drain_batch'    => 100,
    ],
    'db'    => $db,
    'redis' => $redis,
    'auth'  => [
        // FastAdmin / 会员 token 表（库内为 HMAC，与 application/config.php token 一致）
        'token_table' => 'user_token',
        'user_table'  => 'user',
        'key'         => 'H1Ln476zwoJmU0Y2bCPD5QEqOcyZTkvG',
        'hashalgo'    => 'ripemd160',
    ],
    'tron' => [
        'api_url' => 'https://api.trongrid.io',
        'api_key' => 'aaa8e343-c0f7-43ef-8acc-4dbbd1865fd2',
        // 全局唯一线程：每 1 秒拉最新真实区块哈希写入 Redis；拆包/扫雷只读本地缓存
        'hash_poll_interval' => 1,
        // 最近缓存块数量（按末位数字索引，加快扫雷命中）
        'hash_recent_limit'  => 40,
        // 兼容旧 pending 包开奖（新包发包即用缓存哈希拆完）
        'commit_offset' => 2,
        'reveal_delay'  => 8,
        'now_cache_ttl'   => 2,
        'block_cache_ttl' => 86400,
    ],
    // 抢包风控：超人手速 / 多群并发 → 强制滑块
    'grab_guard' => [
        'enabled'           => true,
        'min_interval_ms'   => 150,
        'speed_streak'      => 3,
        'multi_window_ms'   => 2000,
        'multi_group_limit' => 3,
    ],
        'red_packet' => [
        'expire_seconds'             => 60,
        // 扫雷红包单独过期（后台可配，默认 180 秒=3 分钟）
        'mine_expire_seconds'        => 180,
        'min_amount_cent'            => 1,
        'max_count'                  => 10,
        'min_count'                  => 5,
        'min_amount'                 => 10.0,
        'vip_min_count'              => 5,
        'vip_max_count'              => 10,
        'platform_fee_rate'          => 0.03,   // 平台抽水 3%（发时扣入平台户）
        'agent_rebate_rate_default'  => 0.01,   // 群主代理返点默认 1%
        'agent_rebate_rate_vip'      => 0.01,   // VIP 群代理返点 1%
        // 平台手续费入账用户（须在 fa_fans_account 有账户）；请按环境改成真实平台户
        'platform_user_id'           => 56960815,
        // 扫雷单独：赔付倍率 / 抽水 / 返点 / 收款户
        'mine_compensate_rate_5'         => 1.5,
        'mine_compensate_rate_7'         => 1.2,
        'mine_compensate_rate_9'         => 1.0,
        'mine_platform_fee_rate'         => 0.03,
        'mine_agent_rebate_rate_default' => 0.01,
        'mine_agent_rebate_rate_vip'     => 0.01,
        'mine_platform_user_id'          => 56960815,
        // 福利大厅余额 fa_fans_account.balance
        'account_table'              => 'fans_account',
        'ledger_table'               => 'fans_ledger',
        'wallet_field'               => 'balance',
        // 抢包验资余额短缓存（秒）；change() 后立即回写
        'balance_cache_ttl'          => 3,
    ],
    'admin_bridge' => [
        'key' => '758fa83a00956f0419cd8abae1b0e86acffa7c166acb9784',
    ],
], $override);
