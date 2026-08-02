<?php

namespace app\command;

use app\common\library\FansHubService;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * 福利大厅定时维护（密令过期等）
 */
class FanshubMaintain extends Command
{
    protected function configure()
    {
        $this->setName('fanshub:maintain')
            ->setDescription('福利大厅维护：过期密令标记等');
    }

    protected function execute(Input $input, Output $output)
    {
        $expired = FansHubService::expireSecrets();
        $output->writeln('密令过期处理：' . $expired . ' 条');
        $uidStat = FansHubService::pollPendingUidViaSugarCrm(80);
        $output->writeln('SugarCRM 待核销扫描：' . $uidStat['scanned'] . '，自动通过：' . $uidStat['approved']);
        $issued = \app\common\library\FansHubMarket::totalSharesIssued(true);
        $partners = \app\common\library\FansHubMarket::partnerCount(true);
        $price = \app\common\library\FansHubMarket::getSharePrice(false);
        $todayUp = \app\common\library\FansHubMarket::todayPartnerUp();
        $pct = \app\common\library\FansHubMarket::priceUpPercent();
        $output->writeln('已送出股份：' . $issued);
        $output->writeln('虚拟股份人数：' . $partners . '（今日+' . $todayUp . '）；股价：' . number_format($price, 2, '.', '') . '（较昨日+' . $pct . '%）');
        try {
            $csId = \app\common\library\FansHubDefaultCs::ensureAccount();
            $output->writeln('默认客服账号：' . $csId);
        } catch (\Throwable $eCs) {
            $output->writeln('默认客服账号：失败 ' . $eCs->getMessage());
        }
        try {
            $driftN = \app\common\library\FansHubOfficialStats::applyDailyMemberDrift();
            $output->writeln('官方群人数日漂移：' . $driftN . ' 个群');
        } catch (\Throwable $eDrift) {
            $output->writeln('官方群人数日漂移：失败 ' . $eDrift->getMessage());
        }
        return 0;
    }
}
