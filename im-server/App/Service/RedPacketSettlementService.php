<?php

namespace Im\Service;

use Im\Support\Db;
use Im\Support\RedisClient;

/**
 * 红包结算服务
 *
 * 抢完后在同一 MySQL 事务内完成：
 * 1) 埋雷/手气赔付（按 total_amount 全额）
 * 2) 平台抽水：发时已记 platform_fee>0 则不再扣；否则兼容旧包在此扣
 * 3) 群主/推荐返佣：发时已分账则跳过；否则兼容旧包从平台户支出
 * 任一步失败（含余额不足）→ 全部回滚并抛异常。
 */
class RedPacketSettlementService
{
    /** @var WalletService */
    protected $wallet;

    /** @var array */
    protected $cfg;

    public function __construct(WalletService $wallet, array $appCfg = [])
    {
        $this->wallet = $wallet;
        $this->cfg = $appCfg['red_packet'] ?? [];
    }

    /**
     * 抢完最后一个包后调用。幂等：status=5 或 Redis 锁占用则跳过。
     *
     * @return array{settled:bool,compensate_users:int[],platform_fee:float,agent_rebate:float}
     * @throws \Throwable 事务任一步失败时抛出（调用方可见）
     */
    public function settleAfterFinished($packetId)
    {
        $packetId = (int)$packetId;
        $result = [
            'settled'           => false,
            'compensate_users'  => [],
            'platform_fee'      => 0.0,
            'agent_rebate'      => 0.0,
        ];
        if ($packetId <= 0) {
            return $result;
        }

        $lockKey = RedisClient::key('rp:' . $packetId . ':settle_lock');
        $gotLock = false;
        try {
            $r = RedisClient::conn();
            if (!$r->setnx($lockKey, (string)time())) {
                error_log('[RP_SETTLE] skip locked packet_id=' . $packetId);
                return $result;
            }
            $r->expire($lockKey, 60);
            $gotLock = true;
        } catch (\Throwable $e) {
            error_log('[RP_SETTLE] redis lock fail packet_id=' . $packetId . ' err=' . $e->getMessage());
        }

        Db::begin();
        try {
            $packet = Db::fetch(
                'SELECT * FROM ' . Db::table('chat_red_packets') . ' WHERE id=? FOR UPDATE',
                [$packetId]
            );
            if (!$packet) {
                Db::rollBack();
                $this->releaseLock($lockKey, $gotLock);
                return $result;
            }

            // 已结算 / 过期退回 / 关闭
            if (in_array((int)$packet['status'], [3, 4, 5], true)) {
                Db::rollBack();
                error_log('[RP_SETTLE] already closed packet_id=' . $packetId . ' status=' . $packet['status']);
                return $result;
            }
            if ((int)$packet['remain_count'] > 0) {
                Db::rollBack();
                error_log('[RP_SETTLE] not finished packet_id=' . $packetId . ' remain=' . $packet['remain_count']);
                $this->releaseLock($lockKey, $gotLock);
                return $result;
            }

            $packetType = (int)$packet['packet_type'];
            $totalAmount = round((float)$packet['total_amount'], 2);
            $packetNo = (string)$packet['packet_no'];
            $fromUserId = (int)$packet['from_user_id'];
            $scopeType = (int)($packet['scope_type'] ?? 0);
            $isPrivate = ($scopeType === 1);
            $totalCount = (int)($packet['total_count'] ?? 0);
            $compensateAmount = $totalAmount;
            $mineDigit = max(0, min(9, (int)($packet['mine_digit'] ?? 0)));
            if ($packetType === 3 && !$isPrivate) {
                // 领取前已要求 tron 就绪；赔付按金额尾数判定（可已在领取时扣过）
                if ((int)($packet['tron_status'] ?? 0) !== 2 || trim((string)($packet['tron_block_id'] ?? '')) === '') {
                    Db::rollBack();
                    error_log('[RP_SETTLE] mine wait hash packet_id=' . $packetId);
                    $this->releaseLock($lockKey, $gotLock);
                    return $result;
                }
                $compensateAmount = $this->mineCompensateAmount($totalAmount, $totalCount);
            }
            $now = time();
            $bizMeta = ['biz_no' => $packetNo, 'ref_type' => 'red_packet', 'ref_id' => $packetId];

            // 埋雷：金额尾数=手填雷号中雷（领取时已即时扣款的记录 compensate_status=2）

            $records = Db::fetchAll(
                'SELECT * FROM ' . Db::table('chat_red_packet_records') . ' WHERE packet_id=? FOR UPDATE',
                [$packetId]
            );
            usort($records, function ($a, $b) {
                $cmp = ((float)$a['amount'] <=> (float)$b['amount']);
                return $cmp !== 0 ? $cmp : ((int)$a['id'] <=> (int)$b['id']);
            });

            // ---------- 1) 标记最佳 / 中雷 / 手气最差 ----------
            $best = null;
            foreach ($records as $row) {
                if ($best === null || (float)$row['amount'] > (float)$best['amount']) {
                    $best = $row;
                }
            }
            if ($best) {
                Db::exec('UPDATE ' . Db::table('chat_red_packet_records') . ' SET is_best=0 WHERE packet_id=?', [$packetId]);
                Db::exec('UPDATE ' . Db::table('chat_red_packet_records') . ' SET is_best=1 WHERE id=?', [(int)$best['id']]);
            }

            /** @var array<int,string> $losers record_id => mine|worst */
            $losers = [];
            // 私聊红包：无最差/中雷赔付、不抽水、不返点
            if (!$isPrivate && ($packetType === 2 || $packetType === 5) && $records) {
                $worst = $records[0];
                foreach ($records as $row) {
                    $amt = (float)$row['amount'];
                    $wAmt = (float)$worst['amount'];
                    if ($amt < $wAmt - 0.00001) {
                        $worst = $row;
                        continue;
                    }
                    // 金额相同：拼手气取领取时间最晚者赔付；接龙保持先出现的最少者（续发）
                    if (abs($amt - $wAmt) <= 0.00001 && $packetType === 2) {
                        $t = (int)($row['createtime'] ?? 0);
                        $wt = (int)($worst['createtime'] ?? 0);
                        if ($t > $wt || ($t === $wt && (int)$row['id'] > (int)$worst['id'])) {
                            $worst = $row;
                        }
                    }
                }
                Db::exec('UPDATE ' . Db::table('chat_red_packet_records') . ' SET is_worst=0 WHERE packet_id=?', [$packetId]);
                // 拼手气：发包人自己领到最少 → 标记最差但不赔付
                $worstUid = (int)($worst['user_id'] ?? 0);
                $senderIsWorst = ($packetType === 2 && $worstUid === $fromUserId);
                if ($senderIsWorst) {
                    Db::exec(
                        'UPDATE ' . Db::table('chat_red_packet_records')
                        . ' SET is_worst=1, need_compensate=0, compensate_amount=0, compensate_status=0 WHERE id=?',
                        [(int)$worst['id']]
                    );
                    error_log('[RP_SETTLE] lucky sender is worst, skip compensate packet_id=' . $packetId . ' uid=' . $worstUid);
                } else {
                    Db::exec(
                        'UPDATE ' . Db::table('chat_red_packet_records')
                        . ' SET is_worst=1, need_compensate=1, compensate_amount=?, compensate_status=1 WHERE id=?',
                        [sprintf('%.2f', $compensateAmount), (int)$worst['id']]
                    );
                    $losers[(int)$worst['id']] = 'worst';
                }
            } elseif (!$isPrivate && $packetType === 3) {
                foreach ($records as $row) {
                    $cent = (int)($row['amount_cent'] ?? round((float)$row['amount'] * 100));
                    $tail = (int)($row['tail_digit'] ?? ($cent % 10));
                    if ($tail !== $mineDigit) {
                        continue;
                    }
                    // 领取时已即时扣款：仅汇总，不再重复划转
                    if ((int)($row['compensate_status'] ?? 0) === 2) {
                        continue;
                    }
                    Db::exec(
                        'UPDATE ' . Db::table('chat_red_packet_records')
                        . ' SET is_mine_hit=1, need_compensate=1, compensate_amount=?, compensate_status=1 WHERE id=?',
                        [sprintf('%.2f', $compensateAmount), (int)$row['id']]
                    );
                    $losers[(int)$row['id']] = 'mine';
                }
            }

            // ---------- 1.5) 先解冻所有领取人的潜在赔付锁定，再执行实际赔付扣款 ----------
            foreach ($records as $row) {
                if ((int)($row['freeze_status'] ?? 0) !== 1) {
                    continue;
                }
                // 已即时中雷赔付的记录不应再有冻结；兼容旧数据仍解冻
                $freezeAmt = round((float)($row['frozen_amount'] ?? 0), 2);
                $freezeUid = (int)($row['user_id'] ?? 0);
                $freezeRid = (int)($row['id'] ?? 0);
                if ($freezeUid > 0 && $freezeAmt > 0.00001) {
                    $this->wallet->unfreeze(
                        $freezeUid,
                        $freezeAmt,
                        'red_packet_unfreeze',
                        '红包潜在赔付解冻 ' . $packetNo,
                        $bizMeta
                    );
                }
                if ($freezeRid > 0) {
                    Db::exec(
                        'UPDATE ' . Db::table('chat_red_packet_records')
                        . ' SET freeze_status=2 WHERE id=?',
                        [$freezeRid]
                    );
                }
            }

            // ---------- 2) 赔付：中雷 → 扣赔付金给发包方；拼手气最差 → 仅标记，续发时由最差用户直接发包（流水记在其名下）----------
            $compensateUsers = [];
            $compensateTotal = 0.0;
            // 埋雷：汇总领取时已扣的中雷金额
            if (!$isPrivate && $packetType === 3) {
                foreach ($records as $row) {
                    if ((int)($row['compensate_status'] ?? 0) === 2 && (
                        (int)($row['is_mine_hit'] ?? 0) === 1
                        || (int)($row['tail_digit'] ?? -1) === $mineDigit
                    )) {
                        $compensateUsers[] = (int)$row['user_id'];
                        $paidAmt = round((float)($row['compensate_amount'] ?? $compensateAmount), 2);
                        $compensateTotal = round($compensateTotal + $paidAmt, 2);
                    }
                }
            }
            foreach ($losers as $recordId => $reason) {
                $rec = null;
                foreach ($records as $row) {
                    if ((int)$row['id'] === (int)$recordId) {
                        $rec = $row;
                        break;
                    }
                }
                if (!$rec) {
                    throw new \RuntimeException('compensate record missing id=' . $recordId);
                }
                $payerId = (int)$rec['user_id'];

                // 接龙最差：结算不划转，续发时由最差用户直接发包（流水记在其名下）
                // 拼手气最差：立刻赔付整包金额给发包人
                if ($reason === 'worst' && $packetType === 5) {
                    $compensateUsers[] = $payerId;
                    $compensateTotal = round($compensateTotal + $compensateAmount, 2);
                    error_log('[RP_SETTLE] relay worst marked (defer pay via next send) packet_id=' . $packetId . ' payer=' . $payerId . ' amount=' . $compensateAmount);
                    continue;
                }

                // 埋雷已在领取时扣过则跳过
                if ($reason === 'mine' && (int)($rec['compensate_status'] ?? 0) === 2) {
                    continue;
                }

                $payType = $reason === 'mine' ? 'red_packet_mine_pay' : 'red_packet_worst_pay';
                $remarkPay = ($reason === 'mine' ? '中雷赔付 ' : '手气最差赔付 ') . $packetNo;
                $remarkIn = ($reason === 'mine' ? '收到中雷赔付 ' : '收到手气最差赔付 ') . $packetNo;

                $out = $this->wallet->change($payerId, -$compensateAmount, $payType, $remarkPay, $bizMeta);
                $ledgerOutId = (int)($out['ledger_id'] ?? 0);
                $this->wallet->change($fromUserId, $compensateAmount, 'red_packet_compensate_in', $remarkIn, $bizMeta);

                Db::exec(
                    'UPDATE ' . Db::table('chat_red_packet_records')
                    . ' SET compensate_status=2, compensate_ledger_id=? WHERE id=?',
                    [$ledgerOutId, $recordId]
                );
                $this->insertSettlement(
                    $packetId, $packetNo, 'compensate', $payerId, $fromUserId,
                    $compensateAmount, $ledgerOutId, 1, $remarkPay
                );
                $compensateUsers[] = $payerId;
                $compensateTotal = round($compensateTotal + $compensateAmount, 2);
                error_log('[RP_SETTLE] compensate ok packet_id=' . $packetId . ' payer=' . $payerId . ' amount=' . $compensateAmount . ' reason=' . $reason);
            }

            // ---------- 3) 平台抽水：发时已扣则不再扣发包人；仍补结算流水 ----------
            // 私聊：强制 0 手续费 / 0 返点（防止旧包 rate=0 被兜底成 3%）
            $feeRate = 0.0;
            $platformFee = 0.0;
            $platformUserId = 0;
            $agentUserId = 0;
            $agentRate = 0.0;
            $agentRebate = 0.0;
            if (!$isPrivate) {
            $feeRate = round((float)($packet['platform_fee_rate'] ?? 0), 4);
            if ($feeRate <= 0) {
                if ($packetType === 3) {
                    $feeRate = round((float)($this->cfg['mine_platform_fee_rate']
                        ?? $this->cfg['platform_fee_rate'] ?? 0.03), 4);
                } elseif ($packetType === 5) {
                    $feeRate = round((float)($this->cfg['relay_platform_fee_rate']
                        ?? $this->cfg['platform_fee_rate'] ?? 0.03), 4);
                } else {
                    $feeRate = round((float)($this->cfg['platform_fee_rate'] ?? 0.03), 4);
                }
            }
            if ($feeRate <= 0) {
                $feeRate = 0.03;
            }
            $platformUserId = (int)($this->cfg['platform_user_id'] ?? 0);
            if ($packetType === 3) {
                $minePlatformUid = (int)($this->cfg['mine_platform_user_id'] ?? 0);
                if ($minePlatformUid > 0) {
                    $platformUserId = $minePlatformUid;
                }
            } elseif ($packetType === 5) {
                $relayPlatformUid = (int)($this->cfg['relay_platform_user_id'] ?? 0);
                if ($relayPlatformUid > 0) {
                    $platformUserId = $relayPlatformUid;
                }
            }
            $prepaidFee = round((float)($packet['platform_fee'] ?? 0), 2);
            $platformFee = $prepaidFee > 0
                ? $prepaidFee
                : round($totalAmount * $feeRate, 2);

            if ($platformFee > 0) {
                if ($platformUserId <= 0) {
                    throw new \RuntimeException('platform_user_id not configured');
                }
                $existFee = Db::fetch(
                    'SELECT id FROM ' . Db::table('chat_red_packet_settlements')
                    . ' WHERE packet_id=? AND settle_type=? LIMIT 1',
                    [$packetId, 'platform_fee']
                );
                if ($prepaidFee > 0) {
                    // 发时已入平台账户，结算不再从发包人扣
                    if (!$existFee) {
                        $this->insertSettlement(
                            $packetId, $packetNo, 'platform_fee', $fromUserId, $platformUserId,
                            $platformFee, 0, 1,
                            '平台抽水(发时已扣) ' . ($feeRate * 100) . '%'
                        );
                    }
                } else {
                    // 兼容旧包：结算时再扣
                    $feeOut = $this->wallet->change(
                        $fromUserId,
                        -$platformFee,
                        'red_packet_fee',
                        '红包平台手续费 ' . $packetNo,
                        $bizMeta
                    );
                    $this->wallet->change(
                        $platformUserId,
                        $platformFee,
                        'red_packet_fee_in',
                        '收到红包手续费 ' . $packetNo,
                        $bizMeta
                    );
                    if (!$existFee) {
                        $this->insertSettlement(
                            $packetId, $packetNo, 'platform_fee', $fromUserId, $platformUserId,
                            $platformFee, (int)($feeOut['ledger_id'] ?? 0), 1,
                            '平台抽水 ' . ($feeRate * 100) . '%'
                        );
                    }
                }
            }

            // ---------- 4) 代理返点：发时已分账则跳过，避免重复打款 ----------
            $agentUserId = (int)($packet['agent_user_id'] ?? 0);
            $agentRate = round((float)($packet['agent_rebate_rate'] ?? 0), 4);
            $agentRebate = round((float)($packet['agent_rebate_amount'] ?? 0), 2);
            $prepaidRebate = Db::fetch(
                'SELECT id, settle_type, amount FROM ' . Db::table('chat_red_packet_settlements')
                . " WHERE packet_id=? AND settle_type IN ('agent_rebate','dual_rebate','invite_rebate') AND status=1 LIMIT 1",
                [$packetId]
            );
            if ($prepaidRebate) {
                // 发时已完成四方分账；此处只汇总金额用于落库
                if ($agentRebate <= 0) {
                    $sumRow = Db::fetch(
                        'SELECT COALESCE(SUM(amount),0) AS s FROM ' . Db::table('chat_red_packet_settlements')
                        . " WHERE packet_id=? AND settle_type IN ('agent_rebate','dual_rebate') AND status=1",
                        [$packetId]
                    );
                    $agentRebate = round((float)($sumRow['s'] ?? 0), 2);
                }
            } else {
                if ($agentUserId <= 0 || $agentRate <= 0) {
                    $resolved = $this->resolveAgent($fromUserId, (int)($packet['group_id'] ?? 0), $packetType);
                    if ($agentUserId <= 0) {
                        $agentUserId = (int)$resolved['agent_user_id'];
                    }
                    if ($agentRate <= 0) {
                        $agentRate = (float)$resolved['rate'];
                    }
                }
                if ($agentUserId <= 0 || $agentUserId === $fromUserId) {
                    $agentUserId = 0;
                }
                $agentRebate = 0.0;
                if ($agentUserId > 0 && $agentRate > 0) {
                    $agentRebate = round($totalAmount * $agentRate, 2);
                    if ($agentRebate > 0) {
                        if ($platformUserId <= 0) {
                            throw new \RuntimeException('platform_user_id not configured');
                        }
                        $rebateOut = $this->wallet->change(
                            $platformUserId,
                            -$agentRebate,
                            'red_packet_agent_rebate',
                            '红包代理返点支出 ' . $packetNo,
                            $bizMeta
                        );
                        $this->wallet->change(
                            $agentUserId,
                            $agentRebate,
                            'red_packet_agent_rebate_in',
                            '红包代理返点收益 ' . $packetNo,
                            $bizMeta
                        );
                        $this->insertSettlement(
                            $packetId, $packetNo, 'agent_rebate', $platformUserId, $agentUserId,
                            $agentRebate, (int)($rebateOut['ledger_id'] ?? 0), 1,
                            '代理返点 ' . ($agentRate * 100) . '%'
                        );
                    }
                }
            }
            } // end !$isPrivate fee/rebate

            // ---------- 落库结算态 ----------
            $compStatus = $losers ? 2 : 0;
            $compUser = $compensateUsers[0] ?? 0;
            Db::exec(
                'UPDATE ' . Db::table('chat_red_packets')
                . ' SET status=5, remain_count=0, remain_amount=0,'
                . ' finished_time=IF(finished_time=0,?,finished_time), settled_time=?,'
                . ' platform_fee_rate=?, platform_fee=?,'
                . ' agent_user_id=?, agent_rebate_rate=?, agent_rebate_amount=?,'
                . ' compensate_amount=?, compensate_user_id=?, compensate_status=?, compensate_time=?,'
                . ' updatetime=?'
                . ' WHERE id=?',
                [
                    $now, $now,
                    sprintf('%.4f', $feeRate),
                    sprintf('%.2f', $platformFee),
                    $agentUserId,
                    sprintf('%.4f', $agentRate),
                    sprintf('%.2f', $agentRebate),
                    sprintf('%.2f', $compensateTotal),
                    $compUser,
                    $compStatus,
                    $losers ? $now : 0,
                    $now,
                    $packetId,
                ]
            );

            Db::commit();
            $result['settled'] = true;
            $result['compensate_users'] = $compensateUsers;
            $result['platform_fee'] = $platformFee;
            $result['agent_rebate'] = $agentRebate;
            error_log(sprintf(
                '[RP_SETTLE] done packet_id=%d losers=%d fee=%.2f rebate=%.2f agent=%d',
                $packetId,
                count($losers),
                $platformFee,
                $agentRebate,
                $agentUserId
            ));
            return $result;
        } catch (\Throwable $e) {
            Db::rollBack();
            $this->releaseLock($lockKey, $gotLock);
            error_log('[RP_SETTLE][ERROR] packet_id=' . $packetId . ' rollback: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 解析代理与返点比例。
     * 代理 = 群主 owner_user_id（非邀请人）；默认 1%；群 rp_agent_rebate_rate>0 时覆盖。
     *
     * @return array{agent_user_id:int,rate:float}
     */
    protected function resolveAgent($fromUserId, $groupId, $packetType = 0)
    {
        $fromUserId = (int)$fromUserId;
        $groupId = (int)$groupId;
        $packetType = (int)$packetType;
        $agentId = 0;
        $rate = round((float)($this->cfg['agent_rebate_rate_default'] ?? 0.01), 4);
        if ($packetType === 3) {
            $rate = round((float)($this->cfg['mine_agent_rebate_rate_default'] ?? $rate), 4);
        } elseif ($packetType === 5) {
            $rate = round((float)($this->cfg['relay_agent_rebate_rate_default'] ?? $rate), 4);
        }

        if ($groupId > 0) {
            $group = Db::fetch(
                'SELECT owner_user_id, is_vip_group, rp_agent_rebate_rate FROM ' . Db::table('chat_groups') . ' WHERE id=? LIMIT 1',
                [$groupId]
            );
            if ($group) {
                $agentId = (int)($group['owner_user_id'] ?? 0);
                $grpRate = round((float)($group['rp_agent_rebate_rate'] ?? 0), 4);
                if ($grpRate > 0) {
                    $rate = $grpRate;
                } elseif ((int)($group['is_vip_group'] ?? 0) === 1) {
                    if ($packetType === 3) {
                        $rate = round((float)($this->cfg['mine_agent_rebate_rate_vip']
                            ?? $this->cfg['agent_rebate_rate_vip'] ?? 0.01), 4);
                    } elseif ($packetType === 5) {
                        $rate = round((float)($this->cfg['relay_agent_rebate_rate_vip']
                            ?? $this->cfg['agent_rebate_rate_vip'] ?? 0.01), 4);
                    } else {
                        $rate = round((float)($this->cfg['agent_rebate_rate_vip'] ?? 0.01), 4);
                    }
                }
            }
        }

        if ($agentId <= 0 || $agentId === $fromUserId) {
            $agentId = 0;
        }

        return ['agent_user_id' => $agentId, 'rate' => $rate];
    }

    /**
     * 扫雷中雷赔付倍率：5→1.5 / 7→1.2 / 9→1.0（后台可配）
     */
    protected function mineCompensateMultiplier($totalCount)
    {
        $totalCount = (int)$totalCount;
        $defaults = [5 => 1.5, 7 => 1.2, 9 => 1.0];
        $key = 'mine_compensate_rate_' . $totalCount;
        $rate = isset($this->cfg[$key]) ? (float)$this->cfg[$key] : ($defaults[$totalCount] ?? 1.0);
        if ($rate <= 0) {
            $rate = $defaults[$totalCount] ?? 1.0;
        }
        return round($rate, 4);
    }

    protected function mineCompensateAmount($totalAmount, $totalCount)
    {
        return round((float)$totalAmount * $this->mineCompensateMultiplier($totalCount), 2);
    }

    protected function releaseLock($lockKey, $gotLock)
    {
        if (!$gotLock) {
            return;
        }
        try {
            RedisClient::conn()->del($lockKey);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    protected function insertSettlement($packetId, $packetNo, $type, $fromUid, $toUid, $amount, $ledgerId, $status, $remark)
    {
        Db::exec(
            'INSERT INTO ' . Db::table('chat_red_packet_settlements')
            . ' (packet_id,packet_no,settle_type,from_user_id,to_user_id,amount,ledger_id,status,remark,createtime)'
            . ' VALUES (?,?,?,?,?,?,?,?,?,?)',
            [
                (int)$packetId,
                (string)$packetNo,
                (string)$type,
                (int)$fromUid,
                (int)$toUid,
                sprintf('%.2f', $amount),
                (int)$ledgerId,
                (int)$status,
                mb_substr((string)$remark, 0, 255),
                time(),
            ]
        );
    }
}
