<?php

namespace app\command;

use app\common\library\finance\FinanceConfig;
use app\common\library\finance\WithdrawSync;
use app\common\library\Platform;
use app\common\library\WithdrawUrge as WithdrawUrgeService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

class WithdrawUrge extends Command
{
    protected function configure()
    {
        $this->setName('withdraw:urge')
            ->addOption('pid', 'p', Option::VALUE_OPTIONAL, '财务后台PID，all 表示全部启用后台')
            ->addOption('sync', 's', Option::VALUE_NONE, '执行前先同步未支付订单')
            ->setDescription('未支付提现订单定时催单');
    }

    protected function execute(Input $input, Output $output)
    {
        $pidOption = $input->getOption('pid');
        $sync = (bool)$input->getOption('sync');

        if ($pidOption === null || $pidOption === 'all') {
            $pids = FinanceConfig::getEnabledPids();
        } else {
            $pids = [FinanceConfig::resolvePid($pidOption)];
        }

        $exitCode = 0;

        foreach ($pids as $pid) {
            $output->writeln("========== pid={$pid} ==========");
            if ($sync) {
                $output->writeln('正在同步未支付订单...');
                try {
                    $syncResult = (new WithdrawSync($pid))->sync();
                } catch (\Exception $e) {
                    $output->error("[pid={$pid}] 同步失败：" . $e->getMessage());
                    $exitCode = 1;
                    continue;
                }
                $output->writeln(sprintf(
                    '[pid=%d] 同步完成：新增 %d，更新 %d，跳过 %d，通知 %d，已处理 %d，接口 %d 条',
                    $pid,
                    $syncResult['inserted'] ?? 0,
                    $syncResult['updated'] ?? 0,
                    $syncResult['skipped'] ?? 0,
                    $syncResult['notified'] ?? 0,
                    $syncResult['processed'] ?? 0,
                    $syncResult['total'] ?? 0
                ));
            }

            if (!Platform::isValid($pid)) {
                continue;
            }
            $output->writeln("正在催单 pid={$pid}...");
            $result = (new WithdrawUrgeService(FinanceConfig::getUrgeConfig($pid)))->process($pid);

            if (!empty($result['msg'])) {
                $output->warning("[pid={$pid}] " . $result['msg']);
                continue;
            }

            $output->writeln(sprintf(
                '[pid=%d] 催单完成：已催 %d，跳过 %d，待处理 %d',
                $pid,
                $result['urged'] ?? 0,
                $result['skipped'] ?? 0,
                $result['total'] ?? 0
            ));
        }

        return $exitCode;
    }
}
