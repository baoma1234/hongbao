<?php

namespace Im\Support;

use PDO;
use PDOException;

/**
 * IM 长驻进程专用 PDO 封装。
 * MySQL 重启 / wait_timeout 后旧连接失效（2006 gone away），需探测 + 自动重连重试。
 */
class Db
{
    /** @var PDO|null */
    protected static $pdo;
    /** @var array */
    protected static $cfg = [];
    /** @var int */
    protected static $lastOkAt = 0;

    public static function init(array $cfg)
    {
        self::$cfg = $cfg;
        self::$pdo = null;
        self::$lastOkAt = 0;
    }

    /**
     * 定时保活：在 Worker 里 Timer::add(60, [Db::class, 'keepalive'])
     */
    public static function keepalive()
    {
        try {
            self::pdo(false, true)->query('SELECT 1');
            self::$lastOkAt = time();
        } catch (\Throwable $e) {
            try {
                self::reconnect()->query('SELECT 1');
                self::$lastOkAt = time();
            } catch (\Throwable $e2) {
                self::$pdo = null;
            }
        }
    }

    /**
     * @param bool $forceNew 强制新建
     * @param bool $skipPing 跳过探测（内部重连时用）
     */
    public static function pdo($forceNew = false, $skipPing = false)
    {
        if (!$forceNew && self::$pdo instanceof PDO) {
            if (!$skipPing) {
                // 超过 30s 未成功查询则先 ping，避免踩到 MySQL 重启后的死连接
                if (self::$lastOkAt > 0 && (time() - self::$lastOkAt) < 30) {
                    return self::$pdo;
                }
                try {
                    self::$pdo->query('SELECT 1');
                    self::$lastOkAt = time();
                    return self::$pdo;
                } catch (\Throwable $e) {
                    self::$pdo = null;
                }
            } else {
                return self::$pdo;
            }
        }

        $c = self::$cfg;
        if (!$c) {
            throw new PDOException('Db not initialized');
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $c['host'],
            (int)$c['port'],
            $c['database'],
            $c['charset'] ?? 'utf8mb4'
        );
        self::$pdo = new PDO($dsn, $c['username'], $c['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
        try {
            self::$pdo->exec('SET SESSION wait_timeout=28800, interactive_timeout=28800, net_read_timeout=60, net_write_timeout=60');
        } catch (\Throwable $e) {
        }
        self::$lastOkAt = time();
        return self::$pdo;
    }

    public static function reconnect()
    {
        if (self::$pdo instanceof PDO) {
            try {
                self::$pdo = null;
            } catch (\Throwable $e) {
                self::$pdo = null;
            }
        }
        self::$lastOkAt = 0;
        return self::pdo(true, true);
    }

    protected static function isGoneAway(\Throwable $e)
    {
        if ($e instanceof PDOException && !empty($e->errorInfo[1])) {
            $errno = (int)$e->errorInfo[1];
            if ($errno === 2006 || $errno === 2013) {
                return true;
            }
        }
        $msg = $e->getMessage();
        $code = (string)$e->getCode();
        if ($code === '2006' || $code === '2013') {
            return true;
        }
        return (stripos($msg, 'server has gone away') !== false)
            || (stripos($msg, 'Lost connection') !== false)
            || (stripos($msg, 'Broken pipe') !== false);
    }

    /**
     * @template T
     * @param callable(PDO):T $fn
     * @return T
     */
    protected static function withRetry(callable $fn)
    {
        $attempts = 0;
        $last = null;
        while ($attempts < 3) {
            $attempts++;
            try {
                $ret = $fn(self::pdo($attempts > 1));
                self::$lastOkAt = time();
                return $ret;
            } catch (PDOException $e) {
                $last = $e;
                if (!self::isGoneAway($e) || $attempts >= 3) {
                    throw $e;
                }
                self::$pdo = null;
                self::$lastOkAt = 0;
                usleep(50000 * $attempts);
            }
        }
        throw $last;
    }

    public static function table($name)
    {
        $prefix = self::$cfg['prefix'] ?? 'fa_';
        return '`' . $prefix . $name . '`';
    }

    public static function fetch($sql, array $bind = [])
    {
        return self::withRetry(function (PDO $pdo) use ($sql, $bind) {
            $st = $pdo->prepare($sql);
            $st->execute($bind);
            $row = $st->fetch();
            return $row ?: null;
        });
    }

    public static function fetchAll($sql, array $bind = [])
    {
        return self::withRetry(function (PDO $pdo) use ($sql, $bind) {
            $st = $pdo->prepare($sql);
            $st->execute($bind);
            return $st->fetchAll();
        });
    }

    public static function exec($sql, array $bind = [])
    {
        return self::withRetry(function (PDO $pdo) use ($sql, $bind) {
            $st = $pdo->prepare($sql);
            $st->execute($bind);
            return $st->rowCount();
        });
    }

    public static function lastId()
    {
        return (int)self::pdo()->lastInsertId();
    }

    public static function begin()
    {
        self::withRetry(function (PDO $pdo) {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
            }
        });
    }

    public static function commit()
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    }

    public static function rollBack()
    {
        try {
            $pdo = self::pdo(false, true);
            if ($pdo && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (\Throwable $e) {
            self::$pdo = null;
        }
    }
}
