<?php

namespace Im\Support;

class IdGenerator
{
    public static function msgId()
    {
        return sprintf('m%s%04d', date('YmdHis'), random_int(0, 9999));
    }

    public static function packetNo()
    {
        return sprintf('rp%s%06d', date('YmdHis'), random_int(0, 999999));
    }

    /** 私聊会话键：小uid_大uid */
    public static function privateConversationId($uidA, $uidB)
    {
        $a = (int)$uidA;
        $b = (int)$uidB;
        if ($a > $b) {
            $t = $a;
            $a = $b;
            $b = $t;
        }
        return $a . '_' . $b;
    }
}
