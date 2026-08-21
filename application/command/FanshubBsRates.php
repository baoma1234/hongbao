<?php

namespace app\command;

use app\common\library\FansHubBsGateway;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 同步 BS 主商户通道汇率（代收/代付）
 *
 * crontab 每日凌晨：
 *   5 0 * * * cd /path/to/project && php think fanshub:bs-rates >> runtime/log/bs_rates.log 2>&1
 */
class FanshubBsRates extends Command
{
    protected function configure()
    {
        $this->setName('fanshub:bs-rates')
            ->addOption('merchant_id', 'm', Option::VALUE_OPTIONAL, '指定商户 ID，默认自动找 BS 主商户', null)
            ->setDescription('同步 BS USDT 主商户通道汇率（代收→充值通道，代付→提现通道）');
    }

    protected function execute(Input $input, Output $output)
    {
        $merchantId = $input->getOption('merchant_id');
        $merchantId = $merchantId !== null && $merchantId !== '' ? (int)$merchantId : null;
        try {
            $ret = FansHubBsGateway::syncMainMerchantRates($merchantId);
            $output->writeln(sprintf(
                '[%s] OK merchant#%d %s (%s) updated_channels=%d',
                date('Y-m-d H:i:s'),
                (int)$ret['merchant_id'],
                (string)$ret['merchant_name'],
                (string)$ret['merchant_no'],
                (int)$ret['updated']
            ));
            foreach ((array)$ret['coins'] as $coin => $rates) {
                $output->writeln(sprintf(
                    '  %s collection=%s payment=%s',
                    $coin,
                    (string)($rates['collectionExchangeRate'] ?? ''),
                    (string)($rates['paymentExchangeRate'] ?? '')
                ));
            }
            return 0;
        } catch (\Throwable $e) {
            $output->writeln('[' . date('Y-m-d H:i:s') . '] FAIL ' . $e->getMessage());
            return 1;
        }
    }
}
