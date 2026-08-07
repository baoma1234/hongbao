<?php

namespace app\command;

use app\common\library\FansHubFission;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * 裂变红包定时：超时作废 + 满额开奖兜底
 * php think fission:maintain
 */
class FissionMaintain extends Command
{
    protected function configure()
    {
        $this->setName('fission:maintain')
            ->setDescription('裂变红包维护：超时作废与满额开奖');
    }

    protected function execute(Input $input, Output $output)
    {
        $r = FansHubFission::maintain();
        $output->writeln('超时作废：' . (int)$r['expired']);
        $output->writeln('满额开奖：' . (int)$r['settled']);
        return 0;
    }
}
