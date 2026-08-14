<?php

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * 已停用：开奖兜底在 IM cron（TronFair::pollPendingReveals）。
 * 保留命令名避免 crontab 报错，执行时 no-op。
 */
class RedpacketTronReveal extends Command
{
    protected function configure()
    {
        $this->setName('redpacket:tron-reveal')
            ->setDescription('（已停用）波场红包开奖已收口到 IM；本命令不再写库');
    }

    protected function execute(Input $input, Output $output)
    {
        $output->writeln('skipped=im_only_reveal scanned=0 ok=0 fail=0');
        return 0;
    }
}
