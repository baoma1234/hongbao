<?php

namespace app\common\library;

use think\Db;
use think\Exception;

/**
 * 红包自动发 / 自动抢（后台任务 + CLI）
 */
class FansHubRpAuto
{
    /**
     * @param int $taskId 0=全部启用任务
     * @return array{send:int,grab:int,skip:int,errors:array}
     */
    public static function run($taskId = 0)
    {
        $stat = ['send' => 0, 'grab' => 0, 'skip' => 0, 'errors' => []];
        $q = Db::name('chat_rp_auto_task')->where('status', 'normal');
        if ((int)$taskId > 0) {
            $q->where('id', (int)$taskId);
        }
        $rows = $q->order('id', 'asc')->select();
        foreach ($rows ?: [] as $task) {
            try {
                $one = self::runOne($task);
                $stat['send'] += (int)$one['send'];
                $stat['grab'] += (int)$one['grab'];
                $stat['skip'] += (int)$one['skip'];
            } catch (\Throwable $e) {
                $stat['errors'][] = 'task#' . (int)$task['id'] . ': ' . $e->getMessage();
                self::touchError((int)$task['id'], $e->getMessage());
            }
        }
        return $stat;
    }

    protected static function runOne(array $task)
    {
        $out = ['send' => 0, 'grab' => 0, 'skip' => 0];
        $id = (int)$task['id'];
        $groupId = (int)$task['group_id'];
        if ($groupId <= 0) {
            $out['skip']++;
            return $out;
        }

        $today = date('Y-m-d');
        if ((string)($task['today_date'] ?? '') !== $today) {
            Db::name('chat_rp_auto_task')->where('id', $id)->update([
                'today_date'  => $today,
                'today_count' => 0,
                'updatetime'  => time(),
            ]);
            $task['today_count'] = 0;
            $task['today_date'] = $today;
        }

        $packetId = 0;
        if ((int)$task['auto_send'] === 1) {
            $sent = self::maybeSend($task);
            if ($sent['sent']) {
                $out['send'] = 1;
                $packetId = (int)$sent['packet_id'];
            } else {
                $out['skip']++;
            }
        }

        if ((int)$task['auto_grab'] === 1) {
            $grabbed = self::maybeGrab($task, $packetId);
            $out['grab'] += $grabbed;
        }

        return $out;
    }

    protected static function maybeSend(array $task)
    {
        $id = (int)$task['id'];
        $interval = max(5, (int)$task['interval_sec']);
        $last = (int)$task['last_send_time'];
        if ($last > 0 && (time() - $last) < $interval) {
            return ['sent' => false, 'packet_id' => 0];
        }
        $maxDay = (int)$task['max_per_day'];
        if ($maxDay > 0 && (int)$task['today_count'] >= $maxDay) {
            return ['sent' => false, 'packet_id' => 0];
        }
        $sendUid = (int)$task['send_user_id'];
        if ($sendUid <= 0) {
            throw new Exception('未配置发包用户ID');
        }
        $amount = round((float)$task['total_amount'], 2);
        $count = (int)$task['total_count'];
        if ($amount <= 0 || $count <= 0) {
            throw new Exception('金额/个数无效');
        }

        $result = FansHubImBridge::post('/agent/send_redpacket', [
            'agent_user_id' => $sendUid,
            'scope_type'    => 2,
            'group_id'      => (int)$task['group_id'],
            'packet_type'   => (int)$task['packet_type'] ?: 2,
            'total_amount'  => $amount,
            'total_count'   => $count,
            'blessing'      => (string)($task['blessing'] ?: '恭喜发财'),
            'mine_digit'    => (int)($task['mine_digit'] ?? 0),
        ]);

        $packetId = (int)($result['packet_id'] ?? ($result['packet']['id'] ?? 0));
        if ($packetId <= 0 && !empty($result['message']['extra'])) {
            $extra = $result['message']['extra'];
            if (is_string($extra)) {
                $extra = json_decode($extra, true) ?: [];
            }
            $packetId = (int)($extra['packet_id'] ?? 0);
        }

        Db::name('chat_rp_auto_task')->where('id', $id)->update([
            'last_send_time' => time(),
            'last_packet_id' => $packetId,
            'today_count'    => (int)$task['today_count'] + 1,
            'last_error'     => '',
            'updatetime'     => time(),
        ]);

        return ['sent' => true, 'packet_id' => $packetId];
    }

    protected static function maybeGrab(array $task, $preferPacketId = 0)
    {
        $uids = self::parseUserIds((string)($task['grab_user_ids'] ?? ''));
        if (!$uids) {
            return 0;
        }
        $groupId = (int)$task['group_id'];
        $packetIds = [];
        if ((int)$preferPacketId > 0) {
            $packetIds[] = (int)$preferPacketId;
        }
        // 再扫该群未抢完的包（含人工发的）
        $open = Db::name('chat_red_packets')
            ->where('group_id', $groupId)
            ->where('scope_type', 2)
            ->where('status', 1)
            ->where('remain_count', '>', 0)
            ->order('id', 'desc')
            ->limit(8)
            ->column('id');
        foreach ($open ?: [] as $pid) {
            $pid = (int)$pid;
            if ($pid > 0 && !in_array($pid, $packetIds, true)) {
                $packetIds[] = $pid;
            }
        }
        if (!$packetIds) {
            return 0;
        }

        $minMs = max(0, (int)$task['grab_delay_min_ms']);
        $maxMs = max($minMs, (int)$task['grab_delay_max_ms']);
        $done = 0;
        foreach ($packetIds as $packetId) {
            foreach ($uids as $uid) {
                // 已抢过跳过
                $exists = Db::name('chat_red_packet_records')
                    ->where(['packet_id' => $packetId, 'user_id' => $uid])
                    ->value('id');
                if ($exists) {
                    continue;
                }
                if ($maxMs > 0) {
                    $sleepMs = $minMs + ($maxMs > $minMs ? mt_rand(0, $maxMs - $minMs) : 0);
                    if ($sleepMs > 0) {
                        usleep($sleepMs * 1000);
                    }
                }
                try {
                    FansHubImBridge::post('/agent/grab_redpacket', [
                        'agent_user_id' => $uid,
                        'packet_id'     => $packetId,
                    ]);
                    $done++;
                } catch (\Throwable $e) {
                    $msg = $e->getMessage();
                    // 余额不足/已抢完等常见失败不中断整轮
                    if (stripos($msg, 'already') !== false
                        || stripos($msg, 'empty') !== false
                        || stripos($msg, 'closed') !== false
                        || stripos($msg, 'balance') !== false
                        || stripos($msg, 'grabbed') !== false
                    ) {
                        continue;
                    }
                    self::touchError((int)$task['id'], 'grab u' . $uid . ' p' . $packetId . ': ' . $msg);
                }
            }
        }
        return $done;
    }

    protected static function parseUserIds($raw)
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', (string)$raw, -1, PREG_SPLIT_NO_EMPTY) as $p) {
            $id = (int)$p;
            if ($id > 0) {
                $out[$id] = $id;
            }
        }
        return array_values($out);
    }

    protected static function touchError($taskId, $msg)
    {
        $taskId = (int)$taskId;
        if ($taskId <= 0) {
            return;
        }
        Db::name('chat_rp_auto_task')->where('id', $taskId)->update([
            'last_error' => mb_substr((string)$msg, 0, 250),
            'updatetime' => time(),
        ]);
    }
}
