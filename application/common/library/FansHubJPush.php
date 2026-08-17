<?php

namespace app\common\library;

use think\Db;

/**
 * 极光推送 REST v3
 */
class FansHubJPush
{
    public static function appKey()
    {
        return trim((string)FansHubService::config('jpush_app_key', ''));
    }

    public static function masterSecret()
    {
        return trim((string)FansHubService::config('jpush_master_secret', ''));
    }

    public static function enabled()
    {
        if (!FansHubService::config('jpush_enabled', true)) {
            return false;
        }
        return self::appKey() !== '' && self::masterSecret() !== '';
    }

    /** 过滤插件日志/别名误当 Registration ID */
    public static function isValidRegistrationId($rid)
    {
        $rid = trim((string)$rid);
        if ($rid === '' || strlen($rid) < 10 || strlen($rid) > 128) {
            return false;
        }
        if (preg_match('/^u\d+$/i', $rid)) {
            return false;
        }
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $rid)) {
            return false;
        }
        return true;
    }

    /**
     * 登录/App 上报 Registration ID
     */
    public static function registerDevice($userId, $registrationId, $platform = '', $enabled = true)
    {
        $userId = (int)$userId;
        $rid = trim((string)$registrationId);
        $platform = strtolower(trim((string)$platform));
        if ($platform === 'iphone' || $platform === 'ipad') {
            $platform = 'ios';
        }
        if ($platform !== 'ios' && $platform !== 'android') {
            $platform = '';
        }
        if ($userId <= 0 || !self::isValidRegistrationId($rid)) {
            throw new \InvalidArgumentException('invalid registration');
        }
        $now = time();
        $row = Db::name('chat_push_devices')->where('registration_id', $rid)->find();
        $data = [
            'user_id'          => $userId,
            'registration_id'  => $rid,
            'platform'         => $platform,
            'enabled'          => $enabled ? 1 : 0,
            'last_login_time'  => $now,
            'updatetime'       => $now,
        ];
        if ($row) {
            Db::name('chat_push_devices')->where('id', (int)$row['id'])->update($data);
            return ['id' => (int)$row['id'], 'updated' => 1];
        }
        $data['createtime'] = $now;
        $id = (int)Db::name('chat_push_devices')->insertGetId($data);
        return ['id' => $id, 'updated' => 0];
    }

    public static function setUserPushEnabled($userId, $enabled)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return;
        }
        Db::name('chat_push_devices')->where('user_id', $userId)->update([
            'enabled'    => $enabled ? 1 : 0,
            'updatetime' => time(),
        ]);
    }

    /**
     * @param int[] $userIds
     * @return string[] registration ids
     */
    public static function registrationIdsForUsers(array $userIds, $platform = 'all')
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$userIds) {
            return [];
        }
        $q = Db::name('chat_push_devices')
            ->where('user_id', 'in', $userIds)
            ->where('enabled', 1)
            ->where('registration_id', '<>', '');
        if ($platform === 'ios' || $platform === 'android') {
            $q->where('platform', $platform);
        }
        $rows = $q->column('registration_id');
        $out = [];
        foreach ($rows ?: [] as $rid) {
            $rid = (string)$rid;
            if (self::isValidRegistrationId($rid)) {
                $out[$rid] = $rid;
            }
        }
        return array_values($out);
    }

    /**
     * 有有效 Registration ID 的用户（视为已用移动端 App 登录并上报推送）
     * @param int[] $userIds
     * @return int[]
     */
    public static function userIdsWithRegistration(array $userIds, $platform = 'all')
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$userIds) {
            return [];
        }
        $q = Db::name('chat_push_devices')
            ->where('user_id', 'in', $userIds)
            ->where('enabled', 1)
            ->where('registration_id', '<>', '');
        if ($platform === 'ios' || $platform === 'android') {
            $q->where('platform', $platform);
        }
        $rows = $q->column('user_id');
        return array_values(array_unique(array_filter(array_map('intval', $rows ?: []))));
    }

    /**
     * @param array $opts title,content,user_ids|registration_ids|audience_all,platform,extras,admin_id,scene
     * @return array{ok:bool,msg_id:string,raw:mixed,error?:string}
     */
    public static function send(array $opts)
    {
        $title = trim((string)($opts['title'] ?? '红宝'));
        $content = trim((string)($opts['content'] ?? ''));
        if ($content === '') {
            return ['ok' => false, 'msg_id' => '', 'raw' => null, 'error' => 'content empty'];
        }
        if (!self::enabled()) {
            self::writeLog($opts, 'fail', '', 'jpush disabled or missing key');
            return ['ok' => false, 'msg_id' => '', 'raw' => null, 'error' => 'jpush disabled'];
        }

        $platform = strtolower((string)($opts['platform'] ?? 'all'));
        if (!in_array($platform, ['all', 'ios', 'android'], true)) {
            $platform = 'all';
        }

        $audience = null;
        $targetType = 'user';
        $targetIds = [];
        if (!empty($opts['audience_all'])) {
            $audience = 'all';
            $targetType = 'all';
            $targetIds = ['all'];
        } elseif (!empty($opts['registration_ids']) && is_array($opts['registration_ids'])) {
            $rids = array_values(array_unique(array_filter(array_map('strval', $opts['registration_ids']))));
            if (!$rids) {
                return ['ok' => false, 'msg_id' => '', 'raw' => null, 'error' => 'no registration_ids'];
            }
            // JPush 单次 registration_id 建议 ≤1000
            $rids = array_slice($rids, 0, 1000);
            $audience = ['registration_id' => $rids];
            $targetType = 'registration';
            $targetIds = $rids;
        } else {
            $uids = array_values(array_unique(array_filter(array_map('intval', (array)($opts['user_ids'] ?? [])))));
            // 仅按 Registration ID 推：无 RID = 未用移动端 App 上报，不推、不写失败日志（避免 1011 刷屏）
            $rids = self::registrationIdsForUsers($uids, $platform === 'all' ? 'all' : $platform);
            if ($rids) {
                $rids = array_slice($rids, 0, 1000);
                $audience = ['registration_id' => $rids];
                $targetType = 'users';
                $targetIds = self::userIdsWithRegistration($uids, $platform === 'all' ? 'all' : $platform);
            } else {
                return [
                    'ok'      => false,
                    'msg_id'  => '',
                    'raw'     => null,
                    'error'   => 'no registration_id',
                    'skipped' => true,
                ];
            }
        }

        $extras = is_array($opts['extras'] ?? null) ? $opts['extras'] : [];
        $body = [
            'platform' => $platform === 'all' ? 'all' : [$platform],
            'audience' => $audience,
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
                'apns_production' => !empty(FansHubService::config('jpush_apns_production', true)),
                'time_to_live'    => 86400,
            ],
        ];

        $auth = base64_encode(self::appKey() . ':' . self::masterSecret());
        $ch = curl_init('https://api.jpush.cn/v3/push');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Basic ' . $auth,
            ],
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode((string)$resp, true);
        $msgId = is_array($decoded) ? (string)($decoded['msg_id'] ?? '') : '';
        $ok = ($errno === 0 && $code >= 200 && $code < 300 && $msgId !== '');
        $error = $ok ? '' : ($err ?: (is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_UNICODE) : (string)$resp));

        self::writeLog([
            'admin_id'    => (int)($opts['admin_id'] ?? 0),
            'scene'       => (string)($opts['scene'] ?? 'push'),
            'title'       => $title,
            'content'     => $content,
            'target_type' => $targetType,
            'target_ids'  => $targetIds,
            'platform'    => $platform,
        ], $ok ? 'ok' : 'fail', $msgId, $error ?: (string)$resp);

        return ['ok' => $ok, 'msg_id' => $msgId, 'raw' => $decoded ?: $resp, 'error' => $error];
    }

    protected static function writeLog(array $opts, $status, $msgId, $result)
    {
        try {
            $targets = $opts['target_ids'] ?? [];
            Db::name('chat_push_logs')->insert([
                'admin_id'    => (int)($opts['admin_id'] ?? 0),
                'channel'     => 'jpush',
                'scene'       => mb_substr((string)($opts['scene'] ?? ''), 0, 64),
                'title'       => mb_substr((string)($opts['title'] ?? ''), 0, 128),
                'content'     => mb_substr((string)($opts['content'] ?? ''), 0, 512),
                'target_type' => mb_substr((string)($opts['target_type'] ?? ''), 0, 32),
                'target_ids'  => json_encode($targets, JSON_UNESCAPED_UNICODE),
                'platform'    => mb_substr((string)($opts['platform'] ?? 'all'), 0, 16),
                'msg_id'      => mb_substr((string)$msgId, 0, 64),
                'status'      => $status === 'ok' ? 'ok' : 'fail',
                'result'      => mb_substr((string)$result, 0, 4000),
                'createtime'  => time(),
            ]);
        } catch (\Throwable $e) {
        }
    }
}
