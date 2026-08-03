<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubRedPacket;

/**
 * 红包全局配置
 *
 * @icon fa fa-sliders
 */
class Redpacketconfig extends Backend
{
    protected $noNeedRight = [];

    public function index()
    {
        if ($this->request->isPost()) {
            $row = $this->request->post('row/a', []);
            if (!is_array($row) || !$row) {
                $this->error('无提交数据');
            }
            $minAmount = round((float)($row['min_amount'] ?? 10), 2);
            $minCount = (int)($row['min_count'] ?? 5);
            $maxCount = (int)($row['max_count'] ?? 10);
            $vipMin = (int)($row['vip_min_count'] ?? 5);
            $vipMax = (int)($row['vip_max_count'] ?? 10);
            $fee = round((float)($row['platform_fee_rate'] ?? 0.03), 4);
            $rebate = round((float)($row['agent_rebate_rate_default'] ?? 0.01), 4);
            $rebateVip = round((float)($row['agent_rebate_rate_vip'] ?? 0.01), 4);
            $expire = max(1, (int)($row['expire_seconds'] ?? 60));
            $mineExpire = max(1, (int)($row['mine_expire_seconds'] ?? 180));
            $platformUid = (int)($row['platform_user_id'] ?? 0);
            $mineFee = round((float)($row['mine_platform_fee_rate'] ?? $fee), 4);
            $mineRebate = round((float)($row['mine_agent_rebate_rate_default'] ?? $rebate), 4);
            $mineRebateVip = round((float)($row['mine_agent_rebate_rate_vip'] ?? $rebateVip), 4);
            $minePlatformUid = (int)($row['mine_platform_user_id'] ?? $platformUid);
            $rate5 = round((float)($row['mine_compensate_rate_5'] ?? 1.5), 4);
            $rate7 = round((float)($row['mine_compensate_rate_7'] ?? 1.2), 4);
            $rate9 = round((float)($row['mine_compensate_rate_9'] ?? 1.0), 4);
            $userRpExpire = max(1, (int)($row['user_rp_expire_seconds'] ?? 1800));
            $userRpMinAmount = round((float)($row['user_rp_min_amount'] ?? $minAmount), 2);
            $userRpMinCount = max(1, (int)($row['user_rp_min_count'] ?? 1));
            $userRpMaxCount = max($userRpMinCount, (int)($row['user_rp_max_count'] ?? 100));
            $userRpFee = round((float)($row['user_rp_platform_fee_rate'] ?? $fee), 4);
            $userRpRebate = round((float)($row['user_rp_agent_rebate_rate_default'] ?? $rebate), 4);
            $userRpRebateVip = round((float)($row['user_rp_agent_rebate_rate_vip'] ?? $rebateVip), 4);
            $userRpPlatformUid = (int)($row['user_rp_platform_user_id'] ?? $platformUid);
            if ($minAmount <= 0) {
                $this->error('最低金额无效');
            }
            if ($minCount < 1 || $maxCount < $minCount) {
                $this->error('个数上下限无效');
            }
            if ($vipMin < 1 || $vipMax < $vipMin) {
                $this->error('VIP 个数上下限无效');
            }
            if ($fee < 0 || $fee > 1 || $rebate < 0 || $rebate > 1 || $rebateVip < 0 || $rebateVip > 1) {
                $this->error('比例须在 0～1 之间（如 0.03=3%）');
            }
            if ($mineFee < 0 || $mineFee > 1 || $mineRebate < 0 || $mineRebate > 1 || $mineRebateVip < 0 || $mineRebateVip > 1) {
                $this->error('扫雷比例须在 0～1 之间（如 0.03=3%）');
            }
            if ($userRpFee < 0 || $userRpFee > 1 || $userRpRebate < 0 || $userRpRebate > 1 || $userRpRebateVip < 0 || $userRpRebateVip > 1) {
                $this->error('普通用户群红宝比例须在 0～1 之间（如 0.03=3%）');
            }
            if ($userRpMinAmount <= 0) {
                $this->error('普通用户群红宝最低金额无效');
            }
            if ($rate5 <= 0 || $rate7 <= 0 || $rate9 <= 0) {
                $this->error('扫雷赔付倍率须大于 0');
            }
            if ($platformUid <= 0) {
                $this->error('请填写平台收款用户 ID');
            }
            if ($minePlatformUid <= 0) {
                $this->error('请填写扫雷平台收款用户 ID');
            }
            if ($userRpPlatformUid <= 0) {
                $this->error('请填写普通用户群红宝平台收款用户 ID');
            }
            FansHubRedPacket::saveConfig([
                'min_amount'                      => sprintf('%.2f', $minAmount),
                'min_count'                       => (string)$minCount,
                'max_count'                       => (string)$maxCount,
                'vip_min_count'                   => (string)$vipMin,
                'vip_max_count'                   => (string)$vipMax,
                'platform_fee_rate'               => sprintf('%.4f', $fee),
                'agent_rebate_rate_default'       => sprintf('%.4f', $rebate),
                'agent_rebate_rate_vip'           => sprintf('%.4f', $rebateVip),
                'expire_seconds'                  => (string)$expire,
                'mine_expire_seconds'             => (string)$mineExpire,
                'platform_user_id'                => (string)$platformUid,
                'mine_compensate_rate_5'          => sprintf('%.4f', $rate5),
                'mine_compensate_rate_7'          => sprintf('%.4f', $rate7),
                'mine_compensate_rate_9'          => sprintf('%.4f', $rate9),
                'mine_platform_fee_rate'          => sprintf('%.4f', $mineFee),
                'mine_agent_rebate_rate_default'  => sprintf('%.4f', $mineRebate),
                'mine_agent_rebate_rate_vip'      => sprintf('%.4f', $mineRebateVip),
                'mine_platform_user_id'           => (string)$minePlatformUid,
                'user_rp_expire_seconds'            => (string)$userRpExpire,
                'user_rp_min_amount'                => sprintf('%.2f', $userRpMinAmount),
                'user_rp_min_count'                 => (string)$userRpMinCount,
                'user_rp_max_count'                 => (string)$userRpMaxCount,
                'user_rp_platform_fee_rate'         => sprintf('%.4f', $userRpFee),
                'user_rp_agent_rebate_rate_default' => sprintf('%.4f', $userRpRebate),
                'user_rp_agent_rebate_rate_vip'     => sprintf('%.4f', $userRpRebateVip),
                'user_rp_platform_user_id'          => (string)$userRpPlatformUid,
                'skin_width'                      => '750',
                'skin_height'                     => '1000',
            ], [
                'min_amount'                      => '普通群最低金额',
                'min_count'                       => '普通群最少个数',
                'max_count'                       => '普通群最多个数',
                'vip_min_count'                   => 'VIP群最少个数',
                'vip_max_count'                   => 'VIP群最多个数',
                'platform_fee_rate'               => '平台抽水比例',
                'agent_rebate_rate_default'       => '代理默认返佣',
                'agent_rebate_rate_vip'           => 'VIP群返佣',
                'expire_seconds'                  => '拼手气过期秒数',
                'mine_expire_seconds'             => '扫雷过期秒数（默认180=3分钟）',
                'platform_user_id'                => '平台收款用户',
                'mine_compensate_rate_5'          => '扫雷5包赔付倍率',
                'mine_compensate_rate_7'          => '扫雷7包赔付倍率',
                'mine_compensate_rate_9'          => '扫雷9包赔付倍率',
                'mine_platform_fee_rate'          => '扫雷平台抽水比例',
                'mine_agent_rebate_rate_default'  => '扫雷代理返佣（普通）',
                'mine_agent_rebate_rate_vip'      => '扫雷代理返佣（VIP）',
                'mine_platform_user_id'           => '扫雷平台收款用户',
                'user_rp_expire_seconds'            => '普通用户群红宝过期秒数（默认1800=30分钟）',
                'user_rp_min_amount'                => '普通用户群红宝最低金额',
                'user_rp_min_count'                 => '普通用户群红宝最少个数',
                'user_rp_max_count'                 => '普通用户群红宝最多个数',
                'user_rp_platform_fee_rate'         => '普通用户群红宝平台抽水',
                'user_rp_agent_rebate_rate_default' => '普通用户群红宝代理返佣',
                'user_rp_agent_rebate_rate_vip'     => '普通用户群红宝代理返佣（VIP）',
                'user_rp_platform_user_id'          => '普通用户群红宝平台收款用户',
                'skin_width'                      => '皮肤宽',
                'skin_height'                     => '皮肤高',
            ]);
            $this->success('已保存（IM 需重启后读取最新过期/抽水配置）');
        }
        $this->view->assign('config', FansHubRedPacket::configMap());
        return $this->view->fetch();
    }
}
