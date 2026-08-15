<?php

namespace Im\Support;

/**
 * 离线用户走极光推送；在线用户仅 WebSocket（客户端本地提示音）
 */
class OfflinePush
{
    protected static $cfgLoaded = false;
    protected static $enabled = false;
    protected static $appKey = '';
    protected static $masterSecret = '';
    protected static $apnsProduction = true;

    public static function afterChatPush($type, array $data)
    {
        $type = (string)$type;
        if ($type !== 'private.message' && $type !== 'group.message') {
            return;
        }
        $msg = $data['message'] ?? null;
        if (!is_array($msg)) {
            return;
        }
        try {
            self::dispatch($type, $msg);
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Support.OfflinePush');
        }
    }

    protected static function dispatch($type, array $msg)
    {
        self::loadConfig();
        if (!self::$enabled) {
            return;
        }

        $msgId = (int)($msg['id'] ?? $msg['msg_id'] ?? 0);
        $from = (int)($msg['from_user_id'] ?? 0);
        $msgType = (int)($msg['msg_type'] ?? 1);
        // 系统提示等一般不推（避免刷屏）；红包/文本/图等推
        if ($msgType === 0 || $msgType === 9) {
            return;
        }

        if ($msgId > 0 && !self::claimOnce('jpush:msg:' . $msgId, 8)) {
            return;
        }

        $targets = [];
        if ($type === 'private.message' || (int)($msg['conversation_type'] ?? 0) === 1) {
            $to = (int)($msg['to_user_id'] ?? 0);
            if ($to > 0 && $to !== $from) {
                $targets = [$to];
            }
        } else {
            $gid = (int)($msg['group_id'] ?? 0);
            if ($gid <= 0) {
                return;
            }
            $targets = self::offlineGroupTargets($gid, $from);
        }

        if (!$targets) {
            return;
        }

        // 去掉仍在线的（WS 已送达，用 App 内提示音即可）
        $online = ConnMap::filterOnlineUserIds($targets);
        if ($online) {
            $onlineMap = array_fill_keys($online, true);
            $targets = array_values(array_filter($targets, function ($uid) use ($onlineMap) {
                return empty($onlineMap[(int)$uid]);
            }));
        }
        if (!$targets) {
            return;
        }

        $rids = self::registrationIdsForUsers($targets);
        if (!$rids) {
            return;
        }

        $title = self::pushTitle($type, $msg);
        $content = self::pushBody($msg);
        $extras = [
            'type'               => $type,
            'conversation_type'  => (int)($msg['conversation_type'] ?? ($type === 'group.message' ? 2 : 1)),
            'group_id'           => (int)($msg['group_id'] ?? 0),
            'from_user_id'       => $from,
            'to_user_id'         => (int)($msg['to_user_id'] ?? 0),
            'msg_id'             => $msgId,
            'msg_type'           => $msgType,
        ];

        // 分批（极光 registration_id ≤1000）
        foreach (array_chunk($rids, 800) as $chunk) {
            self::sendJPush($title, $content, $chunk, $extras, 'im_msg', $targets);
        }
    }

    protected static function offlineGroupTargets($groupId, $exceptUid)
    {
        $groupId = (int)$groupId;
        $exceptUid = (int)$exceptUid;
        $offline = [];
        try {
            $r = RedisClient::conn();
            $mset = RedisClient::key('g:' . $groupId . ':mset');
            $online = RedisClient::key('online');
            // 确保成员集存在
            if (!(int)$r->exists($mset) || (int)$r->sCard($mset) <= 0) {
                try {
                    (new \Im\Service\GroupService())->ensureMemberSet($groupId);
                } catch (\Throwable $e) {
                }
            }
            $diff = $r->sDiff($mset, $online);
            if (!is_array($diff)) {
                $diff = [];
            }
            $muteKey = RedisClient::key('g:' . $groupId . ':nmuteset');
            $muted = [];
            if ((int)$r->exists($muteKey) > 0) {
                $muted = $r->sMembers($muteKey);
                if (!is_array($muted)) {
                    $muted = [];
                }
            }
            $muteMap = [];
            foreach ($muted as $m) {
                $muteMap[(int)$m] = true;
            }
            foreach ($diff as $uid) {
                $uid = (int)$uid;
                if ($uid <= 0 || $uid === $exceptUid) {
                    continue;
                }
                if (!empty($muteMap[$uid])) {
                    continue;
                }
                $offline[] = $uid;
            }
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Support.OfflinePush');
            return [];
        }
        // 人数过大时截断，避免单条消息拖垮 worker（优先任意一批；后续可按活跃度排序）
        if (count($offline) > 3000) {
            $offline = array_slice($offline, 0, 3000);
        }
        return $offline;
    }

    protected static function registrationIdsForUsers(array $userIds)
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$userIds) {
            return [];
        }
        $rids = [];
        $table = Db::table('chat_push_devices');
        foreach (array_chunk($userIds, 400) as $chunk) {
            $in = implode(',', $chunk);
            $rows = Db::fetchAll(
                "SELECT registration_id FROM {$table} WHERE enabled=1 AND registration_id<>'' AND user_id IN ({$in})"
            );
            foreach ($rows as $row) {
                $rid = trim((string)($row['registration_id'] ?? ''));
                if ($rid !== '') {
                    $rids[$rid] = $rid;
                }
            }
        }
        return array_values($rids);
    }

    protected static function pushTitle($type, array $msg)
    {
        if ($type === 'group.message') {
            $gid = (int)($msg['group_id'] ?? 0);
            $name = '';
            if ($gid > 0) {
                try {
                    $row = Db::fetch(
                        'SELECT name FROM ' . Db::table('chat_groups') . ' WHERE id=? LIMIT 1',
                        [$gid]
                    );
                    $name = trim((string)($row['name'] ?? ''));
                } catch (\Throwable $e) {
                }
            }
            return $name !== '' ? $name : '群消息';
        }
        $from = (int)($msg['from_user_id'] ?? 0);
        $nick = trim((string)($msg['from_nickname'] ?? $msg['nickname'] ?? ''));
        if ($nick === '' && $from > 0) {
            try {
                $row = Db::fetch(
                    'SELECT nickname,username FROM ' . Db::table('user') . ' WHERE id=? LIMIT 1',
                    [$from]
                );
                $nick = trim((string)($row['nickname'] ?? '')) ?: trim((string)($row['username'] ?? ''));
            } catch (\Throwable $e) {
            }
        }
        return $nick !== '' ? $nick : '新消息';
    }

    protected static function pushBody(array $msg)
    {
        $msgType = (int)($msg['msg_type'] ?? 1);
        $content = trim((string)($msg['content'] ?? ''));
        if ($msgType === 2) {
            return '[红包]';
        }
        if ($msgType === 3) {
            return '[图片]';
        }
        if ($msgType === 4) {
            return '[语音]';
        }
        if ($msgType === 5) {
            return '[视频]';
        }
        if ($msgType === 8) {
            return '[转账]';
        }
        if ($content === '') {
            return '发来一条消息';
        }
        $content = preg_replace('/\s+/u', ' ', $content);
        if (function_exists('mb_substr')) {
            return mb_substr($content, 0, 80);
        }
        return substr($content, 0, 80);
    }

    protected static function sendJPush($title, $content, array $rids, array $extras, $scene, array $userIds)
    {
        $body = [
            'platform' => 'all',
            'audience' => ['registration_id' => array_values($rids)],
            'notification' => [
                'alert' => $content,
                'android' => [
                    'alert'  => $content,
                    'title'  => $title,
                    'extras' => $extras,
                ],
                'ios' => [
                    'alert'  => ['title' => $title, 'body' => $content],
                    'sound'  => 'default',
                    'badge'  => '+1',
                    'extras' => $extras,
                ],
            ],
            'options' => [
                'apns_production' => self::$apnsProduction,
                'time_to_live'    => 86400,
            ],
        ];
        $auth = base64_encode(self::$appKey . ':' . self::$masterSecret);
        $ch = curl_init('https://api.jpush.cn/v3/push');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Basic ' . $auth,
            ],
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $decoded = json_decode((string)$resp, true);
        $msgId = is_array($decoded) ? (string)($decoded['msg_id'] ?? '') : '';
        $ok = ($errno === 0 && $code >= 200 && $code < 300 && $msgId !== '');
        self::writeLog([
            'scene'       => $scene,
            'title'       => $title,
            'content'     => $content,
            'target_type' => 'users',
            'target_ids'  => array_slice(array_values($userIds), 0, 200),
            'platform'    => 'all',
            'msg_id'      => $msgId,
            'status'      => $ok ? 'ok' : 'fail',
            'result'      => $ok ? (string)$resp : substr((string)$resp, 0, 2000),
        ]);
    }

    protected static function writeLog(array $row)
    {
        try {
            Db::exec(
                'INSERT INTO ' . Db::table('chat_push_logs')
                . ' (admin_id,channel,scene,title,content,target_type,target_ids,platform,msg_id,status,result,createtime)'
                . ' VALUES (0,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    'jpush',
                    mb_substr((string)($row['scene'] ?? ''), 0, 64),
                    mb_substr((string)($row['title'] ?? ''), 0, 128),
                    mb_substr((string)($row['content'] ?? ''), 0, 512),
                    mb_substr((string)($row['target_type'] ?? ''), 0, 32),
                    json_encode($row['target_ids'] ?? [], JSON_UNESCAPED_UNICODE),
                    mb_substr((string)($row['platform'] ?? 'all'), 0, 16),
                    mb_substr((string)($row['msg_id'] ?? ''), 0, 64),
                    ($row['status'] ?? '') === 'ok' ? 'ok' : 'fail',
                    mb_substr((string)($row['result'] ?? ''), 0, 4000),
                    time(),
                ]
            );
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Support.OfflinePush');
        }
    }

    protected static function claimOnce($key, $ttl)
    {
        try {
            $r = RedisClient::conn();
            return (bool)$r->set(RedisClient::key($key), '1', ['nx', 'ex' => (int)$ttl]);
        } catch (\Throwable $e) {
            return true;
        }
    }

    protected static function loadConfig()
    {
        if (self::$cfgLoaded) {
            return;
        }
        self::$cfgLoaded = true;
        $path = dirname(__DIR__, 3) . '/application/extra/fanshub.php';
        $cfg = is_file($path) ? (include $path) : [];
        if (!is_array($cfg)) {
            $cfg = [];
        }
        self::$appKey = trim((string)($cfg['jpush_app_key'] ?? ''));
        self::$masterSecret = trim((string)($cfg['jpush_master_secret'] ?? ''));
        self::$apnsProduction = !empty($cfg['jpush_apns_production']);
        $flag = array_key_exists('jpush_enabled', $cfg) ? !empty($cfg['jpush_enabled']) : true;
        self::$enabled = $flag && self::$appKey !== '' && self::$masterSecret !== '';
    }
}
