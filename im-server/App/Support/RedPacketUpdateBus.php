<?php

namespace Im\Support;

use Workerman\Timer;

/**
 * 红包 redpacket.update 合并推送（抢包风暴）
 *
 * 群内同一 packet_id 在短窗口内多次抢包：只保留最新状态，窗口结束推 1 次。
 * 结算 / 开奖 / 领完 / 过期：立即推送并取消待合并项。
 * 私聊（非群）：直接推，不做合并。
 */
class RedPacketUpdateBus
{
    /** @var array<int, true> 本进程已武装的 packetId（避免重复 Timer） */
    protected static $armedLocal = [];

    /**
     * @param array $event  推送 data（须含 packet_id；抢包时含 grab / by_user_id）
     * @param array $route  ['group_id'=>int] 或 ['user_ids'=>int[]]
     */
    public static function publish(array $event, array $route = [])
    {
        $packetId = (int)($event['packet_id'] ?? 0);
        if ($packetId <= 0) {
            return;
        }

        $groupId = (int)($route['group_id'] ?? 0);
        if ($groupId <= 0) {
            $groupId = (int)($event['group_id'] ?? 0);
        }
        if ($groupId <= 0) {
            $groupId = (int)(($event['grab']['packet']['group_id'] ?? 0));
        }

        $userIds = [];
        if (!empty($route['user_ids']) && is_array($route['user_ids'])) {
            foreach ($route['user_ids'] as $uid) {
                $uid = (int)$uid;
                if ($uid > 0) {
                    $userIds[$uid] = $uid;
                }
            }
        }

        if (self::shouldFlushImmediate($event)) {
            self::cancelPending($packetId);
            self::deliver($event, $groupId, array_values($userIds));
            return;
        }

        // 私聊：两端即可，无需合并
        if ($groupId <= 0) {
            self::deliver($event, 0, array_values($userIds));
            return;
        }

        self::coalesceGroup($packetId, $groupId, $event);
    }

    protected static function shouldFlushImmediate(array $event)
    {
        if (!empty($event['settled']) || !empty($event['tron_revealed'])) {
            return true;
        }
        $status = (int)($event['grab']['status'] ?? 0);
        $pStatus = (int)($event['grab']['packet']['status'] ?? ($event['packet']['status'] ?? 0));
        if (in_array($status, [2, 3, 4], true) || in_array($pStatus, [2, 3, 4], true)) {
            return true;
        }
        $remain = $event['grab']['remain_count'] ?? ($event['grab']['packet']['remain_count'] ?? null);
        if ($remain !== null && (int)$remain <= 0) {
            return true;
        }
        return false;
    }

    protected static function coalesceMs()
    {
        static $ms = null;
        if ($ms !== null) {
            return $ms;
        }
        $ms = 120;
        try {
            $cfg = require dirname(__DIR__, 2) . '/config/app.php';
            if (isset($cfg['redpacket']['update_coalesce_ms'])) {
                $ms = max(40, min(800, (int)$cfg['redpacket']['update_coalesce_ms']));
            }
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Support.RedPacketUpdateBus');
        }
        return $ms;
    }

    protected static function pendingKey($packetId)
    {
        return RedisClient::key('rp:upd:' . (int)$packetId);
    }

    protected static function armedKey($packetId)
    {
        return RedisClient::key('rp:upd:' . (int)$packetId . ':armed');
    }

    protected static function coalesceGroup($packetId, $groupId, array $event)
    {
        $packetId = (int)$packetId;
        $groupId = (int)$groupId;
        try {
            $r = RedisClient::conn();
            $pkey = self::pendingKey($packetId);
            $raw = $r->get($pkey);
            $prev = $raw ? json_decode($raw, true) : null;
            if (!is_array($prev)) {
                $prev = [];
            }

            $merged = self::mergeEvents(is_array($prev['event'] ?? null) ? $prev['event'] : [], $event);
            $payload = [
                'group_id' => $groupId,
                'event'    => $merged,
                'ts'       => time(),
            ];
            $json = Json::encode($payload);
            if ($json === '' || $json === '{"code":0,"message":"encode error"}') {
                self::deliver($event, $groupId, []);
                return;
            }
            $r->setex($pkey, 3, $json);

            $armedKey = self::armedKey($packetId);
            $armed = (bool)$r->set($armedKey, '1', ['nx', 'ex' => 3]);
            if (!$armed) {
                return;
            }

            $delay = self::coalesceMs() / 1000.0;
            if (!self::scheduleFlush($packetId, $delay)) {
                // 无 Timer（极少见）：短延迟后直接刷
                usleep((int)($delay * 1e6));
                self::flush($packetId);
            }
        } catch (\Throwable $e) {
            self::deliver($event, $groupId, []);
        }
    }

    /**
     * 合并多次抢包事件：保留最新 grab/packet 状态，并累计 recent_grabs
     */
    protected static function mergeEvents(array $prev, array $next)
    {
        $out = $next;
        $recent = [];
        if (!empty($prev['recent_grabs']) && is_array($prev['recent_grabs'])) {
            $recent = $prev['recent_grabs'];
        } elseif (!empty($prev['by_user_id'])) {
            $recent[] = [
                'by_user_id' => (int)$prev['by_user_id'],
                'amount'     => isset($prev['grab']['amount']) ? (float)$prev['grab']['amount'] : null,
            ];
        }
        if (!empty($next['by_user_id'])) {
            $recent[] = [
                'by_user_id' => (int)$next['by_user_id'],
                'amount'     => isset($next['grab']['amount']) ? (float)$next['grab']['amount'] : null,
            ];
        }
        // 去重保序（同用户多次已抢忽略）
        $seen = [];
        $uniq = [];
        foreach ($recent as $row) {
            $uid = (int)($row['by_user_id'] ?? 0);
            if ($uid <= 0 || isset($seen[$uid])) {
                continue;
            }
            $seen[$uid] = true;
            $uniq[] = $row;
        }
        if (count($uniq) > 40) {
            $uniq = array_slice($uniq, -40);
        }
        if ($uniq) {
            $out['recent_grabs'] = $uniq;
            $out['coalesced'] = count($uniq);
        }
        return $out;
    }

    protected static function scheduleFlush($packetId, $delaySec)
    {
        $packetId = (int)$packetId;
        if (isset(self::$armedLocal[$packetId])) {
            return true;
        }
        try {
            if (!class_exists(Timer::class)) {
                return false;
            }
            self::$armedLocal[$packetId] = true;
            Timer::add($delaySec, function () use ($packetId) {
                unset(self::$armedLocal[$packetId]);
                self::flush($packetId);
            }, [], false);
            return true;
        } catch (\Throwable $e) {
            unset(self::$armedLocal[$packetId]);
            return false;
        }
    }

    public static function flush($packetId)
    {
        $packetId = (int)$packetId;
        if ($packetId <= 0) {
            return;
        }
        unset(self::$armedLocal[$packetId]);
        try {
            $r = RedisClient::conn();
            $pkey = self::pendingKey($packetId);
            $raw = $r->get($pkey);
            $r->del($pkey, self::armedKey($packetId));
            if (!$raw) {
                return;
            }
            $payload = json_decode($raw, true);
            if (!is_array($payload) || empty($payload['event']) || !is_array($payload['event'])) {
                return;
            }
            $groupId = (int)($payload['group_id'] ?? 0);
            self::deliver($payload['event'], $groupId, []);
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Support.RedPacketUpdateBus');
        }
    }

    protected static function cancelPending($packetId)
    {
        $packetId = (int)$packetId;
        unset(self::$armedLocal[$packetId]);
        try {
            $r = RedisClient::conn();
            $r->del(self::pendingKey($packetId), self::armedKey($packetId));
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Support.RedPacketUpdateBus');
        }
    }

    protected static function deliver(array $event, $groupId, array $userIds)
    {
        $groupId = (int)$groupId;
        if ($groupId > 0) {
            PushBus::toGroup($groupId, 'redpacket.update', $event);
            return;
        }
        if ($userIds) {
            PushBus::toUsers($userIds, 'redpacket.update', $event);
            return;
        }
        $from = (int)($event['grab']['packet']['from_user_id'] ?? ($event['packet']['from_user_id'] ?? 0));
        $to = (int)($event['grab']['packet']['to_user_id'] ?? ($event['packet']['to_user_id'] ?? 0));
        $uids = array_values(array_unique(array_filter([$from, $to])));
        if ($uids) {
            PushBus::toUsers($uids, 'redpacket.update', $event);
        }
    }
}
