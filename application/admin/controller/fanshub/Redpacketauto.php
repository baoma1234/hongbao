<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubRpAuto;

/**
 * 红包自动发抢任务
 *
 * @icon fa fa-magic
 */
class Redpacketauto extends Backend
{
    protected $model = null;
    protected $searchFields = 'id,name,group_id,send_user_id';

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Redpacketauto;
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->view->assign('packetTypeList', $this->model->getPacketTypeList());
        $this->assignconfig('statusList', $this->model->getStatusList());
        $this->assignconfig('packetTypeList', $this->model->getPacketTypeList());
    }

    public function add()
    {
        if ($this->request->isPost()) {
            $params = $this->normalize($this->request->post('row/a'));
            $this->request->post(['row' => $params]);
        }
        return parent::add();
    }

    public function edit($ids = null)
    {
        if ($this->request->isPost()) {
            $params = $this->normalize($this->request->post('row/a'));
            $this->request->post(['row' => $params]);
        }
        return parent::edit($ids);
    }

    /**
     * 立即执行一次（可指定 ids，逗号分隔）
     * 强制发包：不受 IM 心跳「跳过」与间隔限制；群内仍有待领包时仍会跳过并说明原因
     */
    public function runonce($ids = null)
    {
        $raw = (string)($ids ?: $this->request->post('ids', $this->request->get('ids', '')));
        $idList = [];
        foreach (preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $p) {
            $id = (int)$p;
            if ($id > 0) {
                $idList[$id] = $id;
            }
        }
        try {
            $total = ['send' => 0, 'grab' => 0, 'skip' => 0, 'errors' => [], 'via' => 'admin_force'];
            if (!$idList) {
                $stat = FansHubRpAuto::run(0, true);
                $total = $stat;
            } else {
                foreach ($idList as $id) {
                    $stat = FansHubRpAuto::run($id, true);
                    $total['send'] += (int)$stat['send'];
                    $total['grab'] += (int)$stat['grab'];
                    $total['skip'] += (int)$stat['skip'];
                    $total['errors'] = array_merge($total['errors'], $stat['errors'] ?: []);
                    if (!empty($stat['via'])) {
                        $total['via'] = $stat['via'];
                    }
                }
            }
            $msg = sprintf(
                '发包 %d / 抢包 %d / 跳过 %d',
                (int)$total['send'],
                (int)$total['grab'],
                (int)$total['skip']
            );
            if (!empty($total['errors'])) {
                $msg .= '；说明: ' . implode('; ', $total['errors']);
            }
            if ((int)$total['send'] <= 0 && (int)$total['grab'] <= 0) {
                $err = trim(implode('; ', $total['errors'] ?: []));
                $this->error($err !== '' ? $err : '未发出红包（请检查群ID、余额、待领包与任务配置）', null, $total);
            }
            $this->success($msg, null, $total);
        } catch (\think\exception\HttpResponseException $e) {
            // success/error 通过抛出该异常结束请求，必须原样抛出
            throw $e;
        } catch (\Throwable $e) {
            $msg = trim($e->getMessage());
            if ($msg === '') {
                $msg = '执行失败: ' . get_class($e);
            }
            $this->error($msg);
        }
    }

    protected function normalize(array $params)
    {
        $params['name'] = trim((string)($params['name'] ?? ''));
        $params['group_id'] = max(0, (int)($params['group_id'] ?? 0));

        // 发包 UID：支持多人逗号分隔，随机选一个发
        $sendRaw = (string)($params['send_user_ids'] ?? '');
        if ($sendRaw === '' && !empty($params['send_user_id'])) {
            $sendRaw = (string)$params['send_user_id'];
        }
        $sendRaw = str_replace(["\xef\xbc\x8c", '、', '|', "\n", "\r"], ',', $sendRaw);
        $sendRaw = preg_replace('/[^\d,\s;]/', '', $sendRaw);
        $sendIds = [];
        foreach (preg_split('/[\s,;]+/', $sendRaw, -1, PREG_SPLIT_NO_EMPTY) as $p) {
            $id = (int)$p;
            if ($id > 0) {
                $sendIds[$id] = $id;
            }
        }
        $sendIds = array_values($sendIds);
        $params['send_user_ids'] = implode(',', $sendIds);
        $params['send_user_id'] = $sendIds ? (int)$sendIds[0] : 0;

        $params['packet_type'] = (int)($params['packet_type'] ?? 2);
        if (!in_array($params['packet_type'], [1, 2, 3, 5], true)) {
            $params['packet_type'] = 2;
        }
        // 金额区间：只允许整数元；最小=最大 → 固定；否则随机整数
        $amountMin = (float)($params['amount_min'] ?? 0);
        $amountMax = (float)($params['amount_max'] ?? 0);
        $legacyAmt = (float)($params['total_amount'] ?? 0);
        if ($amountMin <= 0 && $legacyAmt > 0) {
            $amountMin = $legacyAmt;
        }
        if ($amountMax <= 0 && $legacyAmt > 0) {
            $amountMax = $legacyAmt;
        }
        if ($amountMin <= 0 && $amountMax > 0) {
            $amountMin = $amountMax;
        }
        if ($amountMax <= 0 && $amountMin > 0) {
            $amountMax = $amountMin;
        }
        if ($amountMax < $amountMin) {
            $tmp = $amountMin;
            $amountMin = $amountMax;
            $amountMax = $tmp;
        }
        $amountMin = max(0, (int)round($amountMin));
        $amountMax = max($amountMin, (int)round($amountMax));
        $params['amount_min'] = sprintf('%.2f', $amountMin);
        $params['amount_max'] = sprintf('%.2f', $amountMax);
        $params['total_amount'] = $params['amount_min']; // 列表兼容展示下限
        $params['total_count'] = max(1, (int)($params['total_count'] ?? 5));
        $params['blessing'] = trim((string)($params['blessing'] ?? '恭喜发财')) ?: '恭喜发财';
        $params['mine_digit'] = 0; // 埋雷雷号运行时随机，后台不配置
        $params['interval_sec'] = max(5, (int)($params['interval_sec'] ?? 60));
        $params['burst_count'] = max(1, (int)($params['burst_count'] ?? 1));
        $params['burst_window_sec'] = max(0, (int)($params['burst_window_sec'] ?? 0));
        if ($params['burst_window_sec'] > 0 && $params['burst_window_sec'] < 30) {
            $params['burst_window_sec'] = 30;
        }
        if ($params['burst_count'] > 1 && $params['burst_window_sec'] <= 0) {
            // 突发包数>1 但未填时间窗：默认用 间隔×包数 作窗口
            $params['burst_window_sec'] = max(60, $params['interval_sec'] * $params['burst_count']);
        }
        $params['auto_send'] = !empty($params['auto_send']) ? 1 : 0;
        $params['auto_grab'] = !empty($params['auto_grab']) ? 1 : 0;
        $params['grab_user_ids'] = preg_replace(
            '/[^\d,\s;]/',
            '',
            str_replace(["\xef\xbc\x8c", '、', '|'], ',', (string)($params['grab_user_ids'] ?? ''))
        );
        $params['grab_delay_min_ms'] = max(5000, (int)($params['grab_delay_min_ms'] ?? 5000));
        $params['grab_delay_max_ms'] = max($params['grab_delay_min_ms'], (int)($params['grab_delay_max_ms'] ?? 15000));
        if ($params['grab_delay_max_ms'] > 120000) {
            $params['grab_delay_max_ms'] = 120000;
        }
        $params['max_per_day'] = max(0, (int)($params['max_per_day'] ?? 100));
        $params['status'] = ($params['status'] ?? '') === 'normal' ? 'normal' : 'hidden';
        $params['remark'] = mb_substr(trim((string)($params['remark'] ?? '')), 0, 255);
        if ($params['name'] === '') {
            $params['name'] = '群' . $params['group_id'];
        }
        if ($params['group_id'] <= 0) {
            $this->error('请填写群 ID');
        }
        $group = \think\Db::name('chat_groups')->where('id', $params['group_id'])->find();
        if (!$group) {
            $this->error('群不存在: #' . $params['group_id']);
        }
        if ($params['auto_send'] && $params['send_user_id'] <= 0) {
            $this->error('自动发包需填写发包用户 ID（可多个，逗号分隔）');
        }
        if ($params['auto_send'] && (float)$params['amount_min'] <= 0) {
            $this->error('请填写红包金额（最小/最大）');
        }
        if ($params['auto_grab'] && trim($params['grab_user_ids']) === '') {
            $this->error('自动抢包需填写抢包用户 ID（逗号分隔）');
        }
        return $params;
    }
}
