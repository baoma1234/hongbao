<?php
/**
 * 清理机器人资产流水（fa_fans_ledger）：只删 is_bot=1 且 createtime 早于 N 天的记录
 *
 * 安全规则：
 * - 只删 fans_account.is_bot=1 的会员流水，不动真人
 * - 默认 DRY-RUN；加 --execute 才删除
 * - 默认连项目根 .env 库（正式库）；也可用 im-server 本地配置
 *
 * Usage:
 *   php scripts/cleanup_bot_ledger.php --days=3
 *   php scripts/cleanup_bot_ledger.php --days=3 --execute
 *   php scripts/cleanup_bot_ledger.php --days=3 --batch=5000 --execute
 *
 * cron 建议（每天 04:40）：
 *   40 4 * * * cd /path/to/project && php scripts/cleanup_bot_ledger.php --days=3 --execute >> runtime/log/bot_ledger_cleanup.log 2>&1
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$imRoot = $root . '/im-server';
require $imRoot . '/vendor/autoload.php';

$opts = [
    'days'            => 3,
    'batch'           => 20000,
    'execute'         => false,
    'sleep_ms'        => 5,
    'use_project_env' => true,
];
foreach ($argv as $i => $arg) {
    if ($i === 0) {
        continue;
    }
    if ($arg === '--execute') {
        $opts['execute'] = true;
        continue;
    }
    if ($arg === '--use-im-config') {
        $opts['use_project_env'] = false;
        continue;
    }
    if ($arg === '--use-project-env') {
        $opts['use_project_env'] = true;
        continue;
    }
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) {
        $opts['days'] = max(1, (int)$m[1]);
        continue;
    }
    if (preg_match('/^--batch=(\d+)$/', $arg, $m)) {
        $opts['batch'] = max(500, min(20000, (int)$m[1]));
        continue;
    }
    if (preg_match('/^--sleep-ms=(\d+)$/', $arg, $m)) {
        $opts['sleep_ms'] = max(0, (int)$m[1]);
        continue;
    }
    if ($arg === '--help' || $arg === '-h') {
        echo "Usage: php cleanup_bot_ledger.php [--days=3] [--batch=5000] [--sleep-ms=20] [--execute]\n";
        echo "  默认读项目 .env 数据库；--use-im-config 改用 im-server/config\n";
        exit(0);
    }
}

if ($opts['use_project_env']) {
    $envFile = $root . DIRECTORY_SEPARATOR . '.env';
    $ini = is_file($envFile) ? parse_ini_file($envFile, true) : [];
    $d = is_array($ini['database'] ?? null) ? $ini['database'] : [];
    $cfg = [
        'db' => [
            'host'     => (string)($d['hostname'] ?? '127.0.0.1'),
            'port'     => (int)($d['hostport'] ?? 3306),
            'database' => (string)($d['database'] ?? ''),
            'username' => (string)($d['username'] ?? ''),
            'password' => (string)($d['password'] ?? ''),
            'charset'  => 'utf8mb4',
            'prefix'   => (string)($d['prefix'] ?? 'fa_'),
        ],
    ];
} else {
    $cfg = require $imRoot . '/config/app.php';
}
Im\Support\Db::init($cfg['db']);

$days = (int)$opts['days'];
$batch = (int)$opts['batch'];
$execute = (bool)$opts['execute'];
$sleepUs = ((int)$opts['sleep_ms']) * 1000;
$cutoff = time() - ($days * 86400);

$ledger = Im\Support\Db::table('fans_ledger');
$account = Im\Support\Db::table('fans_account');

echo '[' . date('Y-m-d H:i:s') . '] cleanup bot ledger days=' . $days
    . ' cutoff=' . date('Y-m-d H:i:s', $cutoff)
    . ' mode=' . ($execute ? 'EXECUTE' : 'DRY-RUN')
    . " batch={$batch}\n";
echo 'db_host=' . ($cfg['db']['host'] ?? '') . ' db=' . ($cfg['db']['database'] ?? '') . "\n";

$botAccounts = (int)(Im\Support\Db::fetch(
    "SELECT COUNT(*) AS c FROM {$account} WHERE IFNULL(is_bot,0)=1"
)['c'] ?? 0);
$botIds = Im\Support\Db::fetchAll(
    "SELECT user_id FROM {$account} WHERE IFNULL(is_bot,0)=1"
) ?: [];
$botUidList = [];
foreach ($botIds as $r) {
    $uid = (int)($r['user_id'] ?? 0);
    if ($uid > 0) {
        $botUidList[] = $uid;
    }
}
$botUidList = array_values(array_unique($botUidList));
if (!$botUidList) {
    echo "OK no bot accounts\n";
    exit(0);
}
$botIn = implode(',', $botUidList);
echo 'bot_uid_count=' . count($botUidList) . "\n";

$botAll = (int)(Im\Support\Db::fetch(
    "SELECT COUNT(*) AS c FROM {$ledger} WHERE user_id IN ({$botIn})"
)['c'] ?? 0);
$candidates = (int)(Im\Support\Db::fetch(
    "SELECT COUNT(*) AS c FROM {$ledger} WHERE user_id IN ({$botIn}) AND createtime < ?",
    [$cutoff]
)['c'] ?? 0);
$hotTotal = (int)(Im\Support\Db::fetch("SELECT COUNT(*) AS c FROM {$ledger}")['c'] ?? 0);

echo "bot_accounts={$botAccounts} bot_ledger_all={$botAll} candidates={$candidates} ledger_total={$hotTotal}\n";

if ($candidates <= 0) {
    echo "OK nothing to delete\n";
    exit(0);
}

if (!$execute) {
    $samples = Im\Support\Db::fetchAll(
        "SELECT id, user_id, type, createtime
         FROM {$ledger}
         WHERE user_id IN ({$botIn}) AND createtime < ?
         ORDER BY id ASC
         LIMIT 10",
        [$cutoff]
    ) ?: [];
    echo "--- sample (first 10) ---\n";
    foreach ($samples as $s) {
        echo sprintf(
            "  id=%s uid=%s type=%s at=%s\n",
            $s['id'],
            $s['user_id'],
            $s['type'],
            date('Y-m-d H:i:s', (int)$s['createtime'])
        );
    }
    echo "DRY-RUN done. Re-run with --execute to delete {$candidates} bot ledger rows.\n";
    exit(0);
}

$deleted = 0;
$rounds = 0;
$t0 = microtime(true);
while (true) {
    // 单表 DELETE + LIMIT：比 JOIN 快；bot UID 仅约数百个
    $n = (int)Im\Support\Db::exec(
        "DELETE FROM {$ledger}
         WHERE user_id IN ({$botIn}) AND createtime < ?
         ORDER BY id ASC
         LIMIT {$batch}",
        [$cutoff]
    );
    $rounds++;
    $deleted += $n;
    if ($rounds === 1 || $rounds % 10 === 0 || $n < $batch) {
        $elapsed = round(microtime(true) - $t0, 1);
        echo '[' . date('H:i:s') . "] round={$rounds} deleted={$deleted} last_batch={$n} elapsed={$elapsed}s\n";
    }
    if ($n <= 0) {
        break;
    }
    if ($sleepUs > 0) {
        usleep($sleepUs);
    }
}

$left = (int)(Im\Support\Db::fetch(
    "SELECT COUNT(*) AS c FROM {$ledger} WHERE user_id IN ({$botIn}) AND createtime < ?",
    [$cutoff]
)['c'] ?? 0);
echo '[' . date('Y-m-d H:i:s') . "] done deleted={$deleted} remaining_old_bot={$left}\n";
exit($left > 0 ? 2 : 0);
