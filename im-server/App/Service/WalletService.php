<?php

namespace Im\Service;

use Im\Support\Db;
use Im\Support\RedisClient;
use Im\Support\CatchLog;

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

    /** @var array<int,array{bal:float,at:float}> */
    protected static $memFrozen = [];

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

    public function accountTable()
    {
        return (string)$this->cfg['account_table'];
    }

    /** 批量读可用余额（一次 IN 查询） */
    public function getBalances(array $userIds, $fresh = false)
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        $out = [];
        if (!$userIds) {
            return $out;
        }
        $miss = [];
        if (!$fresh) {
            foreach ($userIds as $uid) {
                $cached = $this->cacheGet($uid);
                if ($cached !== null) {
                    $out[$uid] = $cached;
                } else {
                    $miss[] = $uid;
                }
            }
        } else {
            $miss = $userIds;
        }
        if ($miss) {
            $field = $this->cfg['field'];
            $in = implode(',', $miss);
            $rows = Db::fetchAll(
                "SELECT user_id, `{$field}` AS bal FROM " . Db::table($this->cfg['account_table'])
                . " WHERE user_id IN ({$in})"
            );
            foreach ($rows ?: [] as $row) {
                $uid = (int)($row['user_id'] ?? 0);
                $bal = round((float)($row['bal'] ?? 0), 2);
                if ($uid <= 0) {
                    continue;
                }
                $out[$uid] = $bal;
                $this->cachePut($uid, $bal);
            }
            foreach ($miss as $uid) {
                if (!array_key_exists($uid, $out)) {
                    $out[$uid] = 0.0;
                }
            }
        }
        return $out;
    }

    /**
     * 可用红宝（不含冻结）。@param bool $fresh true=强制回库（验资不足时防误拒）
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

    /** 红包潜在赔付冻结额 */
    public function getFrozen($userId, $fresh = false)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return 0.0;
        }
        if (!$fresh) {
            $cached = $this->frozenCacheGet($userId);
            if ($cached !== null) {
                return $cached;
            }
        }
        $row = Db::fetch(
            'SELECT hongbao_frozen AS bal FROM ' . Db::table($this->cfg['account_table'])
            . ' WHERE user_id=? LIMIT 1',
            [$userId]
        );
        $bal = round((float)($row['bal'] ?? 0), 2);
        $this->frozenCachePut($userId, $bal);
        return $bal;
    }

    /**
     * @return array{hongbao:float,hongbao_frozen:float,hongbao_total:float}
     */
    public function getWalletView($userId, $fresh = false)
    {
        $avail = $this->getBalance($userId, $fresh);
        $frozen = $this->getFrozen($userId, $fresh);
        return [
            'hongbao'        => $avail,
            'hongbao_frozen' => $frozen,
            'hongbao_total'  => round($avail + $frozen, 2),
        ];
    }

    /**
     * 可用 → 冻结（领取赔付类红包时锁定潜在赔付额）
     * @return array{before:float,after:float,frozen_before:float,frozen_after:float,delta:float,ledger_id:int}
     */
    public function freeze($userId, $amount, $type = 'red_packet_freeze', $remark = '', array $meta = [])
    {
        $userId = (int)$userId;
        $amount = round((float)$amount, 2);
        if ($userId <= 0) {
            throw new \InvalidArgumentException('invalid user');
        }
        if ($amount <= 0.00001) {
            $bal = $this->getBalance($userId);
            $fr = $this->getFrozen($userId);
            return [
                'before' => $bal, 'after' => $bal, 'delta' => 0.0,
                'frozen_before' => $fr, 'frozen_after' => $fr, 'ledger_id' => 0,
            ];
        }

        $field = $this->cfg['field'];
        $now = time();
        $abs = sprintf('%.2f', $amount);
        $table = Db::table($this->cfg['account_table']);
        $affected = Db::exec(
            "UPDATE {$table} SET `{$field}`=`{$field}`-(?), hongbao_frozen=hongbao_frozen+(?), updatetime=?"
            . " WHERE user_id=? AND status='normal' AND `{$field}`>=?",
            [$abs, $abs, $now, $userId, $abs]
        );
        if ($affected <= 0) {
            error_log('[WALLET][ERROR] freeze fail user=' . $userId . ' amount=' . $amount);
            throw new \RuntimeException('insufficient balance');
        }

        $row = Db::fetch(
            "SELECT `{$field}` AS bal, hongbao_frozen, rights FROM {$table} WHERE user_id=? LIMIT 1",
            [$userId]
        );
        $after = round((float)($row['bal'] ?? 0), 2);
        $frozenAfter = round((float)($row['hongbao_frozen'] ?? 0), 2);
        $before = round($after + $amount, 2);
        $frozenBefore = round($frozenAfter - $amount, 2);

        $ledgerId = $this->insertLedger(
            $userId,
            (string)$type,
            -$amount,
            $after,
            (float)($row['rights'] ?? 0),
            (string)$remark,
            $meta,
            $now
        );
        $this->cachePut($userId, $after);
        $this->frozenCachePut($userId, $frozenAfter);

        return [
            'before'        => $before,
            'after'         => $after,
            'delta'         => -$amount,
            'frozen_before' => $frozenBefore,
            'frozen_after'  => $frozenAfter,
            'ledger_id'     => $ledgerId,
        ];
    }

    /**
     * 抢包热路径：入账 + 冻结一次 UPDATE（少一轮 UPDATE/SELECT）。
     * 语义等价于 change(+credit) 再 freeze(freezeAmt)。
     *
     * @return array{before:float,after:float,credit_after:float,frozen_before:float,frozen_after:float,delta:float,ledger_credit:int,ledger_freeze:int}
     */
    public function creditAndFreeze(
        $userId,
        $credit,
        $freezeAmt,
        $creditType,
        $creditRemark,
        $freezeType = 'red_packet_freeze',
        $freezeRemark = '',
        array $meta = []
    ) {
        $userId = (int)$userId;
        $credit = round((float)$credit, 2);
        $freezeAmt = round((float)$freezeAmt, 2);
        if ($userId <= 0 || $credit <= 0) {
            throw new \InvalidArgumentException('invalid creditAndFreeze');
        }
        if ($freezeAmt <= 0.00001) {
            $chg = $this->change($userId, $credit, $creditType, $creditRemark, $meta);
            $fr = $this->getFrozen($userId, false);
            return [
                'before'         => (float)$chg['before'],
                'after'          => (float)$chg['after'],
                'credit_after'   => (float)$chg['after'],
                'frozen_before'  => $fr,
                'frozen_after'   => $fr,
                'delta'          => $credit,
                'ledger_credit'  => (int)$chg['ledger_id'],
                'ledger_freeze'  => 0,
            ];
        }

        $field = $this->cfg['field'];
        $now = time();
        $cAbs = sprintf('%.2f', $credit);
        $fAbs = sprintf('%.2f', $freezeAmt);
        $table = Db::table($this->cfg['account_table']);
        // hongbao 先加领取额再扣冻结；条件保证冻结不超过「领取后」可用余额
        $affected = Db::exec(
            "UPDATE {$table} SET"
            . " `{$field}`=`{$field}`+(?)-(?),"
            . " hongbao_frozen=hongbao_frozen+(?),"
            . " updatetime=?"
            . " WHERE user_id=? AND status='normal' AND (`{$field}`+(?))>=?",
            [$cAbs, $fAbs, $fAbs, $now, $userId, $cAbs, $fAbs]
        );
        if ($affected <= 0) {
            // 回退分步，保留原错误语义
            $chg = $this->change($userId, $credit, $creditType, $creditRemark, $meta);
            $fr = $this->freeze($userId, $freezeAmt, $freezeType, $freezeRemark, $meta);
            return [
                'before'         => (float)$chg['before'],
                'after'          => (float)$fr['after'],
                'credit_after'   => (float)$chg['after'],
                'frozen_before'  => (float)$fr['frozen_before'],
                'frozen_after'   => (float)$fr['frozen_after'],
                'delta'          => $credit - $freezeAmt,
                'ledger_credit'  => (int)$chg['ledger_id'],
                'ledger_freeze'  => (int)$fr['ledger_id'],
            ];
        }

        $row = Db::fetch(
            "SELECT `{$field}` AS bal, hongbao_frozen, rights FROM {$table} WHERE user_id=? LIMIT 1",
            [$userId]
        );
        $after = round((float)($row['bal'] ?? 0), 2);
        $frozenAfter = round((float)($row['hongbao_frozen'] ?? 0), 2);
        $creditAfter = round($after + $freezeAmt, 2);
        $before = round($creditAfter - $credit, 2);
        $frozenBefore = round($frozenAfter - $freezeAmt, 2);
        $rights = (float)($row['rights'] ?? 0);

        $ledgerCredit = $this->insertLedger(
            $userId,
            (string)$creditType,
            $credit,
            $creditAfter,
            $rights,
            (string)$creditRemark,
            $meta,
            $now
        );
        $ledgerFreeze = $this->insertLedger(
            $userId,
            (string)$freezeType,
            -$freezeAmt,
            $after,
            $rights,
            (string)$freezeRemark,
            $meta,
            $now
        );
        $this->cachePut($userId, $after);
        $this->frozenCachePut($userId, $frozenAfter);

        return [
            'before'         => $before,
            'after'          => $after,
            'credit_after'   => $creditAfter,
            'frozen_before'  => $frozenBefore,
            'frozen_after'   => $frozenAfter,
            'delta'          => round($credit - $freezeAmt, 2),
            'ledger_credit'  => $ledgerCredit,
            'ledger_freeze'  => $ledgerFreeze,
        ];
    }

    /**
     * 冻结 → 可用（红包领完结算或过期时解冻）
     * @return array{before:float,after:float,frozen_before:float,frozen_after:float,delta:float,ledger_id:int}
     */
    public function unfreeze($userId, $amount, $type = 'red_packet_unfreeze', $remark = '', array $meta = [])
    {
        $userId = (int)$userId;
        $amount = round((float)$amount, 2);
        if ($userId <= 0) {
            throw new \InvalidArgumentException('invalid user');
        }
        if ($amount <= 0.00001) {
            $bal = $this->getBalance($userId);
            $fr = $this->getFrozen($userId);
            return [
                'before' => $bal, 'after' => $bal, 'delta' => 0.0,
                'frozen_before' => $fr, 'frozen_after' => $fr, 'ledger_id' => 0,
            ];
        }

        $field = $this->cfg['field'];
        $now = time();
        $abs = sprintf('%.2f', $amount);
        $table = Db::table($this->cfg['account_table']);
        $affected = Db::exec(
            "UPDATE {$table} SET `{$field}`=`{$field}`+(?), hongbao_frozen=GREATEST(0, hongbao_frozen-(?)), updatetime=?"
            . " WHERE user_id=? AND status='normal' AND hongbao_frozen>=?",
            [$abs, $abs, $now, $userId, $abs]
        );
        if ($affected <= 0) {
            // 冻结不足：空冻结视为幂等跳过；有余额但不够则抛错，禁止清空全部冻结误伤其它包
            $row0 = Db::fetch(
                "SELECT hongbao_frozen FROM {$table} WHERE user_id=? LIMIT 1",
                [$userId]
            );
            $have = round((float)($row0['hongbao_frozen'] ?? 0), 2);
            if ($have <= 0.00001) {
                error_log('[WALLET][WARN] unfreeze skip empty user=' . $userId . ' want=' . $amount);
                $bal = $this->getBalance($userId, true);
                return [
                    'before' => $bal, 'after' => $bal, 'delta' => 0.0,
                    'frozen_before' => 0.0, 'frozen_after' => 0.0, 'ledger_id' => 0,
                ];
            }
            // 仅允许分位误差级差额（0.01）按实际冻结解冻；更大差额拒绝，避免误释其它包
            if ($have + 0.011 < $amount) {
                error_log('[WALLET][ERROR] unfreeze short user=' . $userId
                    . ' want=' . $amount . ' have=' . $have);
                throw new \RuntimeException('unfreeze insufficient frozen');
            }
            $amount = $have;
            $abs = sprintf('%.2f', $amount);
            $affected = Db::exec(
                "UPDATE {$table} SET `{$field}`=`{$field}`+(?), hongbao_frozen=GREATEST(0, hongbao_frozen-(?)), updatetime=?"
                . " WHERE user_id=? AND status='normal' AND hongbao_frozen>=?",
                [$abs, $abs, $now, $userId, $abs]
            );
            if ($affected <= 0) {
                throw new \RuntimeException('unfreeze failed');
            }
        }

        $row = Db::fetch(
            "SELECT `{$field}` AS bal, hongbao_frozen, rights FROM {$table} WHERE user_id=? LIMIT 1",
            [$userId]
        );
        $after = round((float)($row['bal'] ?? 0), 2);
        $frozenAfter = round((float)($row['hongbao_frozen'] ?? 0), 2);
        $before = round($after - $amount, 2);
        $frozenBefore = round($frozenAfter + $amount, 2);

        $ledgerId = $this->insertLedger(
            $userId,
            (string)$type,
            $amount,
            $after,
            (float)($row['rights'] ?? 0),
            (string)$remark,
            $meta,
            $now
        );
        $this->cachePut($userId, $after);
        $this->frozenCachePut($userId, $frozenAfter);

        return [
            'before'        => $before,
            'after'         => $after,
            'delta'         => $amount,
            'frozen_before' => $frozenBefore,
            'frozen_after'  => $frozenAfter,
            'ledger_id'     => $ledgerId,
        ];
    }

    protected function insertLedger($userId, $type, $delta, $after, $rightsAfter, $remark, array $meta, $now)
    {
        $bizNo = mb_substr((string)($meta['biz_no'] ?? ''), 0, 40);
        $refType = mb_substr((string)($meta['ref_type'] ?? ''), 0, 32);
        $refId = (int)($meta['ref_id'] ?? 0);
        $field = $this->cfg['field'];
        $useHongbaoLedger = ($field === 'hongbao');
        $remark = $this->appendPacketNoToRemark((string)$remark, $meta);
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
                sprintf('%.2f', $rightsAfter),
                $useHongbaoLedger ? '0.00' : sprintf('%.2f', $after),
                $useHongbaoLedger ? sprintf('%.2f', $after) : '0.00',
                mb_substr($remark, 0, 255),
                'im_red_packet',
                $bizNo,
                $refType,
                $refId,
                0,
                $now,
            ]
        );
        return (int)Db::lastId();
    }

    /** 同 biz_no 可安全幂等跳过的入账类型（防双结算） */
    protected function isIdempotentCreditType($type)
    {
        static $types = [
            'red_packet_agent_rebate_in'  => true,
            'red_packet_dual_rebate_in'   => true,
            'red_packet_invite_rebate_in' => true,
            'red_packet_grab'             => true,
            'niuniu_win'                  => true,
            'niuniu_packet'               => true,
        ];
        return isset($types[(string)$type]);
    }

    /**
     * 红包相关流水备注统一带上红宝号，便于前后台对账检索
     */
    protected function appendPacketNoToRemark($remark, array $meta)
    {
        $remark = trim((string)$remark);
        $packetNo = trim((string)($meta['packet_no'] ?? ''));
        if ($packetNo === '' && (string)($meta['ref_type'] ?? '') === 'red_packet') {
            $packetNo = trim((string)($meta['biz_no'] ?? ''));
        }
        if ($packetNo === '') {
            return $remark;
        }
        if ($remark !== '' && (
            strpos($remark, $packetNo) !== false
            || preg_match('/红宝号\s*[:：]/u', $remark)
        )) {
            return $remark;
        }
        if ($remark === '') {
            return '红宝号:' . $packetNo;
        }
        return $remark . ' 红宝号:' . $packetNo;
    }

    /**
     * 抢包验资：缓存余额明显充足则跳过打库；不足则强制回库再判，避免短缓存误拒
     * @param bool $fresh true=强制回库（领前关键验资）
     */
    public function hasEnoughBalance($userId, $need, $fresh = false)
    {
        $need = round((float)$need, 2);
        if ($need <= 0) {
            return true;
        }
        if (!$fresh) {
            $cached = $this->cacheGet((int)$userId);
            if ($cached !== null && $cached + 0.00001 >= $need) {
                return true;
            }
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

        // 返佣等带 biz_no 的入账：同 user+type+biz_no 只入一次，防发送时/结算时双写
        $bizNo = trim((string)($meta['biz_no'] ?? ''));
        if ($delta > 0 && $bizNo !== '' && $this->isIdempotentCreditType((string)$type)) {
            $hit = Db::fetch(
                'SELECT id FROM ' . Db::table($this->cfg['ledger_table'])
                . ' WHERE user_id=? AND type=? AND biz_no=? LIMIT 1',
                [$userId, (string)$type, mb_substr($bizNo, 0, 40)]
            );
            if ($hit) {
                $bal = $this->getBalance($userId, true);
                return [
                    'before'    => $bal,
                    'after'     => $bal,
                    'delta'     => 0.0,
                    'ledger_id' => (int)($hit['id'] ?? 0),
                ];
            }
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
                'niuniu_buy',
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

        $ledgerId = $this->insertLedger(
                $userId,
                (string)$type,
            $delta,
            $after,
            (float)($row['rights'] ?? 0),
            (string)$remark,
            $meta,
            $now
        );

        // 变更后立即回写缓存，供后续抢包验资命中
        $this->cachePut($userId, $after);

        return [
            'before'    => $before,
            'after'     => $after,
            'delta'     => $delta,
            'ledger_id' => $ledgerId,
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

    protected function frozenCacheKey($userId)
    {
        return RedisClient::key('wallet:frozen:' . (int)$userId);
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
            CatchLog::quiet($e, 'wallet.cacheGet');
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
            CatchLog::quiet($e, 'wallet.cachePut');
        }
    }

    /** @return float|null */
    protected function frozenCacheGet($userId)
    {
        $userId = (int)$userId;
        $now = microtime(true);
        if (isset(self::$memFrozen[$userId]) && ($now - (float)self::$memFrozen[$userId]['at']) < 1.0) {
            return (float)self::$memFrozen[$userId]['bal'];
        }
        try {
            $raw = RedisClient::conn()->get($this->frozenCacheKey($userId));
            if ($raw !== false && $raw !== null && $raw !== '') {
                $bal = round((float)$raw, 2);
                self::$memFrozen[$userId] = ['bal' => $bal, 'at' => $now];
                return $bal;
            }
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'wallet.frozenCacheGet');
        }
        return null;
    }

    protected function frozenCachePut($userId, $bal)
    {
        $userId = (int)$userId;
        $bal = round((float)$bal, 2);
        self::$memFrozen[$userId] = ['bal' => $bal, 'at' => microtime(true)];
        try {
            RedisClient::conn()->setex($this->frozenCacheKey($userId), $this->cacheTtl(), sprintf('%.2f', $bal));
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'wallet.frozenCachePut');
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
        $userId = (int)$userId;
        $now = time();
        // 账户曾被删后重建时，旧流水仍挂在同 user_id，会导致对账 SUM≠余额；重建前清孤儿账
        try {
            Db::exec(
                'DELETE FROM ' . Db::table($this->cfg['ledger_table']) . ' WHERE user_id=?',
                [$userId]
            );
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'wallet.purgeOrphanLedger');
        }
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
