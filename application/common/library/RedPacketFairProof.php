<?php

namespace app\common\library;

/**
 * @deprecated 本地 SHA-256 已作废，统一走 RedPacketTronFair（波场官方哈希）
 */
class RedPacketFairProof
{
    public static function publicView(array $packet, array $records = [])
    {
        return RedPacketTronFair::publicView($packet, $records);
    }

    public static function typeLabel($packetType)
    {
        return RedPacketTronFair::typeLabel($packetType);
    }
}
