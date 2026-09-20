<?php

namespace app\command;

use app\common\library\FansHubVideoCrawl;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * 视频采集倒序跑一页
 * crontab 建议: 每 5 分钟 php think videocrawl:run
 */
class VideocrawlRun extends Command
{
    protected function configure()
    {
        $this->setName('videocrawl:run')
            ->setDescription('视频采集：对启用且自动采的任务各倒序采一页');
    }

    protected function execute(Input $input, Output $output)
    {
        $list = FansHubVideoCrawl::tickCron(20);
        if (!$list) {
            $output->writeln('idle: no runnable crawl tasks');
            return 0;
        }
        foreach ($list as $stat) {
            $output->writeln(($stat['ok'] ? 'OK' : 'FAIL') . ' ' . ($stat['msg'] ?? ''));
        }
        return 0;
    }
}
