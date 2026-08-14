<?php

namespace app\job;

/**
 * 已停用：波场开奖写库仅 IM TronFair。
 * 队列里若还有历史任务，消费时直接丢弃，不再改 chat_red_packets。
 */
class TronRedPacketReveal
{
    public function fire($job, $data)
    {
        $packetId = (int)($data['packet_id'] ?? 0);
        if ($packetId > 0) {
            try {
                \think\Log::write('TronRedPacketReveal skipped (im_only) packet_id=' . $packetId, 'info');
            } catch (\Throwable $e) {
            }
        }
        $job->delete();
    }

    public function failed($data)
    {
        // 不再把包标成 FAIL，避免与 IM 开奖竞态
    }
}
