<?php

namespace app\common\library\finance;

use app\common\library\WithdrawUrge;
use think\Db;

/**
 * 未支付提现订单同步
 */
class WithdrawSync
{
    protected $config;
    protected $auth;

    public function __construct($pid = null, array $config = null, FinanceAuth $auth = null)
    {
        $this->config = $config ?: FinanceConfig::forPid($pid);
        $this->auth = $auth ?: new FinanceAuth($this->config);
    }

    public function sync()
    {
        $syncResult = $this->fetchRecords();
        return $this->saveRecords(
            $syncResult['records'],
            $syncResult['create_start_time'],
            $syncResult['create_end_time']
        );
    }

    protected function fetchRecords()
    {
        $params = $this->buildParams();
        $allRecords = [];
        $current = 1;

        do {
            $params['current'] = $current;
            $result = $this->auth->sendAuthRequest(
                $this->config['withdraw_all_url'],
                json_encode($params, JSON_UNESCAPED_UNICODE),
                'withdraw_all'
            );

            $list = $result['data']['data'] ?? [];
            $total = (int)($result['data']['total'] ?? 0);
            if (!empty($list)) {
                $allRecords = array_merge($allRecords, $list);
            }

            if (empty($list) || count($allRecords) >= $total) {
                break;
            }
            $current++;
        } while ($current <= 50);

        return [
            'records'           => $allRecords,
            'create_start_time' => $params['create_start_time'],
            'create_end_time'   => $params['create_end_time'],
        ];
    }

    protected function buildParams()
    {
        $createEndTime = time();
        $createStartTime = $createEndTime - 4 * 3600;

        return [
            'status'            => '3',
            'memberCurrency'    => 'CNY',
            'lockBySelf'        => false,
            'current'           => 1,
            'size'              => 100,
            'timeType'          => 0,
            'create_start_time' => $createStartTime,
            'create_end_time'   => $createEndTime,
            'queryType'         => 3,
            'isRefresh'         => false,
        ];
    }

    protected function saveRecords(array $records, $createStartTime, $createEndTime)
    {
        $table = $this->config['withdraw_table'];
        $pid = (int)$this->config['pid'];
        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $notified = 0;
        $activeOrderNos = [];
        $minMinutes = $this->getMinUrgeMinutes();
        $urgeService = new WithdrawUrge(FinanceConfig::getUrgeConfig($pid));

        foreach ($records as $row) {
            $merchAgentId = (int)($row['merchAgentId'] ?? 0);
            if ($merchAgentId <= 0) {
                continue;
            }

            $orderNo = (string)($row['order_no'] ?? '');
            if ($orderNo === '') {
                continue;
            }

            $createTime = (int)($row['time']['create_time'] ?? 0);
            $applyTime = (int)($row['time']['apply_time'] ?? 0);
            if ($createTime <= 0 || $applyTime <= 0) {
                $skipped++;
                continue;
            }

            $diffMinutes = (int)floor(($applyTime - $createTime) / 60);
            $activeOrderNos[] = $orderNo;

            $data = [
                'username'     => (string)($row['username'] ?? ''),
                'money'        => (string)($row['money'] ?? '0'),
                'accountInfo'  => json_encode($row['accountInfo'] ?? [], JSON_UNESCAPED_UNICODE),
                'order_no'     => $orderNo,
                'merchAgentId' => $merchAgentId,
                'create_time'  => $createTime,
                'addtime'      => $applyTime,
            ];

            $exists = Db::name($table)
                ->where('pid', $pid)
                ->where('order_no', $orderNo)
                ->find();
            if ($exists) {
                Db::name($table)->where('id', $exists['id'])->update($data);
                $updated++;
                continue;
            }

            if ($diffMinutes < $minMinutes) {
                $skipped++;
                continue;
            }

            $data['pid'] = $pid;
            $data['urge_count'] = 0;
            $data['last_urge_time'] = 0;
            $data['pay_status'] = 0;
            Db::name($table)->insert($data);
            $inserted++;

            if ($urgeService->notifyNewOrder($data, $diffMinutes)) {
                Db::name($table)
                    ->where('pid', $pid)
                    ->where('order_no', $orderNo)
                    ->update([
                        'urge_count'     => 1,
                        'last_urge_time' => time(),
                    ]);
                $notified++;
            }
        }

        $processedQuery = Db::name($table)
            ->where('pid', $pid)
            ->where('pay_status', 0)
            ->where('addtime', '>=', (int)$createStartTime)
            ->where('addtime', '<=', (int)$createEndTime);

        if (!empty($activeOrderNos)) {
            $processedQuery->where('order_no', 'not in', $activeOrderNos);
        }

        $processed = $processedQuery->update(['pay_status' => 1]);

        return [
            'pid'         => $pid,
            'inserted'    => $inserted,
            'updated'     => $updated,
            'skipped'     => $skipped,
            'notified'    => $notified,
            'processed'   => $processed,
            'min_minutes' => $minMinutes,
            'total'       => count($records),
        ];
    }

    protected function getMinUrgeMinutes($pid = null)
    {
        $pid = $pid ?: $this->config['pid'];
        $row = Db::name($this->config['schedule_table'])
            ->where('pid', $pid)
            ->where('status', 'normal')
            ->order('sort asc,id asc')
            ->find();

        return (int)($row['minutes'] ?? 15);
    }
}
