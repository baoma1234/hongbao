<?php

namespace app\command;

use app\common\library\FansHubService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 待核销游戏账号：每分钟拉取 SugarCRM，mobilestatus=Verified 则自动通过
 *
 * crontab 示例（每分钟）：
 * * * * * * cd /www/wwwroot/caijin.com_7111 && php think fanshub:uid-sugar >> /tmp/fanshub-uid-sugar.log 2>&1
 */
class FanshubUidSugar extends Command
{
    protected function configure()
    {
        $this->setName('fanshub:uid-sugar')
            ->addOption('limit', 'l', Option::VALUE_OPTIONAL, '每次最多处理条数', 80)
            ->setDescription('扫描待核销游戏账号，SugarCRM 已验证手机则自动通过');
    }

    protected function execute(Input $input, Output $output)
    {
        $limit = (int)$input->getOption('limit');
        $stat = FansHubService::pollPendingUidViaSugarCrm($limit);
        $output->writeln(
            date('Y-m-d H:i:s')
            . ' scanned=' . $stat['scanned']
            . ' approved=' . $stat['approved']
        );
        return 0;
    }
}
