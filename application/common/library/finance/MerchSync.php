<?php

namespace app\common\library\finance;

use think\Db;

/**
 * 商户通道同步
 */
class MerchSync
{
    protected $config;
    protected $auth;

    public function __construct($pid = null, array $config = null, FinanceAuth $auth = null)
    {
        $this->config = $config ?: FinanceConfig::forPid($pid);
        $this->auth = $auth ?: new FinanceAuth($this->config);
    }

    public function sync($pid = null)
    {
        $pid = (int)($pid ?: $this->config['pid']);
        $records = $this->fetchRecords();
        $saved = $this->saveRecords($records, $pid);

        return [
            'pid'          => $pid,
            'remote_total' => count($records),
            'saved'        => $saved,
            'inserted'     => $saved['inserted'] ?? 0,
            'updated'      => $saved['updated'] ?? 0,
            'skipped'      => $saved['skipped'] ?? 0,
        ];
    }

    protected function fetchRecords()
    {
        $allRecords = [];
        $current = 1;
        $size = 200;

        do {
            $params = [
                'current'   => $current,
                'size'      => $size,
                'merchType' => 2,
            ];
            $result = $this->auth->sendAuthRequest(
                $this->config['merch_list_url'],
                json_encode($params, JSON_UNESCAPED_UNICODE),
                'merch_list'
            );

            $list = $this->extractList($result);
            $total = (int)($result['data']['total'] ?? 0);
            if (!empty($list)) {
                $allRecords = array_merge($allRecords, $list);
            }

            if (empty($list) || ($total > 0 && count($allRecords) >= $total)) {
                break;
            }
            if ($total <= 0 && count($list) < $size) {
                break;
            }
            $current++;
        } while ($current <= 50);

        return $allRecords;
    }

    protected function extractList($result)
    {
        if (!is_array($result)) {
            return [];
        }
        $data = $result['data'] ?? [];
        if (isset($data['records']) && is_array($data['records'])) {
            return $data['records'];
        }
        if (isset($data['list']) && is_array($data['list'])) {
            return $data['list'];
        }
        if (isset($data['data']) && is_array($data['data'])) {
            return $data['data'];
        }
        if (is_array($data) && !empty($data) && isset($data[0])) {
            return $data;
        }
        return [];
    }

    protected function saveRecords(array $records, $pid)
    {
        if (empty($records)) {
            return ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'total' => 0];
        }

        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $table = $this->config['merch_table'];

        foreach ($records as $row) {
            $data = $this->normalizeRow($row);
            if ($data === null) {
                $skipped++;
                continue;
            }

            $exists = Db::name($table)
                ->where('pid', $pid)
                ->where('id', $data['id'])
                ->find();
            if (!$exists) {
                $exists = Db::name($table)
                    ->where('pid', $pid)
                    ->where('merchCode', $data['merchCode'])
                    ->find();
            }
            if ($exists) {
                Db::name($table)->where('row_id', $exists['row_id'])->update([
                    'channelName' => $data['channelName'],
                    'merchCode'   => $data['merchCode'],
                    'addtime'     => $data['addtime'],
                ]);
                $updated++;
            } else {
                $data['pid'] = $pid;
                $data['chanel'] = '';
                $data['status'] = 'normal';
                Db::name($table)->insert($data);
                $inserted++;
            }
        }

        return ['inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped, 'total' => count($records)];
    }

    /**
     * listV2: id 对应订单 merchAgentId
     */
    protected function normalizeRow(array $row)
    {
        $recordId = (int)($row['id'] ?? 0);
        if ($recordId <= 0) {
            return null;
        }

        $channelName = (string)($row['merchName'] ?? '');
        if ($channelName === '') {
            $channelName = (string)($row['channelName'] ?? $row['merchCode'] ?? '商户' . $recordId);
        }

        return [
            'id'          => $recordId,
            'channelName' => $channelName,
            'merchCode'   => (string)$recordId,
            'addtime'     => (int)($row['updateTime'] ?? $row['createTime'] ?? time()),
        ];
    }
}
