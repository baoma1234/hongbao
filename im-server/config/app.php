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

/**
 * Linux：按 CPU 核数设 WS，但默认封顶 32（80 核机勿直接拉满）
 * Windows：强制 1（Workerman 多进程不完整）
 * local.php 可覆盖 count（见 local.highperf.example.php）
 */
$cpuCores = 1;
if (PHP_OS_FAMILY !== 'Windows') {
    $n = 0;
    if (is_readable('/proc/cpuinfo')) {
        $n = (int)preg_match_all('/^processor\s*:/m', (string)@file_get_contents('/proc/cpuinfo'));
    }
    if ($n < 1) {
        $n = (int)trim((string)@shell_exec('nproc 2>/dev/null'));
    }
    if ($n < 1 && function_exists('swoole_cpu_num')) {
        $n = (int)@swoole_cpu_num();
    }
    $cpuCores = max(1, $n > 0 ? $n : 8);
}
$wsCountDefault = (PHP_OS_FAMILY === 'Windows') ? 1 : min(32, $cpuCores);
// HTTP 约占 WS 的 1/3～1/2，至少 4、最多 16（避免 MySQL 连接打爆）
$httpCountDefault = (PHP_OS_FAMILY === 'Windows') ? 1 : max(4, min(16, (int)ceil($wsCountDefault / 3)));

return array_replace_recursive([
    'websocket' => [
        'listen'     => 'websocket://0.0.0.0:17272',
        // 默认封顶 32；高配机用 local.php / local.highperf.example.php 再冲高
        'count'      => $wsCountDefault,
        'name'       => 'FansHubIM',
        'heartbeat'  => 50,
        // 空闲无任何帧（含 ping）则踢线；须 > 客户端 PING_INTERVAL(30s)
        'idle_kick'  => 120,
        // Linux SO_REUSEPORT，减轻 accept 热点
        'reuse_port' => (PHP_OS_FAMILY !== 'Windows'),
    ],
    'push' => [
        'drain_interval' => 0.03,  // 降低空闲 Redis 轮询；峰值仍靠 batch 消化
        'drain_batch'    => 2000,  // worker 多时加大单次消费
    ],
    // 17273 HTTP API（列表/历史/代聊）；与 WS count 独立
    'http_api' => [
        'listen'     => 'http://0.0.0.0:17273',
        'count'      => $httpCountDefault,
        'reuse_port' => (PHP_OS_FAMILY !== 'Windows'),
    ],
    // 独立 cron 进程（start_cron.php）；勿再挂到 WS Worker0
    'cron' => [
        'name'                => 'FansHubIM-Cron',
        'tron_poll_interval'  => 1,   // 秒，可调到 3～5 降压
        'refund_interval'     => 5,
        'refund_limit'        => 50,
        'tron_reveal_limit'   => 20,
        'settle_interval'     => 2,
        'settle_limit'        => 30,
        'auto_interval'       => 2,
        'yxx_tick_url'        => 'http://127.0.0.1:7111/api/fanshub/yxxtick',
        'yxx_tick_interval'   => 1,
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
        'agent_rebate_rate_default'  => 0.01,   // 群主代理返点默认 1%（发时从手续费划转）
        'agent_rebate_rate_vip'      => 0.01,   // VIP 群代理返点 1%
        'invite_rebate_rate'         => 0.005,  // 推荐人返佣 0.5%（发时从手续费划转）
        // 平台手续费入账用户（须在 fa_fans_account 有账户）；请按环境改成真实平台户
        'platform_user_id'           => 56960815,
        // 新建群自动拉入的机器人；拼手气续发由系统监听（扣最差余额发包）
        'group_robot_user_id'        => 74282747,
        // 扫雷单独：赔付倍率 / 抽水 / 返点 / 收款户
        'mine_compensate_rate_5'         => 1.5,
        'mine_compensate_rate_7'         => 1.2,
        'mine_compensate_rate_9'         => 1.0,
        'mine_platform_fee_rate'         => 0.03,
        'mine_agent_rebate_rate_default' => 0.01,
        'mine_agent_rebate_rate_vip'     => 0.01,
        'mine_invite_rebate_rate'        => 0.005,
        'mine_platform_user_id'          => 56960815,
        // 普通用户群红宝（普通/随机）单独配置
        'user_rp_expire_seconds'            => 1800,
        'user_rp_min_amount'                => 10.0,
        'user_rp_min_count'                 => 1,
        'user_rp_max_count'                 => 500,
        'user_rp_platform_fee_rate'         => 0.03,
        'user_rp_agent_rebate_rate_default' => 0.01,
        'user_rp_agent_rebate_rate_vip'     => 0.01,
        'user_rp_invite_rebate_rate'        => 0.005,
        'user_rp_platform_user_id'          => 56960815,
        // 福利大厅余额 fa_fans_account.balance
        'account_table'              => 'fans_account',
        'ledger_table'               => 'fans_ledger',
        'wallet_field'               => 'hongbao',
        // 抢包验资余额短缓存（秒）；change() 后立即回写
        'balance_cache_ttl'          => 10,
    ],
    'admin_bridge' => [
        'key' => '758fa83a00956f0419cd8abae1b0e86acffa7c166acb9784',
    ],
    // 万人群：成员 Redis Set；实时推送优先 PushBus::toGroup（跨进程只传 gid）
    // max_push_online 仅约束仍走 onlineMemberIds 的旧路径（未读计数等）
    'group' => [
        'max_members'     => 10000, // 单群人数上限（写入 chat_groups.max_members）
        'max_push_online' => 5000,  // 旧 uid 列表推送上限；toGroup 不受此截断
    ],
    // 抢包风暴：同 packet 的 redpacket.update 合并窗口（毫秒）
    'redpacket' => [
        'update_coalesce_ms' => 120,
    ],
], $override);
