<?php

namespace app\command;

use app\common\library\FansHubOg;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * 定时拉取 OG 投注记录
 * crontab 建议: 每分钟 php think fanshub:og-bets
 */
class FanshubOgBets extends Command
{
    protected function configure()
    {
        $this->setName('fanshub:og-bets')
            ->setDescription('OG视讯：增量抓取投注记录并落库');
    }

    protected function execute(Input $input, Output $output)
    {
        $ret = FansHubOg::tickBetHistoryCron();
        if (!empty($ret['skipped'])) {
            $output->writeln('skip: ' . ($ret['msg'] ?? 'n/a'));
            return 0;
        }
        if (empty($ret['ok'])) {
            $output->writeln('FAIL ' . ($ret['msg'] ?? 'unknown'));
            return 1;
        }
        $output->writeln(sprintf(
            'OK fetched=%d upserted=%d pages=%d last_fetch_id=%s rs=%s',
            (int)($ret['fetched'] ?? 0),
            (int)($ret['upserted'] ?? 0),
            (int)($ret['pages'] ?? 0),
            (string)($ret['last_fetch_id'] ?? ''),
            (string)($ret['rs_code'] ?? '')
        ));
        return 0;
    }
}
