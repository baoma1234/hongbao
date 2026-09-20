<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;

/**
 * 红宝雨机器人
 *
 * @icon fa fa-umbrella
 */
class Redpacketrain extends Backend
{
    protected $model = null;
    protected $searchFields = 'id,name,group_id,send_user_id';

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Redpacketrain;
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
        $this->view->assign('rainSlots', $this->blankSlots());
        return parent::add();
    }

    public function edit($ids = null)
    {
        if ($this->request->isPost()) {
            $params = $this->normalize($this->request->post('row/a'));
            $this->request->post(['row' => $params]);
            return parent::edit($ids);
        }
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        $this->view->assign('row', $row);
        $this->view->assign('rainSlots', $this->slotsFromJson($row['time_slots'] ?? ''));
        return $this->view->fetch();
    }

    /**
     * 立即发一轮（由 IM 定时进程在约 2 秒内执行，不看当前是否到点）
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
        if (!$idList) {
            $this->error('请先勾选任务');
        }
        $n = $this->model->where('id', 'in', array_values($idList))->where('status', 'normal')->update([
            'force_send' => 1,
            'updatetime' => time(),
        ]);
        if ((int)$n <= 0) {
            $this->error('没有可执行的启用任务');
        }
        $this->success('已排队，聊天定时进程约 2 秒内发出。若刚改过机器人代码，需先重启聊天服务。');
    }

    protected function normalize(array $params)
    {
        $params['name'] = trim((string)($params['name'] ?? ''));
        if ($params['name'] === '') {
            $this->error('请填写任务名');
        }
        $params['group_id'] = max(0, (int)($params['group_id'] ?? 0));
        if ($params['group_id'] <= 0) {
            $this->error('请填写群ID');
        }

        $params['actor_mode'] = ((int)($params['actor_mode'] ?? 1) === 2) ? 2 : 1;
        $params['auto_send'] = !empty($params['auto_send']) ? 1 : 0;
        $params['auto_grab'] = !empty($params['auto_grab']) ? 1 : 0;

        $sendIds = $this->parseIds($params['send_user_ids'] ?? ($params['send_user_id'] ?? ''));
        $params['send_user_ids'] = implode(',', $sendIds);
        $params['send_user_id'] = $sendIds ? (int)$sendIds[0] : 0;
        if ($params['auto_send'] === 1 && !$sendIds) {
            $this->error('请填写发包用户ID（固定发送人，可改）');
        }

        $grabIds = $this->parseIds($params['grab_user_ids'] ?? '');
        $params['grab_user_ids'] = implode(',', $grabIds);
        if ($params['actor_mode'] === 1 && $params['auto_grab'] === 1 && !$grabIds) {
            $this->error('抢包模式一请填写抢包用户ID');
        }

        $params['packet_type'] = (int)($params['packet_type'] ?? 1);
        if (!in_array($params['packet_type'], [1, 4], true)) {
            $params['packet_type'] = 1;
        }

        $amountMin = (int)round((float)($params['amount_min'] ?? 0));
        $amountMax = (int)round((float)($params['amount_max'] ?? 0));
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
        if ($amountMin <= 0 || $amountMin % 10 !== 0 || $amountMax % 10 !== 0) {
            $this->error('金额最小/最大必须是 10 的整数倍（如 10、20、50）');
        }
        $params['amount_min'] = sprintf('%.2f', $amountMin);
        $params['amount_max'] = sprintf('%.2f', $amountMax);
        $params['total_amount'] = $params['amount_min'];
        $params['total_count'] = max(1, min(100, (int)($params['total_count'] ?? 5)));

        $blessing = trim((string)($params['blessing'] ?? ''));
        $params['blessing'] = $blessing !== '' ? mb_substr($blessing, 0, 64) : '恭喜发财';

        $minMs = max(1000, (int)($params['grab_delay_min_ms'] ?? 5000));
        $maxMs = max($minMs, (int)($params['grab_delay_max_ms'] ?? 15000));
        $params['grab_delay_min_ms'] = $minMs;
        $params['grab_delay_max_ms'] = $maxMs;

        $params['bot_grab_cap'] = max(0, min(500, (int)($params['bot_grab_cap'] ?? 1)));
        $pct = (int)($params['bot_grab_pct'] ?? 80);
        if ($pct < 0) {
            $pct = 0;
        }
        if ($pct > 100) {
            $pct = 100;
        }
        $params['bot_grab_pct'] = $pct;
        $sweep = (int)($params['sweep_minutes'] ?? 3);
        if ($sweep < 1) {
            $sweep = 1;
        }
        if ($sweep > 180) {
            $sweep = 180;
        }
        $params['sweep_minutes'] = $sweep;

        $scheduleMode = ((int)($params['schedule_mode'] ?? 1) === 2) ? 2 : 1;
        $params['schedule_mode'] = $scheduleMode;
        if ($scheduleMode === 2) {
            $intervalMin = (int)($params['interval_minutes'] ?? 5);
            if ($intervalMin < 1) {
                $intervalMin = 1;
            }
            if ($intervalMin > 1440) {
                $intervalMin = 1440;
            }
            $intervalCount = (int)($params['interval_count'] ?? 1);
            if ($intervalCount < 1) {
                $this->error('模式2：每轮发包数至少为 1');
            }
            if ($intervalCount > 100) {
                $intervalCount = 100;
            }
            $params['interval_minutes'] = $intervalMin;
            $params['interval_count'] = $intervalCount;
            // 保留已有定点配置，便于切回模式1；未填则存空数组
            $slots = $this->normalizeSlots($params['slots'] ?? null, false);
            $params['time_slots'] = json_encode($slots, JSON_UNESCAPED_UNICODE);
        } else {
            $slots = $this->normalizeSlots($params['slots'] ?? null, true);
            if (!$slots) {
                $this->error('模式1：请至少配置一个开启时间（精确到分）和发包次数');
            }
            $params['time_slots'] = json_encode($slots, JSON_UNESCAPED_UNICODE);
            $params['interval_minutes'] = max(1, min(1440, (int)($params['interval_minutes'] ?? 5)));
            $params['interval_count'] = max(1, min(100, (int)($params['interval_count'] ?? 1)));
        }
        unset($params['slots']);

        $status = (string)($params['status'] ?? 'hidden');
        $params['status'] = $status === 'normal' ? 'normal' : 'hidden';
        $params['remark'] = mb_substr(trim((string)($params['remark'] ?? '')), 0, 255);

        return $params;
    }

    protected function parseIds($raw)
    {
        $raw = str_replace(["\xef\xbc\x8c", '、', '|', "\n", "\r"], ',', (string)$raw);
        $raw = preg_replace('/[^\d,\s;]/', '', $raw);
        $ids = [];
        foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $p) {
            $id = (int)$p;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    /**
     * @param bool $required 为 true 时非法行直接报错；false 时跳过空行/非法行
     */
    protected function normalizeSlots($raw, $required = true)
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach (array_values($raw) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $time = trim((string)($row['time'] ?? ''));
            $countRaw = trim((string)($row['count'] ?? ''));
            if ($time === '' && $countRaw === '') {
                continue;
            }
            if (!preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
                if ($required) {
                    $this->error('开启时间须为时:分，例如 20:30');
                }
                continue;
            }
            $h = (int)$m[1];
            $min = (int)$m[2];
            if ($h > 23 || $min > 59) {
                if ($required) {
                    $this->error('开启时间超出范围');
                }
                continue;
            }
            $count = (int)$countRaw;
            if ($count < 1) {
                if ($required) {
                    $this->error(sprintf('%02d:%02d 的发包次数至少为 1', $h, $min));
                }
                continue;
            }
            $key = sprintf('%02d:%02d', $h, $min);
            $out[$key] = [
                'time'  => $key,
                'count' => min(100, $count),
            ];
            if (count($out) >= 12) {
                break;
            }
        }
        return array_values($out);
    }

    protected function blankSlots()
    {
        $out = [];
        for ($i = 0; $i < 12; $i++) {
            $out[] = ['i' => $i, 'time' => '', 'count' => ''];
        }
        return $out;
    }

    protected function slotsFromJson($raw)
    {
        $arr = json_decode((string)$raw, true);
        $out = [];
        if (is_array($arr)) {
            foreach ($arr as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $out[] = [
                    'i'     => count($out),
                    'time'  => (string)($row['time'] ?? ''),
                    'count' => (int)($row['count'] ?? 0) > 0 ? (int)$row['count'] : '',
                ];
            }
        }
        while (count($out) < 12) {
            $out[] = ['i' => count($out), 'time' => '', 'count' => ''];
        }
        return array_slice($out, 0, 12);
    }
}
