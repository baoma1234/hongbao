<?php

namespace Im\Support;

use PDO;

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

    public static function pdo()
    {
        if (self::$pdo instanceof PDO) {
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
        ]);
        return self::$pdo;
    }

    public static function table($name)
    {
        $prefix = self::$cfg['prefix'] ?? 'fa_';
        return '`' . $prefix . $name . '`';
    }

    public static function fetch($sql, array $bind = [])
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($bind);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function fetchAll($sql, array $bind = [])
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($bind);
        return $st->fetchAll();
    }

    public static function exec($sql, array $bind = [])
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($bind);
        return $st->rowCount();
    }

    public static function lastId()
    {
        return (int)self::pdo()->lastInsertId();
    }

    public static function begin()
    {
        self::pdo()->beginTransaction();
    }

    public static function commit()
    {
        self::pdo()->commit();
    }

    public static function rollBack()
    {
        if (self::pdo()->inTransaction()) {
            self::pdo()->rollBack();
        }
    }
}
