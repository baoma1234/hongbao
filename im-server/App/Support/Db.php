<?php

namespace Im\Support;

use PDO;
use PDOException;

/**
 * IM 长驻进程专用 PDO 封装。
 * MySQL 空闲断开（wait_timeout）后会报 2006 gone away，需自动重连并重试一次。
 */
class Db
{
    /** @var PDO|null */
    protected static $pdo;
    /** @var array */
    protected static $cfg = [];

    public static function init(array $cfg)
    {
        self::$cfg = $cfg;
        self::$pdo = null;
    }

    public static function pdo($forceNew = false)
    {
        if (!$forceNew && self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $c = self::$cfg;
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
        ]);
        // 会话级保活：避免被服务端较短 wait_timeout 提前踢掉（仍受全局上限约束）
        try {
            self::$pdo->exec('SET SESSION wait_timeout=28800, interactive_timeout=28800');
        } catch (\Throwable $e) {
            // 权限不足时忽略
        }
        return self::$pdo;
    }

    /** 丢弃失效连接，下次 pdo() 重建 */
    public static function reconnect()
    {
        self::$pdo = null;
        return self::pdo(true);
    }

    protected static function isGoneAway(\Throwable $e)
    {
        $msg = $e->getMessage();
        $code = (string)$e->getCode();
        if ($code === 'HY000' || $code === '2006' || $code === '2013') {
            return true;
        }
        return (stripos($msg, 'server has gone away') !== false)
            || (stripos($msg, 'Lost connection') !== false)
            || (strpos($msg, '2006') !== false)
            || (strpos($msg, '2013') !== false);
    }

    /**
     * @template T
     * @param callable(PDO):T $fn
     * @return T
     */
    protected static function withRetry(callable $fn)
    {
        try {
            return $fn(self::pdo());
        } catch (PDOException $e) {
            if (!self::isGoneAway($e)) {
                throw $e;
            }
            self::reconnect();
            return $fn(self::pdo());
        }
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
        if (self::pdo()->inTransaction()) {
            self::pdo()->commit();
        }
    }

    public static function rollBack()
    {
        if (self::pdo()->inTransaction()) {
            self::pdo()->rollBack();
        }
    }
}
