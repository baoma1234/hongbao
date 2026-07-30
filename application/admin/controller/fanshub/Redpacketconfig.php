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
            $mineExpire = max(1, (int)($row['mine_expire_seconds'] ?? 100));
            $platformUid = (int)($row['platform_user_id'] ?? 0);
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
            if ($platformUid <= 0) {
                $this->error('请填写平台收款用户 ID');
            }
            FansHubRedPacket::saveConfig([
                'min_amount'                => sprintf('%.2f', $minAmount),
                'min_count'                 => (string)$minCount,
                'max_count'                 => (string)$maxCount,
                'vip_min_count'             => (string)$vipMin,
                'vip_max_count'             => (string)$vipMax,
                'platform_fee_rate'         => sprintf('%.4f', $fee),
                'agent_rebate_rate_default' => sprintf('%.4f', $rebate),
                'agent_rebate_rate_vip'     => sprintf('%.4f', $rebateVip),
                'expire_seconds'            => (string)$expire,
                'mine_expire_seconds'       => (string)$mineExpire,
                'platform_user_id'          => (string)$platformUid,
                'skin_width'                => '750',
                'skin_height'               => '1000',
            ], [
                'min_amount'                => '普通群最低金额',
                'min_count'                 => '普通群最少个数',
                'max_count'                 => '普通群最多个数',
                'vip_min_count'             => 'VIP群最少个数',
                'vip_max_count'             => 'VIP群最多个数',
                'platform_fee_rate'         => '平台抽水比例',
                'agent_rebate_rate_default' => '代理默认返佣',
                'agent_rebate_rate_vip'     => 'VIP群返佣',
                'expire_seconds'            => '普通/手气过期秒数',
                'mine_expire_seconds'       => '扫雷过期秒数',
                'platform_user_id'          => '平台收款用户',
                'skin_width'                => '皮肤宽',
                'skin_height'               => '皮肤高',
            ]);
            $this->success('已保存（IM 需重启后读取最新过期/抽水配置）');
        }
        $this->view->assign('config', FansHubRedPacket::configMap());
        return $this->view->fetch();
    }
}
