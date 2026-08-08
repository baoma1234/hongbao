<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubRedPacket;

/**
 * 红宝尾数牛牛 · 单独全局配置
 *
 * @icon fa fa-bullseye
 */
class Niuniuconfig extends Backend
{
    protected $noNeedRight = [];

    public function index()
    {
        if ($this->request->isPost()) {
            $row = $this->request->post('row/a', []);
            if (!is_array($row) || !$row) {
                $this->error('无提交数据');
            }
            $price = round((float)($row['niuniu_share_price'] ?? 100), 2);
            $buySec = max(30, (int)($row['niuniu_buy_seconds'] ?? 120));
            $claimSec = max(15, (int)($row['niuniu_claim_seconds'] ?? 60));
            $fee = round((float)($row['niuniu_fee_rate'] ?? 0.03), 4);
            $nnRate = round((float)($row['niuniu_pool_rate'] ?? 0.6), 4);
            $secRate = round((float)($row['niuniu_secondary_rate'] ?? 0.4), 4);
            $platformUid = (int)($row['niuniu_platform_user_id'] ?? 0);
            $drandApi = trim((string)($row['niuniu_drand_api'] ?? 'https://api.drand.sh'));
            $drandPeriod = max(1, (int)($row['niuniu_drand_period'] ?? 30));
            $enabled = ((int)($row['niuniu_enabled_global'] ?? 1) === 1) ? '1' : '0';
            $rule = trim((string)($row['niuniu_rule_text'] ?? ''));
            if ($price <= 0) {
                $this->error('每份价格无效');
            }
            if ($fee < 0 || $fee > 1 || $nnRate < 0 || $nnRate > 1 || $secRate < 0 || $secRate > 1) {
                $this->error('比例须在 0～1 之间');
            }
            if (abs(($nnRate + $secRate) - 1.0) > 0.0001) {
                $this->error('牛牛占比 + 次级占比须等于 1');
            }
            if ($platformUid <= 0) {
                $this->error('平台收款用户无效');
            }
            if ($drandApi === '') {
                $this->error('drand API 不能为空');
            }
            FansHubRedPacket::saveConfig([
                'niuniu_share_price'      => sprintf('%.2f', $price),
                'niuniu_buy_seconds'      => (string)$buySec,
                'niuniu_claim_seconds'    => (string)$claimSec,
                'niuniu_fee_rate'         => sprintf('%.4f', $fee),
                'niuniu_pool_rate'        => sprintf('%.4f', $nnRate),
                'niuniu_secondary_rate'   => sprintf('%.4f', $secRate),
                'niuniu_platform_user_id' => (string)$platformUid,
                'niuniu_drand_api'        => $drandApi,
                'niuniu_drand_period'     => (string)$drandPeriod,
                'niuniu_enabled_global'   => $enabled,
                'niuniu_rule_text'        => $rule,
            ], [
                'niuniu_share_price'      => '每份购入积分',
                'niuniu_buy_seconds'      => '购入倒计时秒',
                'niuniu_claim_seconds'    => '领取/结算等待秒',
                'niuniu_fee_rate'         => '平台手续费',
                'niuniu_pool_rate'        => '牛牛组奖金占比',
                'niuniu_secondary_rate'   => '次级组奖金占比',
                'niuniu_platform_user_id' => '手续费入账UID',
                'niuniu_drand_api'        => 'drand API',
                'niuniu_drand_period'     => 'drand 周期秒',
                'niuniu_enabled_global'   => '全局开关',
                'niuniu_rule_text'        => '默认规则文案',
            ]);
            $this->success('保存成功（需重启 IM / cron 进程生效）');
        }
        $this->view->assign('config', FansHubRedPacket::configMap());
        return $this->view->fetch();
    }
}
