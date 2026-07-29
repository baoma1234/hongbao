<?php

namespace app\job;

use app\common\library\RedPacketTronFair;

/**
 * 延迟拉取波场区块哈希（由 Queue::later 调度，勿在业务请求里 sleep）
 *
 * 启动队列消费者：
 *   php think queue:work --daemon
 * 或：
 *   php think queue:listen
 */
class TronRedPacketReveal
{
    public function fire($job, $data)
    {
        $packetId = (int)($data['packet_id'] ?? 0);
        if ($packetId > 0) {
            RedPacketTronFair::processReveal($packetId, true);
        }
        $job->delete();
    }

    public function failed($data)
    {
        $packetId = (int)($data['packet_id'] ?? 0);
        if ($packetId > 0) {
            try {
                \think\Db::name('chat_red_packets')->where('id', $packetId)->where('tron_status', '<>', 2)->update([
                    'tron_status' => 3,
                    'updatetime'  => time(),
                ]);
            } catch (\Throwable $e) {
            }
        }
    }
}
