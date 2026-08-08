<?php

namespace app\admin\controller\fanshub;

use app\common\controller\Backend;
use app\common\library\FansHubNiuniuAuto;

/**
 * 尾数牛牛自动购入/领取任务
 *
 * @icon fa fa-android
 */
class Niuniuauto extends Backend
{
    protected $model = null;
    protected $searchFields = 'id,name,group_id';

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\fanshub\Niuniuauto;
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->view->assign('actorModeList', $this->model->getActorModeList());
        $this->assignconfig('statusList', $this->model->getStatusList());
        $this->assignconfig('actorModeList', $this->model->getActorModeList());
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
            $total = ['buy' => 0, 'claim' => 0, 'settle' => 0, 'skip' => 0, 'errors' => [], 'via' => 'admin_force'];
            if (!$idList) {
                $total = FansHubNiuniuAuto::run(0);
            } else {
                foreach ($idList as $id) {
                    $stat = FansHubNiuniuAuto::run($id);
                    $total['buy'] += (int)$stat['buy'];
                    $total['claim'] += (int)$stat['claim'];
                    $total['settle'] += (int)$stat['settle'];
                    $total['skip'] += (int)$stat['skip'];
                    $total['errors'] = array_merge($total['errors'], $stat['errors'] ?: []);
                }
            }
            $msg = sprintf(
                '购入 %d 人 / 领取 %d / 结算 %d / 跳过 %d',
                (int)$total['buy'],
                (int)$total['claim'],
                (int)$total['settle'],
                (int)$total['skip']
            );
            if (!empty($total['errors'])) {
                $msg .= '；说明: ' . implode('; ', $total['errors']);
            }
            if ((int)$total['buy'] <= 0 && (int)$total['claim'] <= 0 && (int)$total['settle'] <= 0) {
                $err = trim(implode('; ', $total['errors'] ?: []));
                $this->error($err !== '' ? $err : '无动作（请确认任务启用、群内已有进行中对局、机器人余额）', null, $total);
            }
            $this->success($msg, null, $total);
        } catch (\think\exception\HttpResponseException $e) {
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
        $started = false;
        try {
            @file_put_contents($logFile, date('Y-m-d H:i:s') . " CMD: {$cmd}\nCWD: {$cwd}\n\n", FILE_APPEND);
            if (function_exists('proc_open')) {
                $proc = @proc_open($cmd, $desc, $pipes, $cwd, null, ['bypass_shell' => false]);
                if (is_resource($proc)) {
                    $started = true;
                    if (isset($pipes[0]) && is_resource($pipes[0])) {
                        fclose($pipes[0]);
                    }
                    usleep(300000);
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

    protected function normalize(array $row)
    {
        $row['group_id'] = max(0, (int)($row['group_id'] ?? 0));
        $row['actor_mode'] = ((int)($row['actor_mode'] ?? 2) === 1) ? 1 : 2;
        $row['buyer_count_min'] = max(1, (int)($row['buyer_count_min'] ?? 3));
        $row['buyer_count_max'] = max($row['buyer_count_min'], (int)($row['buyer_count_max'] ?? 8));
        $row['shares_min'] = max(1, (int)($row['shares_min'] ?? 1));
        $row['shares_max'] = max($row['shares_min'], (int)($row['shares_max'] ?? 3));
        $row['buy_delay_min_ms'] = max(0, (int)($row['buy_delay_min_ms'] ?? 800));
        $row['buy_delay_max_ms'] = max($row['buy_delay_min_ms'], (int)($row['buy_delay_max_ms'] ?? 8000));
        $row['claim_delay_min_ms'] = max(0, (int)($row['claim_delay_min_ms'] ?? 500));
        $row['claim_delay_max_ms'] = max($row['claim_delay_min_ms'], (int)($row['claim_delay_max_ms'] ?? 5000));
        $row['auto_buy'] = !empty($row['auto_buy']) ? 1 : 0;
        $row['auto_claim'] = !empty($row['auto_claim']) ? 1 : 0;
        $row['buy_user_ids'] = trim((string)($row['buy_user_ids'] ?? ''));
        $row['name'] = trim((string)($row['name'] ?? ''));
        $row['remark'] = trim((string)($row['remark'] ?? ''));
        if (!isset($row['status']) || !in_array($row['status'], ['normal', 'hidden'], true)) {
            $row['status'] = 'hidden';
        }
        return $row;
    }
}
