<?php

namespace app\command;

use app\common\library\FansHubRpAuto;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * 红包自动发抢（兼容旧 crontab）
 * 主路径已迁入 IM WebSocket；IM 在线时本命令会跳过，避免双发。
 * 可删除 crontab: 每分钟 php think redpacket:auto
 */
class RedpacketAuto extends Command
{
    protected function configure()
    {
        $this->setName('redpacket:auto')
            ->setDescription('红包自动发抢（已迁入 IM；本命令仅作 IM 宕机兜底）');
    }

    protected function execute(Input $input, Output $output)
    {
        $stat = FansHubRpAuto::run(0);
        if (!empty($stat['via']) && $stat['via'] === 'im_ws') {
            $output->writeln('skip: IM WebSocket RpAutoBot is active (no cron needed)');
            return 0;
        }
        $output->writeln(sprintf(
            'via=%s send=%d grab=%d skip=%d errors=%d',
            (string)($stat['via'] ?? 'cli'),
            (int)$stat['send'],
            (int)$stat['grab'],
            (int)$stat['skip'],
            count($stat['errors'])
        ));
        foreach ($stat['errors'] as $err) {
            $output->writeln('[ERR] ' . $err);
        }
        return 0;
    }
}
