<?php

namespace app\command;

use app\common\library\FansHubRpAuto;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * 红包自动发抢
 * crontab 每分钟：php think redpacket:auto
 */
class RedpacketAuto extends Command
{
    protected function configure()
    {
        $this->setName('redpacket:auto')
            ->setDescription('执行后台配置的红包自动发/自动抢任务');
    }

    protected function execute(Input $input, Output $output)
    {
        $stat = FansHubRpAuto::run(0);
        $output->writeln(sprintf(
            'send=%d grab=%d skip=%d errors=%d',
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
