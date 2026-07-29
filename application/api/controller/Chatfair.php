<?php

namespace app\api\controller;

use app\common\controller\Api;
use app\common\library\RedPacketTronFair;
use think\Db;

/**
 * 红包公平性验证（波场官方区块哈希）
 */
class Chatfair extends Api
{
    protected $noNeedLogin = ['fair'];
    protected $noNeedRight = '*';

    /**
     * GET /api/chatfair/fair?packet_no=RP...
     */
    public function fair()
    {
        $packetNo = trim((string)$this->request->param('packet_no', ''));
        if ($packetNo === '') {
            $this->error('请提供红包单号 packet_no');
        }
        $cached = RedPacketTronFair::cacheGet($packetNo);
        if (is_array($cached) && !empty($cached['revealed']) && !empty($cached['block_id'])) {
            $this->success('ok', $cached);
        }
        $packet = Db::name('chat_red_packets')->where('packet_no', $packetNo)->find();
        if (!$packet && $packetNo !== strtolower($packetNo)) {
            $packet = Db::name('chat_red_packets')->where('packet_no', strtolower($packetNo))->find();
        }
        if (!$packet) {
            $this->error('红包不存在');
        }
        if (!in_array((int)$packet['packet_type'], [2, 3], true)) {
            $this->error('该红包玩法不支持公平性验证');
        }
        $tronStatus = (int)($packet['tron_status'] ?? 0);
        $finished = in_array((int)$packet['status'], [2, 3, 5], true) || (int)($packet['remain_count'] ?? 1) <= 0;
        if ($finished && $tronStatus !== 2) {
            try {
                $r = RedPacketTronFair::processReveal((int)$packet['id'], true);
                if (!empty($r['ok']) && !empty($r['data']) && !empty($r['data']['revealed'])) {
                    $this->success('ok', $r['data']);
                }
                $packet = Db::name('chat_red_packets')->where('id', (int)$packet['id'])->find() ?: $packet;
                $tronStatus = (int)($packet['tron_status'] ?? 0);
            } catch (\Throwable $e) {
            }
        }
        $hasTron = trim((string)($packet['tron_block_id'] ?? '')) !== '' || (int)($packet['tron_block_num'] ?? 0) > 0;
        if (!$hasTron && trim((string)($packet['fair_hash'] ?? '')) === '' && $tronStatus === 0 && !$finished) {
            $this->error('该红包暂无波场哈希（未开奖）');
        }
        $records = [];
        if ($tronStatus === 2 || (int)($packet['fair_revealed_at'] ?? 0) > 0) {
            $records = Db::name('chat_red_packet_records')
                ->where('packet_id', (int)$packet['id'])
                ->order('id', 'asc')
                ->field('user_id,amount,amount_cent,tail_digit,is_best,is_worst,is_mine_hit,createtime')
                ->select();
        }
        $view = RedPacketTronFair::publicView($packet, $records ?: []);
        if (!empty($view['revealed'])) {
            RedPacketTronFair::cachePut($view);
        }
        $this->success('ok', $view);
    }
}
