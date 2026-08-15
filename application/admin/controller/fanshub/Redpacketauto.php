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
        $this->view->assign('intervalWinSlots', $this->defaultIntervalWinSlots());
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
        $this->view->assign('intervalWinSlots', $this->slotsFromIntervalWindows($row['interval_windows'] ?? null));
        return $this->view->fetch();
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

    /**
     * 一键重启聊天服务（执行 im-server/scripts/restart-all.sh 或 Windows .ps1）
     */
    public function restartim()
    {
        if (!$this->request->isPost()) {
            $this->error('请使用 POST 请求');
        }
        $scriptDir = ROOT_PATH . 'im-server' . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR;
        $isWin = stripos(PHP_OS, 'WIN') === 0;
        if ($isWin) {
            $script = $scriptDir . 'restart-all.ps1';
            if (!is_file($script)) {
                $this->error('找不到脚本: ' . $script);
            }
            $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -File ' . escapeshellarg($script);
        } else {
            $script = $scriptDir . 'restart-all.sh';
            if (!is_file($script)) {
                $this->error('找不到脚本: ' . $script);
            }
            $cmd = 'bash ' . escapeshellarg($script);
        }

        $logDir = ROOT_PATH . 'runtime' . DIRECTORY_SEPARATOR . 'log';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . DIRECTORY_SEPARATOR . 'im_restart_' . date('Ymd_His') . '.log';

        $desc = [
            0 => ['pipe', 'r'],
            1 => ['file', $logFile, 'a'],
            2 => ['file', $logFile, 'a'],
        ];
        $cwd = dirname($scriptDir);
        $proc = null;
        $started = false;
        try {
            @file_put_contents($logFile, date('Y-m-d H:i:s') . " CMD: {$cmd}\nCWD: {$cwd}\n\n", FILE_APPEND);
            if (function_exists('proc_open')) {
                $proc = @proc_open($cmd, $desc, $pipes, $cwd, null, ['bypass_shell' => false]);
                if (is_resource($proc)) {
                    $started = true;
                    // 非阻塞：关掉 stdin，让脚本在后台跑完；前端稍后健康检查
                    if (isset($pipes[0]) && is_resource($pipes[0])) {
                        fclose($pipes[0]);
                    }
                    // 短暂等待确认进程已启动
                    usleep(300000);
                    $status = proc_get_status($proc);
                    if (!empty($status['running'])) {
                        // 脱离等待，避免 PHP-FPM 卡死；子进程继续
                        if (function_exists('proc_close')) {
                            // Windows 下无法真正 detach；仍关闭句柄
                        }
                    }
                    @proc_close($proc);
                }
            }
            if (!$started && function_exists('popen')) {
                $ph = @popen($cmd . ' > ' . escapeshellarg($logFile) . ' 2>&1', 'r');
                if (is_resource($ph)) {
                    $started = true;
                    @pclose($ph);
                }
            }
            if (!$started && function_exists('exec')) {
                $out = [];
                $code = 1;
                @exec($cmd . ' 2>&1', $out, $code);
                @file_put_contents($logFile, implode("\n", $out) . "\nexit={$code}\n", FILE_APPEND);
                $started = true;
            }
        } catch (\Throwable $e) {
            $this->error('启动重启失败: ' . $e->getMessage());
        }

        if (!$started) {
            $this->error('服务器禁用了 proc_open/popen/exec，无法从后台重启。请 SSH 执行: bash im-server/scripts/restart-all.sh');
        }

        // 健康探测（最多约 8s）
        $health = ['ok' => false, 'http' => '', 'tries' => 0];
        $healthUrl = 'http://127.0.0.1:17273/';
        for ($i = 0; $i < 8; $i++) {
            $health['tries']++;
            usleep(1000000);
            $ctx = stream_context_create(['http' => ['timeout' => 1.5]]);
            $body = @file_get_contents($healthUrl, false, $ctx);
            if ($body !== false && $body !== '') {
                $health['http'] = substr($body, 0, 200);
                if (stripos($body, 'ok') !== false || stripos($body, '{') !== false) {
                    $health['ok'] = true;
                    break;
                }
            }
        }
        @file_put_contents(
            $logFile,
            "\n" . date('Y-m-d H:i:s') . ' health=' . json_encode($health, JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND
        );

        $msg = $health['ok']
            ? '聊天服务已重启，健康检查通过'
            : '重启命令已执行，健康检查暂未通过（请稍候再试或查看日志）';
        $this->success($msg, null, [
            'log'    => str_replace(ROOT_PATH, '', $logFile),
            'health' => $health,
            'os'     => PHP_OS,
            'script' => $isWin ? 'restart-all.ps1' : 'restart-all.sh',
        ]);
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
        // 金额区间：必须是 10 的整数倍；最小=最大 → 固定；否则随机（步长 10）
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
        $amountMin = (int)round($amountMin);
        $amountMax = (int)round($amountMax);
        if ($amountMin > 0 && $amountMin % 10 !== 0) {
            $this->error('金额最小/最大必须是 10 的整数倍（如 10、20、50）');
        }
        if ($amountMax > 0 && $amountMax % 10 !== 0) {
            $this->error('金额最小/最大必须是 10 的整数倍（如 10、20、50）');
        }
        $amountMin = max(0, $amountMin);
        $amountMax = max($amountMin, $amountMax);
        $params['amount_min'] = sprintf('%.2f', $amountMin);
        $params['amount_max'] = sprintf('%.2f', $amountMax);
        $params['total_amount'] = $params['amount_min']; // 列表兼容展示下限
        $params['total_count'] = max(1, (int)($params['total_count'] ?? 5));
        if ((int)$params['packet_type'] === 3) {
            // 埋雷个数运行时随机 5/7/9，后台个数仅作占位展示
            if (!in_array((int)$params['total_count'], [5, 7, 9], true)) {
                $params['total_count'] = 5;
            }
        }
        $params['blessing'] = trim((string)($params['blessing'] ?? '恭喜发财')) ?: '恭喜发财';
        $params['mine_digit'] = 0; // 埋雷雷号运行时随机，后台不配置
        $params['interval_sec'] = max(5, (int)($params['interval_sec'] ?? 60));
        $params['interval_windows'] = $this->normalizeIntervalWindowsFromForm($params);
        unset($params['iw']);
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
        $params['actor_mode'] = ((int)($params['actor_mode'] ?? 1) === 2) ? 2 : 1;
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
        if ($params['auto_send'] && $params['actor_mode'] === 1 && $params['send_user_id'] <= 0) {
            $this->error('模式一自动发包需填写发包用户 ID（可多个，逗号分隔）');
        }
        if ($params['auto_send'] && (float)$params['amount_min'] <= 0) {
            $this->error('请填写红包金额（最小/最大）');
        }
        if ($params['auto_grab'] && $params['actor_mode'] === 1 && trim($params['grab_user_ids']) === '') {
            $this->error('模式一自动抢包需填写抢包用户 ID（逗号分隔）');
        }
        return $params;
    }

    /**
     * 表单最多 3 段：开始小时 / 结束小时 / 间隔秒 → 存 JSON
     * 三段都空 → []（全天只用 interval_sec）
     */
    protected function normalizeIntervalWindowsFromForm(array $params)
    {
        $slots = $params['iw'] ?? null;
        if (!is_array($slots)) {
            // 兼容旧 JSON 字段
            return $this->normalizeIntervalWindows($params['interval_windows'] ?? null);
        }
        $out = [];
        $idx = 0;
        foreach (array_values($slots) as $row) {
            if ($idx >= 3) {
                break;
            }
            $idx++;
            if (!is_array($row)) {
                continue;
            }
            $startRaw = trim((string)($row['start_hour'] ?? ''));
            $endRaw = trim((string)($row['end_hour'] ?? ''));
            $ivRaw = trim((string)($row['interval_sec'] ?? ''));
            if ($startRaw === '' && $endRaw === '' && $ivRaw === '') {
                continue;
            }
            if ($startRaw === '' || $endRaw === '' || $ivRaw === '') {
                $this->error('时段第 ' . $idx . ' 段请填齐：开始小时、结束小时、间隔秒（或整段留空）');
            }
            $start = (int)$startRaw;
            $end = (int)$endRaw;
            $iv = (int)$ivRaw;
            if ($start < 0 || $start > 23 || $end < 0 || $end > 23) {
                $this->error('时段小时须在 0～23');
            }
            if ($iv < 5) {
                $this->error('时段间隔至少 5 秒');
            }
            $out[] = [
                'start_hour'   => $start,
                'end_hour'     => $end,
                'interval_sec' => $iv,
            ];
        }
        return json_encode($out, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 时段间隔 JSON：空=用系统默认(20-23→30s,0-7→120s)；[]=关闭时段只用 interval_sec
     */
    protected function normalizeIntervalWindows($raw)
    {
        if ($raw === null) {
            return null;
        }
        if (is_array($raw)) {
            $arr = $raw;
        } else {
            $trim = trim((string)$raw);
            if ($trim === '') {
                return null;
            }
            $arr = json_decode($trim, true);
            if (!is_array($arr)) {
                $this->error('时段间隔格式错误');
            }
        }
        $out = [];
        foreach ($arr as $row) {
            if (!is_array($row)) {
                continue;
            }
            $start = (int)($row['start_hour'] ?? $row['start'] ?? -1);
            $end = (int)($row['end_hour'] ?? $row['end'] ?? -1);
            $iv = (int)($row['interval_sec'] ?? $row['interval'] ?? 0);
            if ($start < 0 || $start > 23 || $end < 0 || $end > 23) {
                $this->error('时段小时须在 0～23');
            }
            if ($iv < 5) {
                $this->error('时段间隔至少 5 秒');
            }
            $out[] = [
                'start_hour'   => $start,
                'end_hour'     => $end,
                'interval_sec' => $iv,
            ];
            if (count($out) >= 3) {
                break;
            }
        }
        return json_encode($out, JSON_UNESCAPED_UNICODE);
    }

    protected function defaultIntervalWinSlots()
    {
        return [
            ['start_hour' => '20', 'end_hour' => '23', 'interval_sec' => '30'],
            ['start_hour' => '0', 'end_hour' => '7', 'interval_sec' => '120'],
            ['start_hour' => '', 'end_hour' => '', 'interval_sec' => ''],
        ];
    }

    protected function slotsFromIntervalWindows($raw)
    {
        $slots = [
            ['start_hour' => '', 'end_hour' => '', 'interval_sec' => ''],
            ['start_hour' => '', 'end_hour' => '', 'interval_sec' => ''],
            ['start_hour' => '', 'end_hour' => '', 'interval_sec' => ''],
        ];
        $trim = trim((string)$raw);
        if ($trim === '' || $trim === 'null') {
            return $this->defaultIntervalWinSlots();
        }
        $arr = json_decode($trim, true);
        if (!is_array($arr)) {
            return $this->defaultIntervalWinSlots();
        }
        for ($i = 0; $i < 3; $i++) {
            if (!isset($arr[$i]) || !is_array($arr[$i])) {
                continue;
            }
            $row = $arr[$i];
            $slots[$i] = [
                'start_hour'   => isset($row['start_hour']) ? (string)(int)$row['start_hour'] : '',
                'end_hour'     => isset($row['end_hour']) ? (string)(int)$row['end_hour'] : '',
                'interval_sec' => isset($row['interval_sec']) ? (string)(int)$row['interval_sec'] : '',
            ];
        }
        return $slots;
    }
}
