<?php

namespace app\common\library;

use think\Db;
use think\Log;

class WithdrawUrge
{
    protected $config = [
        'pid' => 1,
        'withdraw_table' => 'sys_withdraw_unpaid',
        'merch_table' => 'sys_merch_channel',
        'schedule_table' => 'sys_urge_schedule',
        'urge_bot_token' => '',
        'urge_chat_id' => '',
    ];

    protected $lastError = '';

    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->config, $config);
    }

    public function getLastError()
    {
        return $this->lastError;
    }

    /**
     * 根据时间表计算当前应催次数
     */
    public function getExpectedUrgeCount(array $schedules, $elapsedMinutes)
    {
        $triggers = $this->buildTriggerMinutes($schedules, (int)$elapsedMinutes);
        $count = 0;
        foreach ($triggers as $minute) {
            if ($minute <= $elapsedMinutes) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * 生成催单触发时间点：如 15, 20, 25, 30...
     */
    public function buildTriggerMinutes(array $schedules, $maxMinutes)
    {
        $triggers = [];
        foreach ($schedules as $item) {
            if (($item['status'] ?? 'normal') !== 'normal') {
                continue;
            }
            if ($item['type'] === 'fixed' && (int)$item['minutes'] > 0) {
                $triggers[] = (int)$item['minutes'];
            } elseif ($item['type'] === 'repeat' && (int)$item['repeat_interval'] > 0) {
                $start = (int)$item['minutes'] + (int)$item['repeat_interval'];
                $interval = (int)$item['repeat_interval'];
                for ($t = $start; $t <= $maxMinutes; $t += $interval) {
                    $triggers[] = $t;
                }
            }
        }

        sort($triggers);
        return array_values(array_unique($triggers));
    }

    public function getSchedules($pid)
    {
        $query = Db::name($this->config['schedule_table'])
            ->where('pid', $pid)
            ->where('status', 'normal');
        return $query->order('sort asc,id asc')->select();
    }

    /**
     * 新订单入库后首次通知
     */
    public function notifyNewOrder(array $order, $diffMinutes)
    {
        return $this->sendUrgeMessage($order, 1, $diffMinutes, true);
    }

    /**
     * 扫描并执行催单（后续催单，从第2次起）
     */
    public function process($pid = 1)
    {
        $schedules = $this->getSchedules($pid);
        if (empty($schedules)) {
            return ['urged' => 0, 'skipped' => 0, 'msg' => '未配置催单时间表'];
        }

        $ordersQuery = Db::name($this->config['withdraw_table'])
            ->where('pid', $pid)
            ->where('pay_status', 0);
        $orders = $ordersQuery->select();

        $urged = 0;
        $skipped = 0;
        $now = time();

        foreach ($orders as $order) {
            $addtime = (int)($order['addtime'] ?? 0);
            $createTime = (int)($order['create_time'] ?? 0);
            if ($addtime <= 0 || $createTime <= 0) {
                $skipped++;
                continue;
            }

            $diffMinutes = (int)floor(($addtime - $createTime) / 60);
            $minMinutes = $this->getMinMinutes($schedules);
            if ($diffMinutes < $minMinutes) {
                $skipped++;
                continue;
            }

            $elapsedMinutes = (int)floor(($now - $addtime) / 60);
            $expectedCount = $this->getExpectedUrgeCount($schedules, $elapsedMinutes);
            $currentCount = (int)($order['urge_count'] ?? 0);

            if ($expectedCount <= $currentCount) {
                $skipped++;
                continue;
            }

            $sent = $this->sendUrgeMessage($order, $currentCount + 1, $elapsedMinutes, false, $diffMinutes);
            if ($sent) {
                Db::name($this->config['withdraw_table'])
                    ->where('id', $order['id'])
                    ->update([
                        'urge_count' => $currentCount + 1,
                        'last_urge_time' => $now,
                    ]);
                $urged++;
            } else {
                $skipped++;
            }
        }

        return ['urged' => $urged, 'skipped' => $skipped, 'total' => count($orders)];
    }

    public function getMinMinutes(array $schedules)
    {
        if (empty($schedules)) {
            return 15;
        }
        return (int)($schedules[0]['minutes'] ?? 15);
    }

    protected function resolveMerchByOrder(array $order)
    {
        $merchAgentId = $order['merchAgentId'] ?? '';
        if ($merchAgentId === '' || $merchAgentId === null) {
            return null;
        }

        $buildQuery = function ($withPid = true) use ($order, $merchAgentId) {
            $query = Db::name($this->config['merch_table'])
                ->where(function ($q) use ($merchAgentId) {
                    $q->where('merchCode', (string)$merchAgentId)
                        ->whereOr('id', (int)$merchAgentId);
                });
            if ($withPid && !empty($order['pid'])) {
                $query->where('pid', $order['pid']);
            }
            return $query;
        };

        $merch = $buildQuery(true)->find();
        if (!$merch) {
            $merch = $buildQuery(false)->find();
        }

        return $merch;
    }

    protected function sendUrgeMessage(array $order, $urgeNum, $waitMinutes, $isNew = false, $createApplyDiff = null)
    {
        $this->lastError = '';
        $token = trim((string)$this->config['urge_bot_token']);
        if ($token === '') {
            $this->lastError = '未配置机器人 Token，请在 application/extra/finance_sites.php 填写 urge_bot_token';
            Log::write('催单跳过：' . $this->lastError, 'warning');
            return false;
        }

        $merch = $this->resolveMerchByOrder($order);
        if (!$merch) {
            $merchAgentId = $order['merchAgentId'] ?? '';
            $this->lastError = "未找到商户通道，merchAgentId={$merchAgentId}";
            Log::write('催单跳过：' . $this->lastError, 'warning');
            return false;
        }
        if (($merch['status'] ?? 'normal') !== 'normal') {
            $name = $merch['channelName'] ?? ($order['merchAgentId'] ?? '');
            $this->lastError = "商户「{$name}」已停用，不再发送催单通知";
            Log::write('催单跳过：' . $this->lastError . ', order_no=' . ($order['order_no'] ?? ''), 'info');
            return false;
        }

        $chatId = trim((string)($merch['chanel'] ?? ''));
        if ($chatId === '') {
            $chatId = trim((string)$this->config['urge_chat_id']);
        }
        if ($chatId === '') {
            $merchAgentId = $order['merchAgentId'] ?? '';
            $this->lastError = "未找到 Telegram 群组，请为商户 merchAgentId={$merchAgentId} 在【商户通道】配置群组 ID";
            Log::write(
                '催单跳过：' . $this->lastError . ', order_no=' . ($order['order_no'] ?? ''),
                'warning'
            );
            return false;
        }

        $merchName = $merch['channelName'] ?? '';
        $merchCode = $merch['merchCode'] ?? ($order['merchAgentId'] ?? '');
        $createTime = (int)($order['create_time'] ?? 0);
        $applyTime = (int)($order['addtime'] ?? 0);
        if ($createApplyDiff === null && $createTime > 0 && $applyTime > 0) {
            $createApplyDiff = (int)floor(($applyTime - $createTime) / 60);
        }

        $title = $isNew ? '🆕 新订单待处理提醒' : "⏰ 提现催单提醒（第{$urgeNum}次）";
        $text = $title . "\n"
            . "━━━━━━━━━━━━━━\n"
            . "📋 订单号：01{$order['order_no']}\n"
            . "👤 用户：{$order['username']}\n"
            . "💰 金额：{$order['money']}\n";

        $accountText = $this->formatAccountInfo($order['accountInfo'] ?? '');
        if ($accountText !== '') {
            $text .= "━━━━━━━━━━━━━━\n"
                . "💳 收款账户\n"
                . $accountText;
        }

        $text .= "━━━━━━━━━━━━━━\n";
        if ($createApplyDiff !== null) {
            $text .= "⏱ 创建到代付：{$createApplyDiff} 分钟\n";
        }
        if (!$isNew) {
            $text .= "⏱ 代付后等待：{$waitMinutes} 分钟\n";
        }
        $text .= ($merchCode ? "🏪 商户编码：{$merchCode}\n" : '')
            . ($merchName ? "📡 通道：{$merchName}\n" : '')
            . ($createTime ? "🕐 创建：" . date('Y-m-d H:i:s', $createTime) . "\n" : '')
            . ($applyTime ? "🕑 代付：" . date('Y-m-d H:i:s', $applyTime) : '');

        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'chat_id' => $chatId,
            'text' => $text,
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->lastError = '网络请求失败：' . $error;
            Log::write('催单发送失败：' . $this->lastError, 'error');
            return false;
        }

        $result = json_decode($response, true);
        if (empty($result['ok'])) {
            $this->lastError = $result['description'] ?? ('Telegram 返回异常：' . $response);
            Log::write('催单发送失败：' . $this->lastError, 'error');
            return false;
        }

        return true;
    }

    /**
     * 美化 accountInfo JSON 为 Telegram 文本
     * 支持：{"真实姓名":"刘银宇","账号/地址":"13876541047"}
     */
    protected function formatAccountInfo($accountInfo)
    {
        $data = $this->parseAccountInfo($accountInfo);
        if (empty($data)) {
            return '';
        }

        $englishLabelMap = [
            'bankName'       => '银行',
            'bank_name'      => '银行',
            'bank'           => '银行',
            'bankBranch'     => '开户行',
            'bank_branch'    => '开户行',
            'branch'         => '开户行',
            'accountName'    => '姓名',
            'account_name'   => '姓名',
            'realName'       => '真实姓名',
            'real_name'      => '真实姓名',
            'name'           => '姓名',
            'cardNo'         => '卡号',
            'card_no'        => '卡号',
            'bankCard'       => '卡号',
            'bank_card'      => '卡号',
            'accountNo'      => '账号',
            'account_no'     => '账号',
            'account'        => '账号',
            'alipayAccount'  => '支付宝',
            'alipay_account' => '支付宝',
            'alipay'         => '支付宝',
            'usdtAddress'    => 'USDT地址',
            'usdt_address'   => 'USDT地址',
            'address'        => '地址',
            'protocol'       => '协议',
            'province'       => '省份',
            'city'           => '城市',
            'mobile'         => '手机',
            'phone'          => '手机',
            'idCard'         => '身份证',
            'id_card'        => '身份证',
            'remark'         => '备注',
            'type'           => '类型',
            'payType'        => '支付方式',
            'pay_type'       => '支付方式',
        ];

        $preferredOrder = [
            '真实姓名', '姓名', '银行', '开户行', '卡号', '账号', '账号/地址', '支付宝', 'USDT地址', '手机', '备注',
        ];

        $lines = [];
        $usedKeys = [];

        foreach ($preferredOrder as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = $this->stringifyAccountValue($data[$key]);
            if ($value === '') {
                continue;
            }
            $lines[] = "{$key}：{$value}";
            $usedKeys[] = $key;
        }

        foreach ($englishLabelMap as $key => $label) {
            if (in_array($key, $usedKeys, true) || !array_key_exists($key, $data)) {
                continue;
            }
            $value = $this->stringifyAccountValue($data[$key]);
            if ($value === '') {
                continue;
            }
            $lines[] = "{$label}：{$value}";
            $usedKeys[] = $key;
        }

        foreach ($data as $key => $value) {
            if (in_array($key, $usedKeys, true)) {
                continue;
            }
            if (is_array($value)) {
                $value = $this->stringifyAccountValue($value);
                if ($value === '') {
                    continue;
                }
                $label = $this->getAccountFieldLabel((string)$key, $englishLabelMap);
                $lines[] = "{$label}：{$value}";
                continue;
            }
            $value = $this->stringifyAccountValue($value);
            if ($value === '') {
                continue;
            }
            $label = $this->getAccountFieldLabel((string)$key, $englishLabelMap);
            $lines[] = "{$label}：{$value}";
        }

        return $lines ? implode("\n", $lines) . "\n" : '';
    }

    protected function parseAccountInfo($accountInfo)
    {
        if (is_array($accountInfo)) {
            return $accountInfo;
        }
        if (!is_string($accountInfo) || trim($accountInfo) === '') {
            return [];
        }

        $raw = trim($accountInfo);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $decoded = json_decode(stripslashes($raw), true);
        }
        if (!is_array($decoded)) {
            $decoded = json_decode(html_entity_decode($raw, ENT_QUOTES, 'UTF-8'), true);
        }

        return is_array($decoded) ? $decoded : [];
    }

    protected function getAccountFieldLabel($key, array $englishLabelMap)
    {
        if (isset($englishLabelMap[$key])) {
            return $englishLabelMap[$key];
        }
        // 中文键名直接使用，如：真实姓名、账号/地址
        if (preg_match('/[\x{4e00}-\x{9fff}]/u', $key)) {
            return $key;
        }
        return $this->humanizeAccountKey($key);
    }

    protected function stringifyAccountValue($value)
    {
        if (is_bool($value)) {
            return $value ? '是' : '否';
        }
        if (is_scalar($value)) {
            return trim((string)$value);
        }
        return '';
    }

    protected function humanizeAccountKey($key)
    {
        $map = [
            'bankName' => '银行',
            'cardNo' => '卡号',
            'accountName' => '姓名',
        ];
        if (isset($map[$key])) {
            return $map[$key];
        }
        $key = preg_replace('/([a-z])([A-Z])/', '$1 $2', (string)$key);
        $key = str_replace(['_', '-'], ' ', $key);
        return ucwords($key);
    }

    /**
     * 后台手动催单
     */
    public function manualUrge(array $order)
    {
        if ((int)($order['pay_status'] ?? 0) === 1) {
            return ['success' => false, 'msg' => '订单已处理，无需催单'];
        }

        $addtime = (int)($order['addtime'] ?? 0);
        $createTime = (int)($order['create_time'] ?? 0);
        $now = time();
        $elapsedMinutes = $addtime > 0 ? (int)floor(($now - $addtime) / 60) : 0;
        $createApplyDiff = null;
        if ($createTime > 0 && $addtime > 0) {
            $createApplyDiff = (int)floor(($addtime - $createTime) / 60);
        }

        $currentCount = (int)($order['urge_count'] ?? 0);
        $urgeNum = $currentCount + 1;
        $isNew = $currentCount === 0;

        $sent = $this->sendUrgeMessage($order, $urgeNum, $elapsedMinutes, $isNew, $createApplyDiff);
        if (!$sent) {
            return ['success' => false, 'msg' => $this->lastError ?: '催单发送失败'];
        }

        Db::name($this->config['withdraw_table'])
            ->where('id', $order['id'])
            ->update([
                'urge_count'     => $urgeNum,
                'last_urge_time' => $now,
            ]);

        return [
            'success'    => true,
            'msg'        => "催单成功（第{$urgeNum}次）",
            'urge_count' => $urgeNum,
        ];
    }
}
