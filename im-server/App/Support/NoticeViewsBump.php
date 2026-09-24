<?php

namespace Im\Support;

use PDO;

/**
 * 社区帖子自动浏览：
 * - views_count < 100000：每分钟 +50～60（冲到 10 万）
 * - views_count >= 100000：每天只加 200～500（不再按分钟猛涨）
 *
 * 由 im-server Cron 定时写库；列表 API / 前端假涨仅作展示。
 */
class NoticeViewsBump
{
    const REDIS_KEY = 'notice:views_bump_at';
    const REDIS_DAILY_KEY = 'notice:views_daily_hot';
    const MIN_INTERVAL = 55;
    /** 超过此浏览量后改走「每天几百」 */
    const HOT_THRESHOLD = 100000;

    /** @var PDO|null */
    protected static $pdo;

    /**
     * @return array{bumped:bool,minutes:int,rows:int,daily_rows?:int,error?:string}
     */
    public static function tick($force = false)
    {
        $now = time();
        $last = 0;
        try {
            $raw = RedisClient::conn()->get(RedisClient::key(self::REDIS_KEY));
            $last = (int)$raw;
        } catch (\Throwable $e) {
            if (!$force) {
                return ['bumped' => false, 'minutes' => 0, 'rows' => 0, 'error' => 'redis:' . $e->getMessage()];
            }
        }

        if (!$force && $last > 0 && ($now - $last) < self::MIN_INTERVAL) {
            return ['bumped' => false, 'minutes' => 0, 'rows' => 0];
        }

        $minutes = 1;
        if ($last > 0) {
            $minutes = (int)floor(($now - $last) / 60);
            if ($minutes < 1) {
                $minutes = 1;
            }
            if ($minutes > 10) {
                $minutes = 10;
            }
        }

        try {
            $pdo = self::webPdo();
            $table = self::noticeTable();
            self::ensureViewsColumn($pdo, $table);
            $thr = (int)self::HOT_THRESHOLD;
            $rows = 0;
            // 未满 10 万：按分钟涨；已满 10 万不走分钟涨
            for ($i = 0; $i < $minutes; $i++) {
                $n = $pdo->exec(
                    "UPDATE `{$table}` SET `views_count` = `views_count` + (50 + FLOOR(RAND() * 11))"
                    . " WHERE `status` = 'published' AND `views_count` < {$thr}"
                );
                $rows += (int)$n;
            }
            $dailyRows = self::dailyHotBoost($pdo, $table, $force);
            try {
                RedisClient::conn()->setex(RedisClient::key(self::REDIS_KEY), 86400, (string)$now);
            } catch (\Throwable $e) {
            }
            return [
                'bumped'     => true,
                'minutes'    => $minutes,
                'rows'       => $rows,
                'daily_rows' => $dailyRows,
            ];
        } catch (\Throwable $e) {
            error_log('[CRON][NOTICE_VIEWS] ' . $e->getMessage());
            return ['bumped' => false, 'minutes' => 0, 'rows' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * 已满 10 万的帖：每个自然日只加一次 200～500
     * @return int 影响行数
     */
    protected static function dailyHotBoost(PDO $pdo, $table, $force = false)
    {
        $ymd = date('Y-m-d');
        $rkey = RedisClient::key(self::REDIS_DAILY_KEY);
        if (!$force) {
            try {
                $done = (string)RedisClient::conn()->get($rkey);
                if ($done === $ymd) {
                    return 0;
                }
            } catch (\Throwable $e) {
                // Redis 不可用时仍尝试写库（依赖幂等：按日最多多涨一次可接受）
            }
        }
        $thr = (int)self::HOT_THRESHOLD;
        // 200～500
        $n = (int)$pdo->exec(
            "UPDATE `{$table}` SET `views_count` = `views_count` + (200 + FLOOR(RAND() * 301))"
            . " WHERE `status` = 'published' AND `views_count` >= {$thr}"
        );
        try {
            // 存到次日凌晨过期，避免跨日误判
            $ttl = max(3600, strtotime('tomorrow') - time() + 3600);
            RedisClient::conn()->setex($rkey, $ttl, $ymd);
        } catch (\Throwable $e) {
        }
        return $n;
    }

    protected static function webPdo()
    {
        if (self::$pdo instanceof PDO) {
            try {
                self::$pdo->query('SELECT 1');
                return self::$pdo;
            } catch (\Throwable $e) {
                self::$pdo = null;
            }
        }
        $c = self::webDbConfig();
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $c['host'],
            (int)$c['port'],
            $c['database'],
            $c['charset']
        );
        self::$pdo = new PDO($dsn, $c['username'], $c['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 10,
        ]);
        return self::$pdo;
    }

    /** @return array{host:string,port:int,database:string,username:string,password:string,charset:string,prefix:string} */
    protected static function webDbConfig()
    {
        $out = [
            'host'     => '127.0.0.1',
            'port'     => 3306,
            'database' => 'caijin_com_7111',
            'username' => 'root',
            'password' => '',
            'charset'  => 'utf8mb4',
            'prefix'   => 'fa_',
        ];
        $envFile = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '.env';
        if (is_file($envFile)) {
            $ini = @parse_ini_file($envFile, true);
            if (is_array($ini) && !empty($ini['database'])) {
                $d = $ini['database'];
                $out['host'] = (string)($d['hostname'] ?? $out['host']);
                $out['port'] = (int)($d['hostport'] ?? $out['port']);
                $out['database'] = (string)($d['database'] ?? $out['database']);
                $out['username'] = (string)($d['username'] ?? $out['username']);
                $out['password'] = (string)($d['password'] ?? $out['password']);
                $out['prefix'] = (string)($d['prefix'] ?? $out['prefix']);
            }
        }
        if ($out['password'] === '' && $out['username'] === 'root') {
            try {
                $cfg = require dirname(__DIR__, 2) . '/config/app.php';
                if (is_array($cfg) && !empty($cfg['db'])) {
                    $db = $cfg['db'];
                    $out['host'] = (string)($db['host'] ?? $out['host']);
                    $out['port'] = (int)($db['port'] ?? $out['port']);
                    $out['database'] = (string)($db['database'] ?? $out['database']);
                    $out['username'] = (string)($db['username'] ?? $out['username']);
                    $out['password'] = (string)($db['password'] ?? $out['password']);
                    $out['prefix'] = (string)($db['prefix'] ?? $out['prefix']);
                }
            } catch (\Throwable $e) {
            }
        }
        return $out;
    }

    protected static function noticeTable()
    {
        $c = self::webDbConfig();
        return $c['prefix'] . 'fans_notice';
    }

    protected static function ensureViewsColumn(PDO $pdo, $table)
    {
        static $ok = false;
        if ($ok) {
            return;
        }
        $st = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'views_count'");
        if ($st && $st->fetch()) {
            $ok = true;
            return;
        }
        $pdo->exec(
            "ALTER TABLE `{$table}` ADD COLUMN `views_count` int unsigned NOT NULL DEFAULT 0 COMMENT '浏览量' AFTER `weigh`"
        );
        $ok = true;
    }
}
