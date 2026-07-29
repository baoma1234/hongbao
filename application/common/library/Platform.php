<?php

namespace app\common\library;

/**
 * 固定平台映射
 */
class Platform
{
    public static function getList()
    {
        $config = config('finance_sites');
        $sites = $config['sites'] ?? [];
        $list = [];
        foreach ($sites as $pid => $site) {
            if (empty($site['enabled'])) {
                continue;
            }
            $list[(int)$pid] = $site['name'] ?? (string)$pid;
        }

        return $list ?: [
            1 => '03',
            2 => '656',
            3 => '887',
            4 => '776',
        ];
    }

    public static function getName($pid)
    {
        $list = self::getList();
        return $list[(int)$pid] ?? (string)$pid;
    }

    public static function isValid($pid)
    {
        return isset(self::getList()[(int)$pid]);
    }
}
