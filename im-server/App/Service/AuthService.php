<?php

namespace Im\Service;

use Im\Support\Db;
use Im\Support\RedisClient;

class AuthService
{
    /** @var array */
    protected $cfg;

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

    /**
     * 校验 FastAdmin 会员 token，返回 user_id 或 0
     */
    public function userIdByToken($token)
    {
        $token = trim((string)$token);
        if ($token === '') {
            return 0;
        }
        $stored = $this->encryptToken($token);
        $tokenTable = Db::table($this->cfg['token_table'] ?? 'user_token');
        $userTable = Db::table($this->cfg['user_table'] ?? 'user');
        $row = Db::fetch(
            "SELECT t.user_id, t.expiretime, u.status AS user_status
             FROM {$tokenTable} t
             LEFT JOIN {$userTable} u ON u.id = t.user_id
             WHERE t.token = ? LIMIT 1",
            [$stored]
        );
        if (!$row) {
            return 0;
        }
        $exp = (int)($row['expiretime'] ?? 0);
        if ($exp > 0 && $exp < time()) {
            return 0;
        }
        if (isset($row['user_status']) && $row['user_status'] !== '' && $row['user_status'] !== 'normal') {
            return 0;
        }
        return (int)$row['user_id'];
    }

    public function userBrief($userId)
    {
        $userTable = Db::table($this->cfg['user_table'] ?? 'user');
        $row = Db::fetch(
            "SELECT u.id, u.username, u.nickname, u.mobile, u.avatar, u.money, u.score, u.status,
                    IFNULL(a.balance, 0) AS balance
             FROM {$userTable} u
             LEFT JOIN " . Db::table('fans_account') . " a ON a.user_id = u.id
             WHERE u.id = ? LIMIT 1",
            [(int)$userId]
        );
        if ($row) {
            $row['balance'] = round((float)$row['balance'], 2);
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
}
