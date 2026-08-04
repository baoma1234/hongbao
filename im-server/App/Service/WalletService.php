<?php

namespace Im\Service;

use Im\Support\Db;
use Im\Support\RedisClient;

/**
 * 福利大厅钱包：fa_fans_account.hongbao（红宝）+ fa_fans_ledger
 *
 * getBalance 走进程内存 + Redis 短缓存，change() 后立即回写，
 * 降低抢包验资门槛的重复打库。
 */
class WalletService
{
    /** 余额缓存秒数（跨进程）；进程内另有更短的 mem 缓存 */
    const BALANCE_CACHE_TTL = 3;

    /** @var array */
    protected $cfg;

    /** @var array<int,array{bal:float,at:float}> */
    protected static $memBal = [];

    public function __construct(array $appCfg = [])
    {
        $rp = $appCfg['red_packet'] ?? [];
        $this->cfg = [
            'account_table'     => (string)($rp['account_table'] ?? 'fans_account'),
            'ledger_table'      => (string)($rp['ledger_table'] ?? 'fans_ledger'),
            'field'             => (string)($rp['wallet_field'] ?? 'hongbao'),
            'balance_cache_ttl' => (int)($rp['balance_cache_ttl'] ?? self::BALANCE_CACHE_TTL),
        ];
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $this->cfg['field'])) {
            $this->cfg['field'] = 'hongbao';
        }
        // 余额已并入红宝，禁止再写 balance
        if ($this->cfg['field'] === 'balance') {
            $this->cfg['field'] = 'hongbao';
        }
    }

    public function field()
    {
        return $this->cfg['field'];
    }

    /**
     * @param bool $fresh true=强制回库（验资不足时防误拒）
     */
    public function getBalance($userId, $fresh = false)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return 0.0;
        }
        if (!$fresh) {
            $cached = $this->cacheGet($userId);
            if ($cached !== null) {
                return $cached;
            }
        }
        $field = $this->cfg['field'];
        $row = Db::fetch(
            'SELECT `' . $field . '` AS bal FROM ' . Db::table($this->cfg['account_table'])
            . ' WHERE user_id=? LIMIT 1',
            [$userId]
        );
        $bal = round((float)($row['bal'] ?? 0), 2);
        $this->cachePut($userId, $bal);
        return $bal;
    }

    /**
     * 抢包验资：缓存余额明显充足则跳过打库；不足则强制回库再判，避免短缓存误拒
     */
    public function hasEnoughBalance($userId, $need)
    {
        $need = round((float)$need, 2);
        if ($need <= 0) {
            return true;
        }
        $cached = $this->cacheGet((int)$userId);
        if ($cached !== null && $cached + 0.00001 >= $need) {
            return true;
        }
        $bal = $this->getBalance($userId, true);
        return $bal + 0.00001 >= $need;
    }

    /**
     * 变更福利余额（调用方负责事务）
     * 入账/扣款用原子 UPDATE，避免 SELECT FOR UPDATE + 乐观重试的双往返。
     * @param array $meta biz_no/ref_type/ref_id
     * @return array{before:float,after:float,delta:float,ledger_id:int}
     */
    public function change($userId, $delta, $type, $remark = '', array $meta = [])
    {
        $userId = (int)$userId;
        $delta = round((float)$delta, 2);
        if ($userId <= 0) {
            throw new \InvalidArgumentException('invalid user');
        }
        if (abs($delta) < 0.00001) {
            $bal = $this->getBalance($userId);
            return ['before' => $bal, 'after' => $bal, 'delta' => 0.0, 'ledger_id' => 0];
        }

        $field = $this->cfg['field'];
        $now = time();
        $abs = sprintf('%.2f', abs($delta));
        $table = Db::table($this->cfg['account_table']);

        if ($delta > 0) {
            $affected = Db::exec(
                "UPDATE {$table} SET `{$field}`=`{$field}`+(?), updatetime=? WHERE user_id=? AND status='normal'",
                [$abs, $now, $userId]
            );
            if ($affected <= 0) {
                $this->ensureAccountForCredit($userId);
                $affected = Db::exec(
                    "UPDATE {$table} SET `{$field}`=`{$field}`+(?), updatetime=? WHERE user_id=? AND status='normal'",
                    [$abs, $now, $userId]
                );
                if ($affected <= 0) {
                    throw new \RuntimeException('account frozen');
                }
            }
        } else {
            // 发红包/中雷赔付等计入提现所需「累计流水」
            $countTurnover = in_array((string)$type, [
                'red_packet_send',
                'red_packet_mine_pay',
                'red_packet_worst_pay',
            ], true);
            if ($countTurnover) {
                $affected = Db::exec(
                    "UPDATE {$table} SET `{$field}`=`{$field}`-(?), turnover=turnover+(?), updatetime=? WHERE user_id=? AND status='normal' AND `{$field}`>=?",
                    [$abs, $abs, $now, $userId, $abs]
                );
            } else {
                $affected = Db::exec(
                    "UPDATE {$table} SET `{$field}`=`{$field}`-(?), updatetime=? WHERE user_id=? AND status='normal' AND `{$field}`>=?",
                    [$abs, $now, $userId, $abs]
                );
            }
            if ($affected <= 0) {
                $row = Db::fetch(
                    "SELECT `{$field}` AS bal, status FROM {$table} WHERE user_id=? LIMIT 1",
                    [$userId]
                );
                if (!$row) {
                    throw new \RuntimeException('insufficient balance');
                }
                if (($row['status'] ?? '') !== 'normal') {
                    throw new \RuntimeException('account frozen');
                }
                error_log('[WALLET][ERROR] insufficient balance user=' . $userId . ' before=' . ($row['bal'] ?? 0) . ' delta=' . $delta . ' type=' . $type);
                throw new \RuntimeException('insufficient balance');
            }
        }

        $row = Db::fetch(
            "SELECT `{$field}` AS bal, rights FROM {$table} WHERE user_id=? LIMIT 1",
            [$userId]
        );
        $after = round((float)($row['bal'] ?? 0), 2);
        $before = round($after - $delta, 2);

        $bizNo = mb_substr((string)($meta['biz_no'] ?? ''), 0, 40);
        $refType = mb_substr((string)($meta['ref_type'] ?? ''), 0, 32);
        $refId = (int)($meta['ref_id'] ?? 0);

        $field = $this->cfg['field'];
        $useHongbaoLedger = ($field === 'hongbao');
        Db::exec(
            'INSERT INTO ' . Db::table($this->cfg['ledger_table'])
            . ' (user_id,type,rights_change,balance_change,hongbao_change,rights_after,balance_after,hongbao_after,remark,channel,biz_no,ref_type,ref_id,admin_id,createtime)'
            . ' VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $userId,
                (string)$type,
                '0.00',
                $useHongbaoLedger ? '0.00' : sprintf('%.2f', $delta),
                $useHongbaoLedger ? sprintf('%.2f', $delta) : '0.00',
                sprintf('%.2f', (float)($row['rights'] ?? 0)),
                $useHongbaoLedger ? '0.00' : sprintf('%.2f', $after),
                $useHongbaoLedger ? sprintf('%.2f', $after) : '0.00',
                mb_substr((string)$remark, 0, 255),
                'im_red_packet',
                $bizNo,
                $refType,
                $refId,
                0,
                $now,
            ]
        );

        // 变更后立即回写缓存，供后续抢包验资命中
        $this->cachePut($userId, $after);

        return [
            'before'    => $before,
            'after'     => $after,
            'delta'     => $delta,
            'ledger_id' => (int)Db::lastId(),
        ];
    }

    protected function ensureAccountForCredit($userId)
    {
        $userId = (int)$userId;
        $row = Db::fetch(
            'SELECT user_id, status FROM ' . Db::table($this->cfg['account_table']) . ' WHERE user_id=? LIMIT 1',
            [$userId]
        );
        if ($row) {
            if (($row['status'] ?? '') !== 'normal') {
                throw new \RuntimeException('account frozen');
            }
            return;
        }
        $this->createAccount($userId);
    }

    protected function cacheKey($userId)
    {
        return RedisClient::key('wallet:bal:' . (int)$userId);
    }

    protected function cacheTtl()
    {
        return max(1, min(30, (int)($this->cfg['balance_cache_ttl'] ?? self::BALANCE_CACHE_TTL)));
    }

    /**
     * @return float|null
     */
    protected function cacheGet($userId)
    {
        $userId = (int)$userId;
        $now = microtime(true);
        if (isset(self::$memBal[$userId]) && ($now - (float)self::$memBal[$userId]['at']) < 1.0) {
            return (float)self::$memBal[$userId]['bal'];
        }
        try {
            $raw = RedisClient::conn()->get($this->cacheKey($userId));
            if ($raw !== false && $raw !== null && $raw !== '') {
                $bal = round((float)$raw, 2);
                self::$memBal[$userId] = ['bal' => $bal, 'at' => $now];
                return $bal;
            }
        } catch (\Throwable $e) {
        }
        return null;
    }

    protected function cachePut($userId, $bal)
    {
        $userId = (int)$userId;
        $bal = round((float)$bal, 2);
        self::$memBal[$userId] = ['bal' => $bal, 'at' => microtime(true)];
        try {
            RedisClient::conn()->setex($this->cacheKey($userId), $this->cacheTtl(), sprintf('%.2f', $bal));
        } catch (\Throwable $e) {
        }
    }

    protected function getAccountRow($userId, $forUpdate)
    {
        $userId = (int)$userId;
        $table = Db::table($this->cfg['account_table']);
        $sql = 'SELECT * FROM ' . $table . ' WHERE user_id=? LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $row = Db::fetch($sql, [$userId]);
        if ($row) {
            return $row;
        }
        if (!$forUpdate) {
            return [
                'user_id' => $userId,
                'balance' => 0,
                'rights'  => 0,
                'status'  => 'normal',
            ];
        }
        return $this->createAccount($userId);
    }

    protected function createAccount($userId)
    {
        $now = time();
        Db::exec(
            'INSERT INTO ' . Db::table($this->cfg['account_table'])
            . ' (id,user_id,rights,balance,main_uid,flow_stage,member_level,status,createtime,updatetime)'
            . " VALUES (?,?,0,0,'','stage1',1,'normal',?,?)",
            [$userId, $userId, $now, $now]
        );
        return Db::fetch(
            'SELECT * FROM ' . Db::table($this->cfg['account_table']) . ' WHERE user_id=? LIMIT 1 FOR UPDATE',
            [$userId]
        );
    }
}
