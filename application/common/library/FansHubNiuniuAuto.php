<?php

namespace app\common\library;

/**
 * 尾数牛牛自动购入/领取 — 后台「立即执行」入口
 * 复用 im-server NiuniuAutoBotService::forceRun
 */
class FansHubNiuniuAuto
{
    /** @var \Im\Service\NiuniuAutoBotService|null */
    protected static $bot;

    /**
     * @param int $taskId 0=全部启用任务
     * @return array{buy:int,claim:int,settle:int,skip:int,errors:array,via:string}
     */
    public static function run($taskId = 0)
    {
        $bot = self::bot();
        $stat = $bot->forceRun((int)$taskId);
        $stat['via'] = 'admin_force';
        return $stat;
    }

    protected static function bot()
    {
        if (self::$bot) {
            return self::$bot;
        }
        $root = defined('ROOT_PATH') ? ROOT_PATH : (dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR);
        $autoload = $root . 'im-server' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        if (!is_file($autoload)) {
            throw new \RuntimeException('找不到 im-server/vendor/autoload.php');
        }
        require_once $autoload;
        $cfgFile = $root . 'im-server' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
        if (!is_file($cfgFile)) {
            throw new \RuntimeException('找不到 im-server/config/app.php');
        }
        $cfg = require $cfgFile;
        \Im\Support\Db::init($cfg['db']);
        \Im\Support\RedisClient::init($cfg['redis']);
        $messages = new \Im\Service\MessageService();
        $groups = new \Im\Service\GroupService();
        $niuniu = new \Im\Service\NiuniuService($cfg, $messages, $groups);
        self::$bot = new \Im\Service\NiuniuAutoBotService($niuniu, $groups);
        return self::$bot;
    }
}
