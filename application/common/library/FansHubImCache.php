<?php

namespace app\common\library;

/**
 * Bust IM WalletService Redis balance cache after HTTP-side hongbao writes.
 * IM uses redis db + prefix from .env [redis] (default db=2, prefix=im:).
 */
class FansHubImCache
{
    /** @var \Redis|null */
    protected static $redis;

    public static function bustWallet($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return;
        }
        try {
            $r = self::conn();
            if (!$r) {
                return;
            }
            $prefix = self::prefix();
            $r->del($prefix . 'wallet:bal:' . $userId);
            $r->del($prefix . 'wallet:frozen:' . $userId);
        } catch (\Throwable $e) {
            try {
                \think\Log::write('FansHubImCache bust fail uid=' . $userId . ' ' . $e->getMessage(), 'warning');
            } catch (\Throwable $ignore) {
            }
        }
    }

    /** 首充标记写入后刷新 IM 缓存（设为已充值） */
    public static function markHasRecharged($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return;
        }
        try {
            $r = self::conn();
            if (!$r) {
                return;
            }
            $r->setex(self::prefix() . 'has_recharged:' . $userId, 86400 * 7, '1');
        } catch (\Throwable $e) {
        }
    }

    /**
     * 实时在线真实用户（WebSocket Redis online 集合，排除 is_bot=1）
     *
     * @param array{page?:int,limit?:int} $opts
     * @return array{total:int,list:array,raw_online:int,bot_online:int}
     */
    public static function realtimeOnlineReal(array $opts = [])
    {
        $page = max(1, (int)($opts['page'] ?? 1));
        $limit = min(200, max(1, (int)($opts['limit'] ?? 50)));
        $empty = ['total' => 0, 'list' => [], 'raw_online' => 0, 'bot_online' => 0];
        try {
            $r = self::conn();
            if (!$r) {
                return $empty;
            }
            $raw = $r->sMembers(self::prefix() . 'online');
            if (!is_array($raw) || !$raw) {
                return $empty;
            }
            $ids = [];
            foreach ($raw as $v) {
                $id = (int)$v;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
            $ids = array_values($ids);
            $rawOnline = count($ids);
            if (!$ids) {
                return $empty;
            }

            $botIds = [];
            foreach (array_chunk($ids, 500) as $chunk) {
                $rows = \think\Db::name('fans_account')
                    ->where('user_id', 'in', $chunk)
                    ->where('is_bot', 1)
                    ->column('user_id');
                foreach ($rows ?: [] as $bid) {
                    $botIds[(int)$bid] = 1;
                }
            }
            $realIds = [];
            foreach ($ids as $id) {
                if (!isset($botIds[$id])) {
                    $realIds[] = $id;
                }
            }
            // 再排除库中已不存在的会员
            $existMap = [];
            foreach (array_chunk($realIds, 500) as $chunk) {
                $rows = \think\Db::name('user')
                    ->where('id', 'in', $chunk)
                    ->where('status', 'normal')
                    ->column('id');
                foreach ($rows ?: [] as $uid) {
                    $existMap[(int)$uid] = 1;
                }
            }
            $realIds = array_values(array_filter($realIds, function ($id) use ($existMap) {
                return isset($existMap[$id]);
            }));
            sort($realIds);
            $total = count($realIds);
            $offset = ($page - 1) * $limit;
            $pageIds = array_slice($realIds, $offset, $limit);
            $list = [];
            if ($pageIds) {
                $users = \think\Db::name('user')
                    ->where('id', 'in', $pageIds)
                    ->field('id,nickname,mobile,avatar,logintime,status')
                    ->select();
                $uMap = [];
                foreach ($users ?: [] as $u) {
                    $uMap[(int)$u['id']] = $u;
                }
                foreach ($pageIds as $uid) {
                    $u = $uMap[$uid] ?? null;
                    if (!$u) {
                        continue;
                    }
                    $mobile = (string)($u['mobile'] ?? '');
                    $digits = preg_replace('/\D+/', '', $mobile);
                    $mask = $digits;
                    if (strlen($digits) >= 7) {
                        $mask = substr($digits, 0, 3) . '****' . substr($digits, -4);
                    }
                    $avatar = '';
                    if (function_exists('normalize_user_avatar')) {
                        $avatar = (string)normalize_user_avatar($u['avatar'] ?? '', true);
                    } else {
                        $avatar = (string)($u['avatar'] ?? '');
                    }
                    $list[] = [
                        'user_id'   => $uid,
                        'nickname'  => (string)($u['nickname'] ?: ('UID' . $uid)),
                        'mobile'    => $mask,
                        'avatar'    => $avatar,
                        'logintime' => (int)($u['logintime'] ?? 0),
                    ];
                }
            }
            return [
                'total'      => $total,
                'list'       => $list,
                'raw_online' => $rawOnline,
                'bot_online' => count($botIds),
            ];
        } catch (\Throwable $e) {
            return $empty;
        }
    }

    /** @return int 实时在线真实人数 */
    public static function realtimeOnlineRealCount()
    {
        $data = self::realtimeOnlineReal(['page' => 1, 'limit' => 1]);
        return (int)($data['total'] ?? 0);
    }

    /** 绑定/改绑邀请人后清 IM 侧 inviter 缓存 */
    public static function bustInviter($inviteeUserId)
    {
        $inviteeUserId = (int)$inviteeUserId;
        if ($inviteeUserId <= 0) {
            return;
        }
        try {
            $r = self::conn();
            if (!$r) {
                return;
            }
            $r->del(self::prefix() . 'inviter:' . $inviteeUserId);
        } catch (\Throwable $e) {
        }
    }

    protected static function prefix()
    {
        $cfg = self::redisCfg();
        return (string)($cfg['prefix'] ?? 'im:');
    }

    protected static function redisCfg()
    {
        static $cfg;
        if (is_array($cfg)) {
            return $cfg;
        }
        $cfg = [
            'host'     => '127.0.0.1',
            'port'     => 6379,
            'password' => '',
            'db'       => 2,
            'prefix'   => 'im:',
        ];
        $env = dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . '.env';
        if (is_file($env)) {
            $ini = @parse_ini_file($env, true);
            if (is_array($ini) && !empty($ini['redis'])) {
                $r = $ini['redis'];
                $cfg['host'] = $r['host'] ?? $cfg['host'];
                $cfg['port'] = (int)($r['port'] ?? $cfg['port']);
                $cfg['password'] = $r['password'] ?? $cfg['password'];
                $cfg['db'] = (int)($r['select'] ?? $r['db'] ?? $cfg['db']);
                $cfg['prefix'] = $r['prefix'] ?? $cfg['prefix'];
            }
        }
        return $cfg;
    }

    /** @return \Redis|null */
    protected static function conn()
    {
        if (self::$redis instanceof \Redis) {
            return self::$redis;
        }
        if (!class_exists('Redis')) {
            return null;
        }
        $c = self::redisCfg();
        $r = new \Redis();
        if (!$r->connect($c['host'], (int)$c['port'], 1.5)) {
            return null;
        }
        if ($c['password'] !== '' && $c['password'] !== null) {
            $r->auth($c['password']);
        }
        $r->select((int)$c['db']);
        self::$redis = $r;
        return self::$redis;
    }
}
