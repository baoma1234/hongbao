<?php

namespace Im\Service;

use Im\Support\CatchLog;

use Im\Support\Db;
use Im\Support\RedisClient;

class AuthService
{
    /** @var array */
    protected $cfg;

    /** token → session 缓存秒数 */
    const TOKEN_CACHE_TTL = 90;
    /** user brief 缓存秒数（余额略敏感，短一点） */
    const BRIEF_CACHE_TTL = 25;

    public function __construct(array $appCfg)
    {
        $this->cfg = $appCfg['auth'] ?? [];
    }

    /**
     * FastAdmin 存库的是 HMAC(token)，与 application/common/library/token/Driver 一致
     */
    protected function encryptToken($token)
    {
        $algo = (string)($this->cfg['hashalgo'] ?? 'ripemd160');
        $key = (string)($this->cfg['key'] ?? '');
        if ($algo === '' || $key === '') {
            return $token;
        }
        return hash_hmac($algo, $token, $key);
    }

    protected function tokenCacheKey($storedToken)
    {
        return RedisClient::key('tok:' . hash('sha256', (string)$storedToken));
    }

    /**
     * 一次查出 user_id + user brief（鉴权加速：单 SQL + Redis）
     * @return array{user_id:int,user:?array}
     */
    public function authByToken($token)
    {
        $token = trim((string)$token);
        if ($token === '') {
            return ['user_id' => 0, 'user' => null];
        }
        $stored = $this->encryptToken($token);
        $cacheKey = $this->tokenCacheKey($stored);

        try {
            $raw = RedisClient::conn()->get($cacheKey);
            if ($raw) {
                $j = json_decode((string)$raw, true);
                if (is_array($j) && (int)($j['user_id'] ?? 0) > 0) {
                    $uid = (int)$j['user_id'];
                    $user = isset($j['user']) && is_array($j['user']) ? $j['user'] : $this->userBrief($uid);
                    return ['user_id' => $uid, 'user' => $user];
                }
            }
        } catch (\Throwable $e) {
            // ignore cache miss / redis down
        }

        $tokenTable = Db::table($this->cfg['token_table'] ?? 'user_token');
        $userTable = Db::table($this->cfg['user_table'] ?? 'user');
        $row = Db::fetch(
            "SELECT t.user_id, t.expiretime,
                    u.id, u.username, u.nickname, u.mobile, u.avatar, u.money, u.score, u.status AS user_status,
                    IFNULL(a.hongbao, 0) AS balance,
                    IFNULL(a.hongbao_frozen, 0) AS hongbao_frozen
             FROM {$tokenTable} t
             LEFT JOIN {$userTable} u ON u.id = t.user_id
             LEFT JOIN " . Db::table('fans_account') . " a ON a.user_id = t.user_id
             WHERE t.token = ? LIMIT 1",
            [$stored]
        );
        if (!$row) {
            return ['user_id' => 0, 'user' => null];
        }
        $exp = (int)($row['expiretime'] ?? 0);
        if ($exp > 0 && $exp < time()) {
            return ['user_id' => 0, 'user' => null];
        }
        $status = (string)($row['user_status'] ?? '');
        if ($status !== '' && $status !== 'normal') {
            return ['user_id' => 0, 'user' => null];
        }
        $userId = (int)($row['user_id'] ?? 0);
        if ($userId <= 0 || empty($row['id'])) {
            return ['user_id' => 0, 'user' => null];
        }
        $user = [
            'id'       => (int)$row['id'],
            'username' => $row['username'] ?? '',
            'nickname' => $row['nickname'] ?? '',
            'mobile'   => $row['mobile'] ?? '',
            'avatar'   => $row['avatar'] ?? '',
            'money'    => $row['money'] ?? 0,
            'score'    => $row['score'] ?? 0,
            'status'   => $status !== '' ? $status : 'normal',
            'balance'  => round((float)($row['balance'] ?? 0), 2),
            'hongbao_frozen' => round((float)($row['hongbao_frozen'] ?? 0), 2),
        ];

        try {
            RedisClient::conn()->setex(
                $cacheKey,
                self::TOKEN_CACHE_TTL,
                json_encode(['user_id' => $userId, 'user' => $user], JSON_UNESCAPED_UNICODE)
            );
            RedisClient::conn()->setex(
                RedisClient::key('ub:' . $userId),
                self::BRIEF_CACHE_TTL,
                json_encode($user, JSON_UNESCAPED_UNICODE)
            );
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.AuthService');
        }

        return ['user_id' => $userId, 'user' => $user];
    }

    /**
     * 校验 FastAdmin 会员 token，返回 user_id 或 0
     */
    public function userIdByToken($token)
    {
        return (int)($this->authByToken($token)['user_id'] ?? 0);
    }

    public function userBrief($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return null;
        }
        try {
            $raw = RedisClient::conn()->get(RedisClient::key('ub:' . $userId));
            if ($raw) {
                $j = json_decode((string)$raw, true);
                if (is_array($j) && !empty($j['id'])) {
                    return $j;
                }
            }
            // 兼容旧 ubrief: 缓存
            $legacy = RedisClient::conn()->get(RedisClient::key('ubrief:' . $userId));
            if ($legacy) {
                $j = json_decode((string)$legacy, true);
                if (is_array($j) && !empty($j['id'])) {
                    try {
                        RedisClient::conn()->setex(
                            RedisClient::key('ub:' . $userId),
                            self::BRIEF_CACHE_TTL,
                            (string)$legacy
                        );
                    } catch (\Throwable $eCopy) {
            CatchLog::quiet($eCopy, 'Service.AuthService');
        }
                    return $j;
                }
            }
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.AuthService');
        }

        $userTable = Db::table($this->cfg['user_table'] ?? 'user');
        $row = Db::fetch(
            "SELECT u.id, u.username, u.nickname, u.mobile, u.avatar, u.money, u.score, u.status,
                    IFNULL(a.hongbao, 0) AS balance,
                    IFNULL(a.hongbao_frozen, 0) AS hongbao_frozen
             FROM {$userTable} u
             LEFT JOIN " . Db::table('fans_account') . " a ON a.user_id = u.id
             WHERE u.id = ? LIMIT 1",
            [$userId]
        );
        if ($row) {
            $row['balance'] = round((float)$row['balance'], 2);
            $row['hongbao_frozen'] = round((float)($row['hongbao_frozen'] ?? 0), 2);
            try {
                RedisClient::conn()->setex(
                    RedisClient::key('ub:' . $userId),
                    self::BRIEF_CACHE_TTL,
                    json_encode($row, JSON_UNESCAPED_UNICODE)
                );
            } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Service.AuthService');
        }
        }
        return $row;
    }

    /**
     * @param int[] $userIds
     * @return array<int, array>
     */
    public function usersBriefMap(array $userIds)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), function ($id) {
            return $id > 0;
        })));
        if (!$ids) {
            return [];
        }
        $map = [];
        $miss = [];
        try {
            $r = RedisClient::conn();
            $keys = [];
            foreach ($ids as $id) {
                $keys[] = RedisClient::key('ub:' . $id);
            }
            $vals = $r->mGet($keys);
            if (!is_array($vals)) {
                $miss = $ids;
            } else {
                foreach ($ids as $i => $id) {
                    $raw = $vals[$i] ?? false;
                    if ($raw === false || $raw === null || $raw === '') {
                        $miss[] = $id;
                        continue;
                    }
                    $j = json_decode((string)$raw, true);
                    if (is_array($j) && !empty($j['id'])) {
                        $map[$id] = $j;
                    } else {
                        $miss[] = $id;
                    }
                }
            }
        } catch (\Throwable $e) {
            $miss = $ids;
            $map = [];
        }

        if ($miss) {
            $userTable = Db::table($this->cfg['user_table'] ?? 'user');
            $in = implode(',', array_map('intval', $miss));
            $rows = Db::fetchAll(
                "SELECT id, username, nickname, mobile, avatar, status FROM {$userTable} WHERE id IN ({$in})"
            );
            try {
                $r = RedisClient::conn();
                foreach ($rows as $row) {
                    $uid = (int)$row['id'];
                    $map[$uid] = $row;
                    $r->setex(RedisClient::key('ub:' . $uid), 120, json_encode($row, JSON_UNESCAPED_UNICODE));
                }
            } catch (\Throwable $e) {
                foreach ($rows as $row) {
                    $map[(int)$row['id']] = $row;
                }
            }
        }
        return $map;
    }

    /**
     * 展示用昵称：昵称 → 用户名 → 脱敏手机号 →「群友」（不回退为 ID{uid}）
     *
     * @param array|null $u usersBriefMap 行
     * @param int        $uid
     */
    public function displayNameFromBrief($u, $uid = 0)
    {
        $uid = (int)$uid;
        if (is_array($u)) {
            $nick = trim((string)($u['nickname'] ?: $u['username'] ?: ''));
            if ($nick !== '') {
                return $nick;
            }
            if (!empty($u['mobile'])) {
                $mob = (string)$u['mobile'];
                return strlen($mob) >= 7 ? (substr($mob, 0, 3) . '****' . substr($mob, -4)) : $mob;
            }
            if (!empty($u['id'])) {
                $uid = (int)$u['id'];
            }
        }
        return '群友';
    }
}
