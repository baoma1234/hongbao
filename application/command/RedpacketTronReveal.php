<?php

namespace app\command;

use app\common\library\RedPacketTronFair;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * 波场红包开奖兜底轮询
 * crontab 每分钟：php think redpacket:tron-reveal
 */
class RedpacketTronReveal extends Command
{
    protected function configure()
    {
        $this->setName('redpacket:tron-reveal')
            ->setDescription('轮询处理待开奖波场哈希红包（队列兜底）');
    }

    protected function execute(Input $input, Output $output)
    {
        $stat = RedPacketTronFair::pollPending(40);
        $output->writeln('scanned=' . $stat['scanned'] . ' ok=' . $stat['ok'] . ' fail=' . $stat['fail']);
        return 0;
    }
}
