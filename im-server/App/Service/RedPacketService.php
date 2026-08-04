<?php

namespace Im\Service;

use Im\Support\Db;
use Im\Support\TronFair;
use Im\Support\TronBlockClient;
use Im\Support\TronHashCache;
use Im\Support\IdGenerator;
use Im\Support\RedisClient;
use Im\Support\PushBus;
use Workerman\Timer;

class RedPacketService
{
    /** @var array */
    protected $cfg;
    /** @var MessageService */
    protected $messages;
    /** @var GroupService */
    protected $groups;
    /** @var WalletService */
    protected $wallet;

    public function __construct(array $appCfg, MessageService $messages, GroupService $groups)
    {
        $this->cfg = $appCfg['red_packet'] ?? [];
        $this->messages = $messages;
        $this->groups = $groups;
        $this->wallet = new WalletService($appCfg);
    }

    /**
     * 发红包：扣余额 → 落库 → Redis 预拆包队列（分）
     * 支持 packet_type: 1普通 2拼手气 3埋雷 4随机；可选 mine_digit / skin_id
     */
    public function send(array $params)
    {
        $fromUserId = (int)($params['from_user_id'] ?? 0);
        $scopeType = (int)($params['scope_type'] ?? 2); // 1私聊 2群聊
        $groupId = (int)($params['group_id'] ?? 0);
        $toUserId = (int)($params['to_user_id'] ?? 0);
        $packetType = (int)($params['packet_type'] ?? 2); // 1普通 2拼手气 3埋雷 4随机
        $totalAmount = round((float)($params['total_amount'] ?? 0), 2);
        $totalCount = (int)($params['total_count'] ?? 0);
        $mineDigit = (int)($params['mine_digit'] ?? 0);
        $skinId = (int)($params['skin_id'] ?? 0);
        $blessing = mb_substr(trim((string)($params['blessing'] ?? '恭喜发财')), 0, 100);
        if ($blessing === '') {
            $blessing = '恭喜发财';
        }
        // 1普通 2拼手气(可自领+最少赔付) 3埋雷 4随机 5接龙(最少续发)
        if (!in_array($packetType, [1, 2, 3, 4, 5], true)) {
            throw new \InvalidArgumentException('invalid packet_type');
        }
        // 拼手气 / 扫雷 / 接龙：祝福语固定为玩法标题
        $fixedBlessing = [
            2 => '红宝拼手气',
            3 => '红宝扫雷',
            5 => '红宝接龙',
        ];
        if (isset($fixedBlessing[$packetType])) {
            $blessing = $fixedBlessing[$packetType];
        }
        if ($packetType === 3) {
            if ($mineDigit < 0 || $mineDigit > 9) {
                throw new \InvalidArgumentException('mine_digit must be 0-9');
            }
        } else {
            $mineDigit = 0;
        }

        $isUserRp = in_array($packetType, [1, 4], true);
        $isRelay = ($packetType === 5);
        $minCent = (int)($this->cfg['min_amount_cent'] ?? 1);
        $maxCount = (int)($this->cfg['max_count'] ?? 10);
        $expireSec = (int)($this->cfg['expire_seconds'] ?? 60);
        $globalMinAmount = round((float)($this->cfg['min_amount'] ?? 10), 2);
        $feeRate = round((float)($this->cfg['platform_fee_rate'] ?? 0.03), 4);
        $agentRate = round((float)($this->cfg['agent_rebate_rate_default'] ?? 0.01), 4);
        $inviteRate = round((float)($this->cfg['invite_rebate_rate'] ?? 0.005), 4);
        $platformUserId = (int)($this->cfg['platform_user_id'] ?? 0);
        if ($packetType === 3) {
            $expireSec = max(1, (int)($this->cfg['mine_expire_seconds'] ?? 180));
            $feeRate = round((float)($this->cfg['mine_platform_fee_rate'] ?? $feeRate), 4);
            $agentRate = round((float)($this->cfg['mine_agent_rebate_rate_default'] ?? $agentRate), 4);
            $inviteRate = round((float)($this->cfg['mine_invite_rebate_rate'] ?? $inviteRate), 4);
            $minePlatformUid = (int)($this->cfg['mine_platform_user_id'] ?? 0);
            if ($minePlatformUid > 0) {
                $platformUserId = $minePlatformUid;
            }
        } elseif ($isRelay) {
            // 接龙红包：独立全局配置（缺省回退拼手气配置，兼容旧数据）
            $expireSec = max(1, (int)($this->cfg['relay_expire_seconds'] ?? 1800));
            $maxCount = max(1, (int)($this->cfg['relay_max_count'] ?? $maxCount));
            $globalMinAmount = round((float)($this->cfg['relay_min_amount'] ?? $globalMinAmount), 2);
            $feeRate = round((float)($this->cfg['relay_platform_fee_rate'] ?? $feeRate), 4);
            $agentRate = round((float)($this->cfg['relay_agent_rebate_rate_default'] ?? $agentRate), 4);
            $inviteRate = round((float)($this->cfg['relay_invite_rebate_rate'] ?? $inviteRate), 4);
            $relayPlatformUid = (int)($this->cfg['relay_platform_user_id'] ?? 0);
            if ($relayPlatformUid > 0) {
                $platformUserId = $relayPlatformUid;
            }
        } elseif ($isUserRp) {
            // 普通用户群红宝：普通/随机单独配置（默认 30 分钟过期）
            $expireSec = max(1, (int)($this->cfg['user_rp_expire_seconds'] ?? 1800));
            $maxCount = max(1, (int)($this->cfg['user_rp_max_count'] ?? $maxCount));
            $globalMinAmount = round((float)($this->cfg['user_rp_min_amount'] ?? $globalMinAmount), 2);
            $feeRate = round((float)($this->cfg['user_rp_platform_fee_rate'] ?? $feeRate), 4);
            $agentRate = round((float)($this->cfg['user_rp_agent_rebate_rate_default'] ?? $agentRate), 4);
            $inviteRate = round((float)($this->cfg['user_rp_invite_rebate_rate'] ?? $inviteRate), 4);
            $userPlatformUid = (int)($this->cfg['user_rp_platform_user_id'] ?? 0);
            if ($userPlatformUid > 0) {
                $platformUserId = $userPlatformUid;
            }
        }
        // 私聊红包：仅对方可领、不抽水不返点；固定 1 个普通包（无赔付玩法）
        if ($scopeType === 1) {
            $feeRate = 0.0;
            $agentRate = 0.0;
            $inviteRate = 0.0;
            $platformUserId = 0;
            $totalCount = 1;
            $packetType = 1;
            $isUserRp = true;
            $mineDigit = 0;
            $expireSec = max(1, (int)($this->cfg['user_rp_expire_seconds'] ?? 1800));
        }

        if ($fromUserId <= 0 || $totalAmount <= 0 || $totalCount <= 0) {
            throw new \InvalidArgumentException('invalid red packet params');
        }
        if ($totalCount > $maxCount) {
            throw new \InvalidArgumentException('too many packets');
        }

        // 平台抽水在发送时从总额划出：例 100×3%=3，可抢池=97；扫雷赔付按倍率×total_amount
        $platformFee = round($totalAmount * $feeRate, 2);
        if ($platformFee < 0) {
            $platformFee = 0.0;
        }
        $poolAmount = round($totalAmount - $platformFee, 2);
        if ($poolAmount <= 0) {
            throw new \InvalidArgumentException('amount too small after fee');
        }
        $poolCent = (int)round($poolAmount * 100);
        if ($poolCent < $totalCount * $minCent) {
            throw new \InvalidArgumentException('amount too small');
        }

        $group = null;
        $agentUserId = 0;
        if ($scopeType === 2) {
            if ($groupId <= 0 || !$this->groups->isMember($groupId, $fromUserId)) {
                throw new \RuntimeException('not in group');
            }
            $this->groups->assertCanSendGroupRedPacket($groupId, $fromUserId, [
                'robot_send'    => !empty($params['robot_send']),
                'trusted_robot' => !empty($params['robot_send']) && !empty($params['trusted_robot']),
                'packet_type'   => $packetType,
            ]);
            $group = $this->groups->get($groupId);
            if (!$group) {
                throw new \RuntimeException('invalid group');
            }
            $this->assertGroupRpLimits($group, $packetType, $totalAmount, $totalCount, $globalMinAmount);
            // 代理 = 群主；默认 1%，群 rp_agent_rebate_rate>0 时覆盖
            $agentUserId = (int)($group['owner_user_id'] ?? 0);
            if ((int)($group['is_vip_group'] ?? 0) === 1) {
                if ($packetType === 3) {
                    $agentRate = round((float)($this->cfg['mine_agent_rebate_rate_vip']
                        ?? $this->cfg['agent_rebate_rate_vip'] ?? 0.01), 4);
                } elseif ($packetType === 5) {
                    $agentRate = round((float)($this->cfg['relay_agent_rebate_rate_vip']
                        ?? $this->cfg['agent_rebate_rate_vip'] ?? 0.01), 4);
                } elseif ($isUserRp) {
                    $agentRate = round((float)($this->cfg['user_rp_agent_rebate_rate_vip']
                        ?? $this->cfg['agent_rebate_rate_vip'] ?? 0.01), 4);
                } else {
                    $agentRate = round((float)($this->cfg['agent_rebate_rate_vip'] ?? 0.01), 4);
                }
            }
            $grpRate = round((float)($group['rp_agent_rebate_rate'] ?? 0), 4);
            if ($grpRate > 0) {
                $agentRate = $grpRate;
            }
            if ($agentUserId <= 0 || $agentUserId === $fromUserId) {
                $agentUserId = 0;
            }
            $conversationId = (string)$groupId;
        } else {
            if ($toUserId <= 0 || $toUserId === $fromUserId) {
                throw new \InvalidArgumentException('invalid private target');
            }
            if ($totalAmount < $globalMinAmount) {
                throw new \InvalidArgumentException('amount below min ' . $globalMinAmount);
            }
            $conversationId = IdGenerator::privateConversationId($fromUserId, $toUserId);
            $groupId = 0;
            $agentUserId = 0;
        }

        $bgImage = '';
        if ($skinId > 0) {
            $skin = Db::fetch(
                'SELECT * FROM ' . Db::table('chat_red_packet_skins')
                . ' WHERE id=? AND status=? LIMIT 1',
                [$skinId, 'normal']
            );
            if (!$skin) {
                throw new \InvalidArgumentException('invalid skin');
            }
            $st = (int)$skin['packet_type'];
            if ($st !== 0 && $st !== $packetType) {
                throw new \InvalidArgumentException('skin type mismatch');
            }
            $bgImage = (string)$skin['image'];
        }

        $packetNo = IdGenerator::packetNo();
        $now = time();
        $expireAt = $now + max(1, $expireSec);
        $field = $this->wallet->field();

        // 拼手气/接龙：Redis 最新哈希立刻拆包
        // 扫雷：哈希末位必须等于手填雷号；匹配后才拆包开抢，否则 pending
        $tronBlockNum = 0;
        $tronBlockId = '';
        $tronLucky = '';
        $tronStatus = 0;
        $fairCentsJson = '';
        $fairRevealedAt = 0;
        $cents = [];
        $minePending = false;
        if ($packetType === 2 || $packetType === 5) {
            $latest = TronHashCache::get();
            if (!$latest) {
                $latest = TronHashCache::refresh(2);
            }
            if (!$latest) {
                throw new \RuntimeException('tron latest hash unavailable');
            }
            $tronBlockNum = (int)$latest['block_num'];
            $tronBlockId = (string)$latest['block_id'];
            $tronLucky = TronBlockClient::luckyFromBlockId($tronBlockId);
            $cents = $this->splitLuckyFromHash($poolCent, $totalCount, $minCent, $tronBlockId, $packetNo);
            $tronStatus = TronFair::STATUS_DONE;
            $fairCentsJson = json_encode($cents, JSON_UNESCAPED_UNICODE);
            $fairRevealedAt = $now;
        } elseif ($packetType === 3) {
            // 优先本地 Redis：按雷号命中最近缓存块（O(1)，不打波场）
            $matched = TronHashCache::findByDigit((int)$mineDigit);
            if (!$matched) {
                $latest = TronHashCache::get();
                if (!$latest) {
                    try {
                        $latest = TronHashCache::refresh(2);
                    } catch (\Throwable $e) {
                        $latest = null;
                    }
                }
                if ($latest && TronBlockClient::luckyDigitFromBlockId($latest['block_id']) === (int)$mineDigit) {
                    $matched = $latest;
                }
                if ($latest) {
                    $tronBlockNum = (int)$latest['block_num'];
                }
            } else {
                $tronBlockNum = (int)$matched['block_num'];
            }
            if ($matched) {
                $tronBlockNum = (int)$matched['block_num'];
                $tronBlockId = (string)$matched['block_id'];
                $tronLucky = TronBlockClient::luckyFromBlockId($tronBlockId);
                $cents = $this->splitLuckyFromHash($poolCent, $totalCount, $minCent, $tronBlockId, $packetNo);
                $tronStatus = TronFair::STATUS_DONE;
                $fairCentsJson = json_encode($cents, JSON_UNESCAPED_UNICODE);
                $fairRevealedAt = $now;
                $minePending = false;
            } else {
                if ($tronBlockNum <= 0) {
                    try {
                        $tronBlockNum = (int)((TronHashCache::get() ?: [])['block_num'] ?? 0);
                    } catch (\Throwable $e) {
                        $tronBlockNum = 0;
                    }
                }
                $tronStatus = TronFair::STATUS_PENDING;
                $minePending = true;
                $cents = [];
            }
        } elseif ($packetType === 4) {
            // 随机红包：本地随机金额，与拼手气（波场哈希）区分，无机器人接龙
            $cents = $this->splitLucky($poolCent, $totalCount, $minCent);
        } else {
            $cents = $this->splitEqual($poolCent, $totalCount, $minCent);
        }
        if ($cents && array_sum($cents) !== $poolCent) {
            throw new \RuntimeException('split sum mismatch');
        }

        if ($platformFee > 0 && $platformUserId <= 0) {
            throw new \RuntimeException('platform_user_id not configured');
        }

        Db::begin();
        try {
            // 发包人一次扣 total_amount；流水记在 from_user_id（含机器人代扣/接龙代扣）
            $sendRemark = '红宝发包扣款';
            if ($packetType === 2) {
                $sendRemark = '红宝拼手气发包扣款';
            } elseif ($packetType === 3) {
                $sendRemark = '红宝扫雷发包扣款';
            } elseif ($packetType === 5) {
                $sendRemark = '红宝接龙发包扣款';
            }
            if (!empty($params['robot_relay']) || ($packetType === 5 && !empty($params['robot_send']))) {
                $sendRemark = '红宝接龙发包扣款';
            } elseif (!empty($params['robot_send']) && $packetType !== 5) {
                $sendRemark = '红宝代发扣款';
            }
            $this->wallet->change(
                $fromUserId,
                -$totalAmount,
                'red_packet_send',
                $sendRemark,
                ['biz_no' => $packetNo, 'ref_type' => 'red_packet', 'ref_id' => 0]
            );
            if ($platformFee > 0) {
                $this->wallet->change(
                    $platformUserId,
                    $platformFee,
                    'red_packet_fee_in',
                    '收到红包手续费 ' . $packetNo,
                    ['biz_no' => $packetNo, 'ref_type' => 'red_packet', 'ref_id' => 0]
                );
            }
            Db::exec(
                'INSERT INTO ' . Db::table('chat_red_packets')
                . ' (packet_no,scope_type,conversation_id,group_id,to_user_id,from_user_id,agent_user_id,'
                . 'packet_type,mine_digit,skin_id,bg_image,blessing,'
                . 'tron_block_num,tron_block_id,tron_lucky,tron_status,'
                . 'fair_hash,fair_seed,fair_cents,fair_payload,fair_revealed_at,'
                . 'total_amount,total_count,remain_amount,remain_count,'
                . 'platform_fee_rate,platform_fee,pool_amount,sender_pay_amount,'
                . 'agent_rebate_rate,status,expiretime,createtime,updatetime)'
                . ' VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?,?)',
                [
                    $packetNo, $scopeType, $conversationId, $groupId, $toUserId, $fromUserId, $agentUserId,
                    $packetType, $mineDigit, $skinId, $bgImage, $blessing,
                    $tronBlockNum, $tronBlockId, $tronLucky, $tronStatus,
                    $tronBlockId, '', $fairCentsJson, '', $fairRevealedAt,
                    sprintf('%.2f', $totalAmount), $totalCount, sprintf('%.2f', $poolAmount), $totalCount,
                    sprintf('%.4f', $feeRate), sprintf('%.2f', $platformFee), sprintf('%.2f', $poolAmount),
                    sprintf('%.2f', $totalAmount),
                    sprintf('%.4f', $agentRate),
                    $expireAt, $now, $now,
                ]
            );
            $packetId = Db::lastId();

            // 发包瞬间四方分账：3% 手续费池 → 群主1% / 推荐0.5%（双吃1.5%）/ 平台留存
            $splitPaid = 0.0;
            if ($scopeType === 2 && $platformFee > 0 && $platformUserId > 0) {
                $splitPaid = $this->paySendTimeFeeSplit(
                    $packetId,
                    $packetNo,
                    $fromUserId,
                    $agentUserId,
                    $totalAmount,
                    $platformFee,
                    $agentRate,
                    $inviteRate,
                    $platformUserId,
                    $now
                );
            }

            // 回填 ref_id：流水已写入，可忽略；后续用 packet_no 对账
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            throw $e;
        }

        $this->seedRedis($packetId, $cents, $expireAt, [
            'packet_no'    => $packetNo,
            'scope_type'   => $scopeType,
            'group_id'     => $groupId,
            'to_user_id'   => $toUserId,
            'from_user_id' => $fromUserId,
            'total_amount' => sprintf('%.2f', $totalAmount),
            'total_count'  => $totalCount,
            'total_cent'   => $poolCent,
            'packet_type'  => $packetType,
            'mine_digit'   => $mineDigit,
            'tron_status'  => (string)$tronStatus,
            'mine_pending' => $minePending ? '1' : '0',
        ]);

        if ($tronStatus === TronFair::STATUS_DONE && $tronBlockId !== '') {
            try {
                $row = Db::fetch('SELECT * FROM ' . Db::table('chat_red_packets') . ' WHERE id=? LIMIT 1', [$packetId]);
                if ($row) {
                    TronFair::cachePut($row);
                }
            } catch (\Throwable $e) {
            }
        }

        // 扫雷未匹配：异步向后找哈希末位=雷号的区块，匹配后再拆包开抢
        if ($packetType === 3 && $minePending) {
            try {
                TronFair::scheduleReveal($packetId, 1);
            } catch (\Throwable $e) {
                error_log('[TRON] mine match schedule fail packet=' . $packetId . ' ' . $e->getMessage());
            }
        }

        $extra = [
            'packet_id'      => $packetId,
            'packet_no'      => $packetNo,
            'total_amount'   => $totalAmount,
            'total_count'    => $totalCount,
            'packet_type'    => $packetType,
            'mine_digit'     => $mineDigit,
            'tron_block_num' => $tronBlockNum,
            'tron_block_id'  => $tronBlockId,
            'tron_lucky'     => $tronLucky,
            'tron_status'    => $tronStatus,
            'mine_pending'   => $minePending,
            'skin_id'        => $skinId,
            'bg_image'       => $bgImage,
            'blessing'       => $blessing,
            'expiretime'     => $expireAt,
            'proof_type'     => in_array($packetType, [2, 3, 5], true) ? 'tron' : '',
        ];
        if ($scopeType === 2) {
            try {
                $msg = $this->messages->sendGroup($fromUserId, $groupId, '[红包]' . $blessing, 2, $extra);
            } catch (\Throwable $eMsg) {
                // 扣款已提交：绝不能因发言限制导致无卡片；再强制落一条红包消息
                error_log('[RP_SEND] sendGroup fail after debit packet=' . $packetId . ' ' . $eMsg->getMessage());
                $msg = $this->messages->insertGroupMessageUnchecked(
                    $fromUserId,
                    $groupId,
                    '[红包]' . $blessing,
                    2,
                    $extra
                );
            }
        } else {
            $msg = $this->messages->sendPrivate($fromUserId, $toUserId, '[红包]' . $blessing, 2, $extra);
        }

        return [
            'packet_id'  => $packetId,
            'packet_no'  => $packetNo,
            'expiretime' => $expireAt,
            'message'    => $msg,
            'wallet'     => $field,
            'balance'    => $this->wallet->getBalance($fromUserId),
            'hongbao_frozen' => $this->wallet->getFrozen($fromUserId),
        ];
    }

    /**
     * 扫雷：哈希末位匹配手填雷号后，用该块哈希拆金额并开放抢包
     * @param array{block_num:int,block_id:string} $block
     */
    public function activateMineWithBlock($packetId, array $block)
    {
        $packetId = (int)$packetId;
        $blockId = strtolower(trim((string)($block['block_id'] ?? '')));
        $blockNum = (int)($block['block_num'] ?? 0);
        if ($packetId <= 0 || $blockId === '' || $blockNum <= 0) {
            return false;
        }
        $packet = Db::fetch('SELECT * FROM ' . Db::table('chat_red_packets') . ' WHERE id=? LIMIT 1', [$packetId]);
        if (!$packet || (int)($packet['packet_type'] ?? 0) !== 3) {
            return false;
        }
        if ((int)($packet['tron_status'] ?? 0) === TronFair::STATUS_DONE
            && trim((string)($packet['tron_block_id'] ?? '')) !== '') {
            return true;
        }
        $mineDigit = max(0, min(9, (int)($packet['mine_digit'] ?? 0)));
        $hashDigit = TronBlockClient::luckyDigitFromBlockId($blockId);
        if ($hashDigit !== $mineDigit) {
            return false;
        }
        $poolCent = (int)round((float)($packet['pool_amount'] ?? 0) * 100);
        $totalCount = (int)($packet['total_count'] ?? 0);
        $minCent = max(1, (int)($this->cfg['min_amount_cent'] ?? 1));
        $packetNo = (string)($packet['packet_no'] ?? '');
        $cents = $this->splitLuckyFromHash($poolCent, $totalCount, $minCent, $blockId, $packetNo);
        if (array_sum($cents) !== $poolCent) {
            throw new \RuntimeException('mine split sum mismatch');
        }
        $luckyChar = TronBlockClient::luckyFromBlockId($blockId);
        $now = time();
        $fairCentsJson = json_encode($cents, JSON_UNESCAPED_UNICODE);
        $n = Db::exec(
            'UPDATE ' . Db::table('chat_red_packets')
            . ' SET tron_block_num=?, tron_block_id=?, tron_lucky=?, tron_status=?, fair_revealed_at=?,'
            . ' fair_hash=?, fair_cents=?, fair_seed=\'\', fair_payload=\'\', updatetime=?'
            . ' WHERE id=? AND tron_status<>?',
            [
                $blockNum,
                $blockId,
                $luckyChar,
                TronFair::STATUS_DONE,
                $now,
                $blockId,
                $fairCentsJson,
                $now,
                $packetId,
                TronFair::STATUS_DONE,
            ]
        );
        if ($n <= 0) {
            // 可能并发已完成
            $fresh = Db::fetch(
                'SELECT tron_status, tron_block_id FROM ' . Db::table('chat_red_packets') . ' WHERE id=? LIMIT 1',
                [$packetId]
            );
            return $fresh && (int)($fresh['tron_status'] ?? 0) === TronFair::STATUS_DONE
                && trim((string)($fresh['tron_block_id'] ?? '')) !== '';
        }
        $expireAt = (int)($packet['expiretime'] ?? ($now + 180));
        $this->seedRedis($packetId, $cents, $expireAt, [
            'packet_no'    => $packetNo,
            'scope_type'   => (int)$packet['scope_type'],
            'group_id'     => (int)$packet['group_id'],
            'to_user_id'   => (int)$packet['to_user_id'],
            'from_user_id' => (int)$packet['from_user_id'],
            'total_amount' => sprintf('%.2f', (float)$packet['total_amount']),
            'total_count'  => $totalCount,
            'total_cent'   => $poolCent,
            'packet_type'  => 3,
            'mine_digit'   => $mineDigit,
            'tron_status'  => (string)TronFair::STATUS_DONE,
            'mine_pending' => '0',
        ]);
        $fresh = Db::fetch('SELECT * FROM ' . Db::table('chat_red_packets') . ' WHERE id=? LIMIT 1', [$packetId]);
        if ($fresh) {
            TronFair::cachePut($fresh);
        }
        error_log(sprintf(
            '[TRON] mine activate ok packet=%d mine=%d block=#%d hash_tail=%s',
            $packetId,
            $mineDigit,
            $blockNum,
            $luckyChar
        ));
        TronFair::maybeSettleAfterReveal($packetId);
        TronFair::notifyRevealDone($packetId);
        return true;
    }

    /**
     * 扫雷中雷赔付倍率：5→1.5 / 7→1.2 / 9→1.0（后台可配）
     */
    public function mineCompensateMultiplier($totalCount)
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

    /**
     * 扫雷中雷应赔金额 = 发包总额 × 倍率
     */
    public function mineCompensateAmount($totalAmount, $totalCount)
    {
        return round((float)$totalAmount * $this->mineCompensateMultiplier($totalCount), 2);
    }

    /**
     * 发包瞬间：从已入账的 3% 手续费中划转群主/推荐返佣（双吃合并）
     * @return float 已分出金额
     */
    protected function paySendTimeFeeSplit(
        $packetId,
        $packetNo,
        $fromUserId,
        $agentUserId,
        $totalAmount,
        $platformFee,
        $agentRate,
        $inviteRate,
        $platformUserId,
        $now
    ) {
        $packetId = (int)$packetId;
        $fromUserId = (int)$fromUserId;
        $agentUserId = (int)$agentUserId;
        $platformUserId = (int)$platformUserId;
        $totalAmount = round((float)$totalAmount, 2);
        $platformFee = round((float)$platformFee, 2);
        $agentRate = round((float)$agentRate, 4);
        $inviteRate = round((float)$inviteRate, 4);
        if ($packetId <= 0 || $platformFee <= 0 || $platformUserId <= 0) {
            return 0.0;
        }

        $inviteUserId = $this->resolveInviterUserId($fromUserId);
        if ($inviteUserId === $fromUserId || $inviteUserId <= 0) {
            $inviteUserId = 0;
        }
        if ($agentUserId === $fromUserId) {
            $agentUserId = 0;
        }

        $bizMeta = ['biz_no' => $packetNo, 'ref_type' => 'red_packet', 'ref_id' => $packetId];
        $paid = 0.0;
        $agentRebateAmt = 0.0;

        $isDual = ($agentUserId > 0 && $inviteUserId > 0 && $agentUserId === $inviteUserId);
        if ($isDual) {
            $dualRate = round($agentRate + $inviteRate, 4);
            if ($dualRate <= 0) {
                $dualRate = 0.015;
            }
            $dualPay = round($totalAmount * $dualRate, 2);
            if ($dualPay > $platformFee) {
                $dualPay = $platformFee;
            }
            if ($dualPay > 0) {
                $out = $this->wallet->change(
                    $platformUserId,
                    -$dualPay,
                    'red_packet_agent_rebate',
                    '双重返佣支出 ' . $packetNo,
                    $bizMeta
                );
                $this->wallet->change(
                    $agentUserId,
                    $dualPay,
                    'red_packet_dual_rebate_in',
                    '群主+推荐双重返佣 ' . $packetNo,
                    $bizMeta
                );
                $this->insertPacketSettlement(
                    $packetId, $packetNo, 'dual_rebate', $platformUserId, $agentUserId,
                    $dualPay, (int)($out['ledger_id'] ?? 0), 1,
                    '双重返佣 ' . ($dualRate * 100) . '%(发时)', $now
                );
                $paid = $dualPay;
                $agentRebateAmt = $dualPay;
            }
        } else {
            $agentPay = 0.0;
            $invitePay = 0.0;
            if ($agentUserId > 0 && $agentRate > 0) {
                $agentPay = round($totalAmount * $agentRate, 2);
            }
            if ($inviteUserId > 0 && $inviteRate > 0) {
                $invitePay = round($totalAmount * $inviteRate, 2);
            }
            $need = round($agentPay + $invitePay, 2);
            if ($need > $platformFee && $need > 0) {
                $scale = $platformFee / $need;
                $agentPay = round($agentPay * $scale, 2);
                $invitePay = round($platformFee - $agentPay, 2);
            }
            if ($agentPay > 0) {
                $out = $this->wallet->change(
                    $platformUserId,
                    -$agentPay,
                    'red_packet_agent_rebate',
                    '群主管理津贴支出 ' . $packetNo,
                    $bizMeta
                );
                $this->wallet->change(
                    $agentUserId,
                    $agentPay,
                    'red_packet_agent_rebate_in',
                    '群聊管理津贴 ' . $packetNo,
                    $bizMeta
                );
                $this->insertPacketSettlement(
                    $packetId, $packetNo, 'agent_rebate', $platformUserId, $agentUserId,
                    $agentPay, (int)($out['ledger_id'] ?? 0), 1,
                    '群主返佣 ' . ($agentRate * 100) . '%(发时)', $now
                );
                $paid = round($paid + $agentPay, 2);
                $agentRebateAmt = $agentPay;
            }
            if ($invitePay > 0) {
                $out = $this->wallet->change(
                    $platformUserId,
                    -$invitePay,
                    'red_packet_agent_rebate',
                    '推荐返佣支出 ' . $packetNo,
                    $bizMeta
                );
                $this->wallet->change(
                    $inviteUserId,
                    $invitePay,
                    'red_packet_invite_rebate_in',
                    '推荐发包返佣 ' . $packetNo,
                    $bizMeta
                );
                $this->insertPacketSettlement(
                    $packetId, $packetNo, 'invite_rebate', $platformUserId, $inviteUserId,
                    $invitePay, (int)($out['ledger_id'] ?? 0), 1,
                    '推荐返佣 ' . ($inviteRate * 100) . '%(发时)', $now
                );
                $paid = round($paid + $invitePay, 2);
            }
        }

        if ($agentRebateAmt > 0) {
            Db::exec(
                'UPDATE ' . Db::table('chat_red_packets')
                . ' SET agent_rebate_amount=?, updatetime=? WHERE id=?',
                [sprintf('%.2f', $agentRebateAmt), $now, $packetId]
            );
        }

        // 记录发时平台抽水结算行（便于对账；抢完结算跳过重复扣费）
        $existFee = Db::fetch(
            'SELECT id FROM ' . Db::table('chat_red_packet_settlements')
            . ' WHERE packet_id=? AND settle_type=? LIMIT 1',
            [$packetId, 'platform_fee']
        );
        if (!$existFee) {
            $this->insertPacketSettlement(
                $packetId, $packetNo, 'platform_fee', $fromUserId, $platformUserId,
                $platformFee, 0, 1,
                '平台抽水(发时已扣，已分账 ' . sprintf('%.2f', $paid) . ')', $now
            );
        }

        return $paid;
    }

    /** 发包人手动邀请人（fa_fans_invite.invitee_user_id） */
    protected function resolveInviterUserId($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return 0;
        }
        try {
            $row = Db::fetch(
                'SELECT inviter_user_id FROM ' . Db::table('fans_invite')
                . ' WHERE invitee_user_id=? ORDER BY id ASC LIMIT 1',
                [$userId]
            );
            return (int)($row['inviter_user_id'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 过期无人领：收回发时分账，保证平台户有足额手续费可退
     */
    protected function clawbackSendTimeFeeSplit($packetId, $packetNo, $platformUserId)
    {
        $packetId = (int)$packetId;
        $platformUserId = (int)$platformUserId;
        if ($packetId <= 0 || $platformUserId <= 0) {
            return;
        }
        $rows = Db::fetchAll(
            'SELECT id, settle_type, to_user_id, amount, status FROM ' . Db::table('chat_red_packet_settlements')
            . " WHERE packet_id=? AND settle_type IN ('agent_rebate','invite_rebate','dual_rebate') AND status=1",
            [$packetId]
        );
        if (!$rows) {
            return;
        }
        $bizMeta = ['biz_no' => $packetNo, 'ref_type' => 'red_packet', 'ref_id' => $packetId];
        foreach ($rows as $row) {
            $toUid = (int)($row['to_user_id'] ?? 0);
            $amt = round((float)($row['amount'] ?? 0), 2);
            $sid = (int)($row['id'] ?? 0);
            if ($toUid <= 0 || $amt <= 0) {
                continue;
            }
            try {
                $this->wallet->change(
                    $toUid,
                    -$amt,
                    'red_packet_agent_rebate',
                    '未领红包收回返佣 ' . $packetNo,
                    $bizMeta
                );
                $this->wallet->change(
                    $platformUserId,
                    $amt,
                    'red_packet_fee_in',
                    '未领红包收回返佣入账 ' . $packetNo,
                    $bizMeta
                );
                if ($sid > 0) {
                    Db::exec(
                        'UPDATE ' . Db::table('chat_red_packet_settlements')
                        . ' SET status=3, remark=CONCAT(IFNULL(remark,\'\'),\' | clawback\') WHERE id=?',
                        [$sid]
                    );
                }
            } catch (\Throwable $e) {
                error_log('[RP_EXPIRE] clawback fail packet=' . $packetId . ' to=' . $toUid . ' ' . $e->getMessage());
                throw $e;
            }
        }
    }

    protected function insertPacketSettlement(
        $packetId,
        $packetNo,
        $type,
        $fromUid,
        $toUid,
        $amount,
        $ledgerId,
        $status,
        $remark,
        $now = 0
    ) {
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
                $now > 0 ? (int)$now : time(),
            ]
        );
    }

    /**
     * 群限额与允许类型校验
     */
    protected function assertGroupRpLimits(array $group, $packetType, $totalAmount, $totalCount, $globalMinAmount)
    {
        $fixedAmount = round((float)($group['rp_fixed_amount'] ?? 0), 2);
        if ($fixedAmount > 0) {
            if (abs($totalAmount - $fixedAmount) > 0.001) {
                throw new \InvalidArgumentException('金额须为 ' . number_format($fixedAmount, 2, '.', '') . ' 元');
            }
        }
        $minAmount = round((float)($group['rp_min_amount'] ?? 0), 2);
        if ($minAmount <= 0) {
            $minAmount = $globalMinAmount;
        }
        if ($fixedAmount <= 0 && $totalAmount < $minAmount) {
            throw new \InvalidArgumentException('金额不能低于本群最低 ' . number_format($minAmount, 2, '.', '') . ' 元');
        }
        $isVip = (int)($group['is_vip_group'] ?? 0) === 1;
        $isUserRp = in_array((int)$packetType, [1, 4], true);
        $isRelay = ((int)$packetType === 5);
        // 普通/随机：个数自由填写，不受群「最少/最多个数」玩法限制
        if ($isUserRp) {
            $minCount = max(1, (int)($this->cfg['user_rp_min_count'] ?? 1));
            $maxCount = max($minCount, (int)($this->cfg['user_rp_max_count'] ?? 500));
        } else {
            $minCount = (int)($group['rp_min_count'] ?? 0);
            $maxCount = (int)($group['rp_max_count'] ?? 0);
            if ($minCount <= 0) {
                if ($isRelay) {
                    $minCount = $isVip
                        ? (int)($this->cfg['relay_vip_min_count'] ?? $this->cfg['vip_min_count'] ?? 5)
                        : (int)($this->cfg['relay_min_count'] ?? $this->cfg['min_count'] ?? 5);
                } else {
                    $minCount = $isVip
                        ? (int)($this->cfg['vip_min_count'] ?? 5)
                        : (int)($this->cfg['min_count'] ?? 5);
                }
            }
            if ($maxCount <= 0) {
                if ($isRelay) {
                    $maxCount = $isVip
                        ? (int)($this->cfg['relay_vip_max_count'] ?? $this->cfg['vip_max_count'] ?? 10)
                        : (int)($this->cfg['relay_max_count'] ?? $this->cfg['max_count'] ?? 10);
                } else {
                    $maxCount = $isVip
                        ? (int)($this->cfg['vip_max_count'] ?? 10)
                        : (int)($this->cfg['max_count'] ?? 10);
                }
            }
        }
        if ($minCount < 1) {
            $minCount = 1;
        }
        if ($maxCount < $minCount) {
            $maxCount = $minCount;
        }
        if ($packetType === 3) {
            // 扫雷固定 5 / 7 / 9，再按本群个数区间过滤
            $mineAllowed = [];
            foreach ([5, 7, 9] as $n) {
                if ($n >= $minCount && $n <= $maxCount) {
                    $mineAllowed[] = $n;
                }
            }
            if (!$mineAllowed) {
                $mineAllowed = [5, 7, 9];
            }
            if (!in_array($totalCount, $mineAllowed, true)) {
                if (count($mineAllowed) === 1) {
                    throw new \InvalidArgumentException('本群扫雷红包个数固定为 ' . $mineAllowed[0] . ' 个');
                }
                throw new \InvalidArgumentException('扫雷红包个数仅可选 ' . implode(' / ', $mineAllowed));
            }
        } elseif ($totalCount < $minCount || $totalCount > $maxCount) {
            if ($minCount === $maxCount) {
                throw new \InvalidArgumentException('本群红包个数固定为 ' . $minCount . ' 个');
            }
            throw new \InvalidArgumentException('红包个数须为 ' . $minCount . '～' . $maxCount);
        }
        $enabled = (string)($group['rp_enabled_types'] ?? '1,3,4,5');
        $allowed = array_filter(array_map('intval', explode(',', $enabled)));
        if ($allowed && !in_array((int)$packetType, $allowed, true)) {
            throw new \InvalidArgumentException('packet type not allowed in this group');
        }
    }

    /**
     * 管理端：强制对指定包结算
     */
    public function adminSettle($packetId)
    {
        $packetId = (int)$packetId;
        $packet = Db::fetch(
            'SELECT id, packet_type, tron_status FROM ' . Db::table('chat_red_packets') . ' WHERE id=? LIMIT 1',
            [$packetId]
        );
        $result = (new RedPacketSettlementService($this->wallet, ['red_packet' => $this->cfg]))
            ->settleAfterFinished($packetId);
        if (!empty($result['settled']) && $packet && in_array((int)$packet['packet_type'], [2, 5], true)) {
            $this->revealFairProof($packetId);
        }
        return $result;
    }

    /**
     * 管理端：强制过期退回（可不检查 expiretime）
     */
    public function adminRefund($packetId, $force = false)
    {
        $packetId = (int)$packetId;
        $packet = Db::fetch('SELECT * FROM ' . Db::table('chat_red_packets') . ' WHERE id=? LIMIT 1', [$packetId]);
        if (!$packet) {
            throw new \RuntimeException('packet not found');
        }
        if ((int)$packet['status'] !== 1) {
            throw new \RuntimeException('packet not open');
        }
        if (!$force && (int)$packet['expiretime'] > time()) {
            // 强制标记为已到期再退
            Db::exec(
                'UPDATE ' . Db::table('chat_red_packets') . ' SET expiretime=? WHERE id=? AND status=1',
                [time() - 1, $packetId]
            );
            $packet['expiretime'] = time() - 1;
        }
        $this->refundOne($packet);
        return ['packet_id' => $packetId, 'refunded' => true];
    }

    /**
     * 管理端：强制关包（清 Redis，不退款）
     */
    public function adminClose($packetId)
    {
        $packetId = (int)$packetId;
        $now = time();
        Db::begin();
        try {
            $packet = Db::fetch(
                'SELECT * FROM ' . Db::table('chat_red_packets') . ' WHERE id=? FOR UPDATE',
                [$packetId]
            );
            if (!$packet || (int)$packet['status'] !== 1) {
                Db::rollBack();
                throw new \RuntimeException('packet not open');
            }
            Db::exec(
                'UPDATE ' . Db::table('chat_red_packets')
                . ' SET status=4, updatetime=? WHERE id=?',
                [$now, $packetId]
            );
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            throw $e;
        }
        try {
            $r = RedisClient::conn();
            $r->del(
                RedisClient::key('rp:' . $packetId . ':queue'),
                RedisClient::key('rp:' . $packetId . ':grabbed'),
                RedisClient::key('rp:' . $packetId . ':meta')
            );
        } catch (\Throwable $e) {
            // ignore
        }
        return ['packet_id' => $packetId, 'closed' => true];
    }

    /**
     * 红宝（高并发安全）：
     * 1) 验资拦截（手气包/埋雷包：余额必须 ≥ 红包总金额，否则无法覆盖赔付）
     * 2) Redis Lua 原子 LPOP + 占坑（防超发/防重复抢）
     * 3) MySQL 写领取明细 + 入账
     * 4) 若为最后一个包，触发 Settlement 结算（中雷/最差赔付）
     */
    public function grab($packetId, $userId)
    {
        $packetId = (int)$packetId;
        $userId = (int)$userId;
        if ($packetId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException('invalid grab');
        }

        $packet = $this->packetMetaForGrab($packetId);
        $fromRedisMeta = $packet !== null;
        if (!$packet) {
            $packet = Db::fetch('SELECT * FROM ' . Db::table('chat_red_packets') . ' WHERE id=? LIMIT 1', [$packetId]);
            if (!$packet) {
                throw new \RuntimeException('packet not found');
            }
        }
        if ((int)$packet['scope_type'] === 2) {
            if (!$this->groups->isMember((int)$packet['group_id'], $userId)) {
                throw new \RuntimeException('not in group');
            }
        } elseif ((int)$packet['scope_type'] === 1) {
            // 私聊红包仅对方可领（发包人/机器人不可领）
            if ($userId !== (int)$packet['to_user_id']) {
                throw new \RuntimeException('only recipient can grab');
            }
        }
        if (!$fromRedisMeta && (int)$packet['status'] !== 1) {
            throw new \RuntimeException('packet closed');
        }
        $expireAt = (int)($packet['expiretime'] ?? ($packet['expire_at'] ?? 0));
        if ($expireAt > 0 && $expireAt < time()) {
            throw new \RuntimeException('packet expired');
        }

        $packetType = (int)$packet['packet_type'];
        $totalAmount = round((float)$packet['total_amount'], 2);
        $packetNo = (string)$packet['packet_no'];

        // 扫雷：须等哈希末位匹配手填雷号并拆包后才能抢
        if ($packetType === 3) {
            $tronRow = Db::fetch(
                'SELECT tron_status, tron_block_id FROM ' . Db::table('chat_red_packets') . ' WHERE id=? LIMIT 1',
                [$packetId]
            );
            $ts = (int)($tronRow['tron_status'] ?? ($packet['tron_status'] ?? 0));
            $bid = trim((string)($tronRow['tron_block_id'] ?? ($packet['tron_block_id'] ?? '')));
            if ($ts !== TronFair::STATUS_DONE || $bid === '') {
                throw new \RuntimeException('mine_hash_pending');
            }
        }

        // ---------- 关键节点：验资拦截（必须在 Redis 弹队列之前）----------
        // 拼手气(2)：领取冻结够赔付额；扫雷(3)不冻结、中雷即时扣；接龙(5)领取不冻、判定最少后再冻续发额
        // 扫雷额外：余额必须严格大于最低限制（如 10 元），才可领取。
        // 私聊红包无赔付玩法，跳过验资门槛。
        $needCompensate = 0.0;
        $shouldFreeze = false;
        // type2: freeze compensate(=total); type3: no freeze; type5: freeze only if amount==global min
        if ((int)($packet['scope_type'] ?? 0) !== 1 && $packetType === 2) {
            $needCompensate = $totalAmount;
            $shouldFreeze = true;
            if (!$this->wallet->hasEnoughBalance($userId, $needCompensate)) {
                error_log(sprintf(
                    '[RP_GRAB][ERROR] balance gate reject user=%d packet_id=%d packet_no=%s need=%.2f type=%d',
                    $userId,
                    $packetId,
                    $packetNo,
                    $needCompensate,
                    $packetType
                ));
                throw new \RuntimeException('balance_not_enough_for_compensate:' . sprintf('%.2f', $needCompensate));
            }
        } elseif ((int)($packet['scope_type'] ?? 0) !== 1 && $packetType === 3) {
            $needCompensate = $this->mineCompensateAmount(
                $totalAmount,
                (int)($packet['total_count'] ?? 0)
            );
            $minGate = round((float)($this->cfg['min_amount'] ?? 10), 2);
            if ((int)($packet['scope_type'] ?? 0) === 2 && (int)($packet['group_id'] ?? 0) > 0) {
                $g = $this->groups->get((int)$packet['group_id']);
                $gMin = round((float)($g['rp_min_amount'] ?? 0), 2);
                if ($gMin > 0) {
                    $minGate = $gMin;
                }
            }
            if ($minGate > 0) {
                $bal = $this->wallet->getBalance($userId);
                if ($bal <= $minGate + 0.00001) {
                    error_log(sprintf(
                        '[RP_GRAB][ERROR] mine min gate reject user=%d packet_id=%d bal=%.2f need_gt=%.2f',
                        $userId,
                        $packetId,
                        $bal,
                        $minGate
                    ));
                    throw new \RuntimeException('balance_below_mine_min');
                }
            }
            if ($needCompensate > 0.00001 && !$this->wallet->hasEnoughBalance($userId, $needCompensate)) {
                error_log(sprintf(
                    '[RP_GRAB][ERROR] balance gate reject user=%d packet_id=%d packet_no=%s need=%.2f type=%d',
                    $userId,
                    $packetId,
                    $packetNo,
                    $needCompensate,
                    $packetType
                ));
                throw new \RuntimeException('balance_not_enough_for_compensate:' . sprintf('%.2f', $needCompensate));
            }
        }

        $queueKey = RedisClient::key('rp:' . $packetId . ':queue');
        $grabbedKey = RedisClient::key('rp:' . $packetId . ':grabbed');
        $metaKey = RedisClient::key('rp:' . $packetId . ':meta');

        // 若 Redis 队列丢失但库仍可抢，尝试按剩余均分补种（兜底）
        if (!$fromRedisMeta) {
            $this->ensureRedisSeeded($packet, $queueKey, $metaKey);
        }

        // ---------- 关键节点：Redis 原子弹队列（Lua LPOP + SADD，防并发超发）----------
        $result = $this->evalGrabPacket($queueKey, $grabbedKey, $metaKey, $userId);
        if (!is_array($result)) {
            throw new \RuntimeException('grab lua failed');
        }
        $code = (int)($result['code'] ?? -1);
        if ($code === 410 && $fromRedisMeta) {
            $fullPacket = Db::fetch('SELECT * FROM ' . Db::table('chat_red_packets') . ' WHERE id=? LIMIT 1', [$packetId]);
            if ($fullPacket) {
                if ((int)$fullPacket['status'] !== 1) {
                    throw new \RuntimeException('packet closed');
                }
                if ((int)$fullPacket['expiretime'] > 0 && (int)$fullPacket['expiretime'] < time()) {
                    throw new \RuntimeException('packet expired');
                }
                $this->ensureRedisSeeded($fullPacket, $queueKey, $metaKey);
                $retry = $this->evalGrabPacket($queueKey, $grabbedKey, $metaKey, $userId);
                if (is_array($retry)) {
                    $result = $retry;
                    $code = (int)($result['code'] ?? -1);
                    $packet = $fullPacket;
                    $fromRedisMeta = false;
                }
            }
        }
        if ($code === 409) {
            $exists = Db::fetch(
                'SELECT amount FROM ' . Db::table('chat_red_packet_records') . ' WHERE packet_id=? AND user_id=? LIMIT 1',
                [$packetId, $userId]
            );
            if ($exists) {
                $remainCount = isset($result['remain']) ? (int)$result['remain'] : 0;
                $statusNow = $remainCount <= 0 ? 2 : 1;
                return [
                    'packet_id'    => $packetId,
                    'amount'       => round((float)$exists['amount'], 2),
                    'remain_count' => $remainCount,
                    'status'       => $statusNow,
                    'already'      => true,
                    'balance'      => $this->wallet->getBalance($userId),
                    'hongbao_frozen' => $this->wallet->getFrozen($userId),
                    'packet'       => [
                        'scope_type'   => (int)($packet['scope_type'] ?? 0),
                        'group_id'     => (int)($packet['group_id'] ?? 0),
                        'from_user_id' => (int)($packet['from_user_id'] ?? 0),
                        'to_user_id'   => (int)($packet['to_user_id'] ?? 0),
                    ],
                ];
            }
            throw new \RuntimeException('already grabbed');
        }
        if ($code === 410) {
            $msg = (string)($result['msg'] ?? 'empty');
            error_log('[RP_GRAB] redis empty/expired packet_id=' . $packetId . ' user=' . $userId . ' msg=' . $msg);
            throw new \RuntimeException($msg === 'expired' ? 'packet expired' : 'packet empty');
        }
        if ($code !== 0) {
            error_log('[RP_GRAB][ERROR] lua code=' . $code . ' packet_id=' . $packetId . ' user=' . $userId);
            throw new \RuntimeException('grab failed');
        }

        $amountCent = (int)$result['amount_cent'];
        $amount = round($amountCent / 100, 2);
        $tailDigit = $amountCent % 10;
        $remain = (int)$result['remain'];
        $now = time();

        $status = $remain <= 0 ? 2 : 1;
        $walletChange = null;
        $frozenAmt = 0.0;
        $mineHit = false;
        $minePayAmount = 0.0;
        $fromUserId = (int)($packet['from_user_id'] ?? 0);
        $mineDigit = max(0, min(9, (int)($packet['mine_digit'] ?? 0)));
        Db::begin();
        try {
            // 条件更新扣剩余（去掉 SELECT FOR UPDATE，缩短锁等待）
            $affected = Db::exec(
                'UPDATE ' . Db::table('chat_red_packets')
                . ' SET remain_count=?, remain_amount=GREATEST(0, ROUND(remain_amount-?, 2)),'
                . ' status=?, finished_time=IF(?=2,?,finished_time), updatetime=?'
                . ' WHERE id=? AND status=1',
                [$remain, sprintf('%.2f', $amount), $status, $status, $now, $now, $packetId]
            );
            if ($affected <= 0) {
                throw new \RuntimeException('packet closed');
            }

            // 写领取明细（含尾数，供埋雷结算）
            Db::exec(
                'INSERT INTO ' . Db::table('chat_red_packet_records')
                . ' (packet_id,packet_no,user_id,amount,amount_cent,tail_digit,is_best,is_worst,is_mine_hit,createtime)'
                . ' VALUES (?,?,?,?,?,?,0,0,0,?)',
                [$packetId, $packetNo, $userId, sprintf('%.2f', $amount), $amountCent, $tailDigit, $now]
            );
            $recordId = (int)Db::lastId();

            $grabRemark = '红包入账';
            if ($packetType === 2) {
                $grabRemark = '红包拼手气入账';
            } elseif ($packetType === 3) {
                $grabRemark = '红包扫雷入账';
            } elseif ($packetType === 5) {
                $grabRemark = '红包接龙入账';
            }
            $walletChange = $this->wallet->change(
                $userId,
                $amount,
                'red_packet_grab',
                $grabRemark,
                ['biz_no' => $packetNo, 'ref_type' => 'red_packet', 'ref_id' => $packetId]
            );

            // 埋雷中雷：第一时间扣赔付（不冻结）
            if (
                $packetType === 3
                && (int)($packet['scope_type'] ?? 0) !== 1
                && $tailDigit === $mineDigit
                && $needCompensate > 0.00001
                && $recordId > 0
            ) {
                $pay = $this->payMineHitForRecord(
                    [
                        'id'           => $packetId,
                        'packet_no'    => $packetNo,
                        'from_user_id' => $fromUserId,
                    ],
                    [
                        'id'                => $recordId,
                        'user_id'           => $userId,
                        'freeze_status'     => 0,
                        'frozen_amount'     => 0,
                        'compensate_status' => 0,
                    ],
                    $needCompensate,
                    ['biz_no' => $packetNo, 'ref_type' => 'red_packet', 'ref_id' => $packetId]
                );
                $mineHit = !empty($pay['paid']) || !empty($pay['already']);
                $minePayAmount = round((float)($pay['amount'] ?? $needCompensate), 2);
            }

            // 拼手气：仅冻结够赔付额；埋雷不冻；接龙仅领到全局最少时冻续发额
            $frozenAmt = 0.0;
            if ($shouldFreeze && $packetType === 2 && $needCompensate > 0.00001) {
                $frozenAmt = round((float)$needCompensate, 2);
                $this->wallet->freeze(
                    $userId,
                    $frozenAmt,
                    'red_packet_freeze',
                    '红宝拼手气冻结',
                    ['biz_no' => $packetNo, 'ref_type' => 'red_packet', 'ref_id' => $packetId]
                );
                if ($recordId > 0) {
                    Db::exec(
                        'UPDATE ' . Db::table('chat_red_packet_records')
                        . ' SET frozen_amount=?, freeze_status=1 WHERE id=?',
                        [sprintf('%.2f', $frozenAmt), $recordId]
                    );
                }
            } elseif (
                $packetType === 5
                && (int)($packet['scope_type'] ?? 0) !== 1
                && $recordId > 0
            ) {
                $relayAmt = round((float)($packet['total_amount'] ?? 0), 2);
                $minCent = $this->packetMinCent($packet);
                if ($minCent <= 0) {
                    try {
                        $metaMin = RedisClient::conn()->hGet($metaKey, 'min_cent');
                        if ($metaMin !== false && $metaMin !== null && (string)$metaMin !== '') {
                            $minCent = (int)$metaMin;
                        }
                    } catch (\Throwable $eMin) {
                    }
                }
                if ($minCent > 0 && $amountCent === $minCent && $relayAmt > 0.00001) {
                    if (!$this->wallet->hasEnoughBalance($userId, $relayAmt)) {
                        throw new \RuntimeException('balance_not_enough_for_compensate:' . sprintf('%.2f', $relayAmt));
                    }
                    $frozenAmt = $relayAmt;
                    $this->wallet->freeze(
                        $userId,
                        $frozenAmt,
                        'red_packet_freeze',
                        '红宝接龙续发冻结',
                        ['biz_no' => $packetNo, 'ref_type' => 'red_packet', 'ref_id' => $packetId]
                    );
                    Db::exec(
                        'UPDATE ' . Db::table('chat_red_packet_records')
                        . ' SET frozen_amount=?, freeze_status=1, need_compensate=1, compensate_amount=? WHERE id=?',
                        [sprintf('%.2f', $frozenAmt), sprintf('%.2f', $frozenAmt), $recordId]
                    );
                }
            }

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            // MySQL 失败必须原子回滚 Redis 占坑，避免吞金额 / 双花占坑
            error_log('[RP_GRAB][ERROR] mysql fail rollback redis packet_id=' . $packetId . ' user=' . $userId . ' err=' . $e->getMessage());
            try {
                RedisClient::evalFile('grab_rollback.lua', [$queueKey, $grabbedKey], [
                    (string)$userId,
                    (string)$amountCent,
                ]);
            } catch (\Throwable $ignore) {
                error_log('[RP_GRAB][ERROR] redis rollback fail packet_id=' . $packetId . ' ' . $ignore->getMessage());
            }
            if (stripos($e->getMessage(), 'Duplicate') !== false || stripos($e->getMessage(), '1062') !== false) {
                throw new \RuntimeException('already grabbed');
            }
            throw $e;
        }

        $settleInfo = null;
        $nextRoundMessage = null;
        $settlePending = false;
        // ---------- 抢完最后一个包：结算/开奖异步，缩短抢包响应 ----------
        if ($remain <= 0) {
            // 扫雷/拼手气/接龙：领完后异步结算（解冻 + 赔付/抽水/返点）
            if (in_array($packetType, [1, 4], true)) {
                try {
                    $this->markBestLuck($packetId);
                } catch (\Throwable $eBest) {
                }
            } else {
                $this->scheduleSettleAfterFinished($packetId, $packet);
                $settlePending = true;
            }
        }

        try {
            RedisClient::conn()->incr(RedisClient::key('rp:detail:ver:' . $packetId));
            RedisClient::conn()->del(RedisClient::key('rp:cover:' . $packetId));
        } catch (\Throwable $e) {
        }

        $view = $this->wallet->getWalletView($userId, true);
        return [
            'packet_id'           => $packetId,
            'amount'              => $amount,
            'remain_count'        => $remain,
            'status'              => $status,
            'balance'             => $view['hongbao'],
            'hongbao_frozen'      => $view['hongbao_frozen'],
            'frozen_amount'       => isset($frozenAmt) ? round((float)$frozenAmt, 2) : 0.0,
            'tail_digit'          => $tailDigit,
            'is_mine_hit'         => $mineHit,
            'mine_digit'          => $packetType === 3 ? $mineDigit : null,
            'compensate_amount'   => $mineHit ? $minePayAmount : 0.0,
            'settlement'          => $settleInfo,
            'settle_pending'      => $settlePending,
            'next_round_message'  => $nextRoundMessage,
            'packet'              => [
                'scope_type'   => (int)($packet['scope_type'] ?? 0),
                'group_id'     => (int)($packet['group_id'] ?? 0),
                'from_user_id' => (int)($packet['from_user_id'] ?? 0),
                'to_user_id'   => (int)($packet['to_user_id'] ?? 0),
            ],
        ];
    }

    /**
     * 埋雷中雷赔付（幂等：compensate_status=2 跳过）。调用方须在事务内。
     *
     * @return array{paid:bool,already:bool,amount:float,ledger_id:int}
     */
    protected function payMineHitForRecord(array $packet, array $record, $compensateAmount, array $bizMeta = [])
    {
        $recordId = (int)($record['id'] ?? 0);
        $payerId = (int)($record['user_id'] ?? 0);
        $fromUserId = (int)($packet['from_user_id'] ?? 0);
        $packetId = (int)($packet['id'] ?? 0);
        $packetNo = (string)($packet['packet_no'] ?? '');
        $amt = round((float)$compensateAmount, 2);
        if ($recordId <= 0 || $payerId <= 0 || $fromUserId <= 0 || $amt <= 0.00001) {
            return ['paid' => false, 'already' => false, 'amount' => 0.0, 'ledger_id' => 0];
        }

        $fresh = Db::fetch(
            'SELECT id, user_id, compensate_status, freeze_status, frozen_amount, compensate_amount'
            . ' FROM ' . Db::table('chat_red_packet_records') . ' WHERE id=? LIMIT 1',
            [$recordId]
        );
        if (!$fresh) {
            return ['paid' => false, 'already' => false, 'amount' => 0.0, 'ledger_id' => 0];
        }
        if ((int)($fresh['compensate_status'] ?? 0) === 2) {
            return [
                'paid'    => false,
                'already' => true,
                'amount'  => round((float)($fresh['compensate_amount'] ?? $amt), 2),
                'ledger_id' => 0,
            ];
        }

        if ((int)($fresh['freeze_status'] ?? 0) === 1) {
            $freezeAmt = round((float)($fresh['frozen_amount'] ?? 0), 2);
            if ($freezeAmt > 0.00001) {
                $this->wallet->unfreeze(
                    $payerId,
                    $freezeAmt,
                    'red_packet_unfreeze',
                    '红宝扫雷解冻',
                    $bizMeta
                );
            }
            Db::exec(
                'UPDATE ' . Db::table('chat_red_packet_records')
                . ' SET freeze_status=2 WHERE id=?',
                [$recordId]
            );
        }

        $out = $this->wallet->change(
            $payerId,
            -$amt,
            'red_packet_mine_pay',
            '红宝扫雷赔付',
            $bizMeta
        );
        $ledgerOutId = (int)($out['ledger_id'] ?? 0);
        $this->wallet->change(
            $fromUserId,
            $amt,
            'red_packet_compensate_in',
            '红宝扫雷赔付入账',
            $bizMeta
        );
        Db::exec(
            'UPDATE ' . Db::table('chat_red_packet_records')
            . ' SET is_mine_hit=1, need_compensate=1, compensate_amount=?, compensate_status=2, compensate_ledger_id=?'
            . ' WHERE id=?',
            [sprintf('%.2f', $amt), $ledgerOutId, $recordId]
        );
        Db::exec(
            'INSERT INTO ' . Db::table('chat_red_packet_settlements')
            . ' (packet_id,packet_no,settle_type,from_user_id,to_user_id,amount,ledger_id,status,remark,createtime)'
            . ' VALUES (?,?,?,?,?,?,?,?,?,?)',
            [
                $packetId,
                $packetNo,
                'compensate',
                $payerId,
                $fromUserId,
                sprintf('%.2f', $amt),
                $ledgerOutId,
                1,
                '红宝扫雷赔付',
                time(),
            ]
        );
        error_log(sprintf(
            '[RP_MINE] pay ok packet_id=%d record=%d payer=%d amount=%.2f',
            $packetId,
            $recordId,
            $payerId,
            $amt
        ));
        return ['paid' => true, 'already' => false, 'amount' => $amt, 'ledger_id' => $ledgerOutId];
    }

    /**
     * 抢完后异步结算（赔付/抽水/返点/机器人续发/波场证明）
     * 不阻塞 redpacket.grabbed；worker0 5s 轮询仍作兜底。
     */
    public function scheduleSettleAfterFinished($packetId, array $packetHint = [])
    {
        $packetId = (int)$packetId;
        if ($packetId <= 0) {
            return false;
        }
        $lockKey = RedisClient::key('rp:' . $packetId . ':settle_sched');
        try {
            if (!RedisClient::conn()->set($lockKey, (string)time(), ['nx', 'ex' => 30])) {
                return false;
            }
        } catch (\Throwable $e) {
        }
        $hint = [
            'packet_type'  => (int)($packetHint['packet_type'] ?? 0),
            'scope_type'   => (int)($packetHint['scope_type'] ?? 0),
            'group_id'     => (int)($packetHint['group_id'] ?? 0),
            'from_user_id' => (int)($packetHint['from_user_id'] ?? 0),
            'to_user_id'   => (int)($packetHint['to_user_id'] ?? 0),
            'total_amount' => (float)($packetHint['total_amount'] ?? 0),
            'total_count'  => (int)($packetHint['total_count'] ?? 0),
            'blessing'     => (string)($packetHint['blessing'] ?? ''),
            'id'           => $packetId,
        ];
        try {
            Timer::add(0.05, function () use ($packetId, $hint) {
                $this->runSettleAfterFinished($packetId, $hint);
            }, [], false);
            return true;
        } catch (\Throwable $e) {
            error_log('[RP_SETTLE] Timer::add fail packet=' . $packetId . ' ' . $e->getMessage());
            // Timer 不可用时同步兜底，避免只靠 5s 轮询
            $this->runSettleAfterFinished($packetId, $hint);
            return true;
        }
    }

    protected function runSettleAfterFinished($packetId, array $hint = [])
    {
        $packetId = (int)$packetId;
        $packetType = (int)($hint['packet_type'] ?? 0);
        if ($packetType <= 0 || (int)($hint['scope_type'] ?? 0) <= 0) {
            $row = Db::fetch(
                'SELECT id, packet_type, scope_type, group_id, from_user_id, to_user_id, total_amount, total_count, blessing'
                . ' FROM ' . Db::table('chat_red_packets') . ' WHERE id=? LIMIT 1',
                [$packetId]
            );
            if ($row) {
                $hint = array_merge($hint, $row);
                $packetType = (int)$row['packet_type'];
            }
        }
        try {
            if ($packetType === 1) {
                $this->markBestLuck($packetId);
            }
            if ($packetType === 3) {
                // 扫雷：发包已写入波场哈希拆包；抢完即可按手填雷号结算
                $row = Db::fetch(
                    'SELECT id, status, tron_status, remain_count, scope_type, group_id, from_user_id, to_user_id, packet_type'
                    . ' FROM ' . Db::table('chat_red_packets') . ' WHERE id=? LIMIT 1',
                    [$packetId]
                );
                if ($row && (int)($row['remain_count'] ?? 1) <= 0 && (int)($row['tron_status'] ?? 0) === 2) {
                    $settleInfo = (new RedPacketSettlementService($this->wallet, [
                        'red_packet' => $this->cfg,
                    ]))->settleAfterFinished($packetId);
                    if (!empty($settleInfo['settled'])) {
                        error_log('[RP_SETTLE] async mine ok packet_id=' . $packetId);
                        $this->notifySettled($packetId, array_merge($hint, $row), $settleInfo);
                    }
                } else {
                    // 旧包兜底：尚未写入哈希时再调度开奖
                    $this->revealFairProof($packetId);
                }
                return;
            }
            $settleInfo = (new RedPacketSettlementService($this->wallet, [
                'red_packet' => $this->cfg,
            ]))->settleAfterFinished($packetId);
            $settled = !empty($settleInfo['settled']);
            if ($settled) {
                error_log('[RP_SETTLE] async ok packet_id=' . $packetId);
            }
            if ($settled && $packetType === 5 && (int)($hint['scope_type'] ?? 0) === 2) {
                $full = $hint;
                if (empty($full['blessing']) || empty($full['total_amount']) || empty($full['total_count'])) {
                    $row = Db::fetch('SELECT * FROM ' . Db::table('chat_red_packets') . ' WHERE id=? LIMIT 1', [$packetId]);
                    if ($row) {
                        $full = $row;
                    }
                }
                // 全局：任意群接龙抢完 → 最少者名义续发
                error_log(sprintf(
                    '[RP_RELAY] settle trigger next group=%d packet=%d',
                    (int)($full['group_id'] ?? 0),
                    $packetId
                ));
                $this->trySendRobotNextRound($full);
            }
            if ($packetType === 2 || $packetType === 5) {
                $this->revealFairProof($packetId);
            }
            if ($settled) {
                $this->notifySettled($packetId, $hint, $settleInfo);
            }
        } catch (\Throwable $e) {
            error_log('[RP_SETTLE][ERROR] async fail packet_id=' . $packetId . ' err=' . $e->getMessage());
        } finally {
            try {
                RedisClient::conn()->del(RedisClient::key('rp:' . $packetId . ':settle_sched'));
            } catch (\Throwable $e) {
            }
        }
    }

    protected function notifySettled($packetId, array $hint, array $settleInfo)
    {
        $packetId = (int)$packetId;
        $event = [
            'packet_id' => $packetId,
            'settled'   => true,
            'settlement'=> [
                'settled'          => true,
                'compensate_users' => $settleInfo['compensate_users'] ?? [],
                'platform_fee'     => $settleInfo['platform_fee'] ?? 0,
                'agent_rebate'     => $settleInfo['agent_rebate'] ?? 0,
            ],
        ];
        try {
            if ((int)($hint['scope_type'] ?? 0) === 2 && (int)($hint['group_id'] ?? 0) > 0) {
                $uids = $this->groups->onlineMemberIds((int)$hint['group_id']);
                if ($uids) {
                    PushBus::toUsers($uids, 'redpacket.update', $event);
                }
                // 埋雷结算后群内公示中雷结果，所有人可见
                if ((int)($hint['packet_type'] ?? 0) === 3) {
                    $packet = Db::fetch(
                        'SELECT mine_digit FROM ' . Db::table('chat_red_packets') . ' WHERE id=? LIMIT 1',
                        [$packetId]
                    );
                    $mineDigit = (int)($packet['mine_digit'] ?? 0);
                    $hits = Db::fetchAll(
                        'SELECT user_id FROM ' . Db::table('chat_red_packet_records')
                        . ' WHERE packet_id=? AND is_mine_hit=1 ORDER BY id ASC',
                        [$packetId]
                    );
                    $hitUids = array_map(function ($r) {
                        return (int)$r['user_id'];
                    }, $hits ?: []);
                    if ($hitUids) {
                        $authBrief = new AuthService([]);
                        $briefs = $authBrief->usersBriefMap($hitUids);
                        $names = [];
                        foreach ($hitUids as $hid) {
                            $names[] = $authBrief->displayNameFromBrief($briefs[$hid] ?? null, $hid);
                        }
                        $text = '埋雷结算：雷号 ' . $mineDigit . '（哈希末位已匹配） · 中雷 ' . count($hitUids) . ' 人（'
                            . implode('、', $names) . '）';
                    } else {
                        $text = '埋雷结算：雷号 ' . $mineDigit . '（哈希末位已匹配） · 本局无人中雷';
                    }
                    $sys = $this->messages->sendGroupSystem((int)$hint['group_id'], $text, 0, [
                        'packet_id'  => $packetId,
                        'mine_digit' => $mineDigit,
                        'hit_count'  => count($hitUids),
                        'kind'       => 'mine_settle',
                    ]);
                    if ($uids && is_array($sys)) {
                        PushBus::toUsers($uids, 'group.message', ['message' => $sys]);
                    }
                }
            } else {
                $uids = array_values(array_unique(array_filter([
                    (int)($hint['from_user_id'] ?? 0),
                    (int)($hint['to_user_id'] ?? 0),
                ])));
                if ($uids) {
                    PushBus::toUsers($uids, 'redpacket.update', $event);
                }
            }
        } catch (\Throwable $e) {
            error_log('[RP_SETTLE] notifySettled fail packet=' . $packetId . ' ' . $e->getMessage());
        }
    }

    /**
     * 接龙续发（全局）：监听全部群、全部 type=5 接龙红包。
     * 任一包抢完并结算后，由「抢最少」用户扣余额发下一包（流水记在其名下）。
     * 与「机器人自动抢包」无关；不依赖机器人是否在群内。
     *
     * @return array|null
     */
    public function trySendRobotNextRound(array $packet)
    {
        $groupId = (int)($packet['group_id'] ?? 0);
        $packetId = (int)($packet['id'] ?? 0);
        if ($groupId <= 0) {
            return null;
        }
        // 仅接龙包(5)续发；拼手气(2)改为结算时直接赔付发包人
        if ((int)($packet['packet_type'] ?? 0) !== 5) {
            return null;
        }
        if ((int)($packet['scope_type'] ?? 0) !== 2) {
            return null;
        }

        // 扣款+发包主体 = 手气最差；找不到则不续发
        $senderUid = $this->findWorstGrabberUserId($packetId);
        if ($senderUid <= 0) {
            error_log('[RP_RELAY] skip next round: no worst grabber packet_id=' . $packetId . ' group=' . $groupId);
            return null;
        }

        $amount = round((float)($packet['total_amount'] ?? 0), 2);
        $count = (int)($packet['total_count'] ?? 0);
        try {
            $g = $this->groups->get($groupId);
            $fixed = round((float)($g['rp_fixed_amount'] ?? 0), 2);
            if ($fixed > 0) {
                $amount = $fixed;
            }
        } catch (\Throwable $eFix) {
        }
        if ($amount <= 0 || $count <= 0) {
            return null;
        }

        // 群内还有待领包则不续发
        try {
            $open = Db::fetch(
                'SELECT COUNT(*) AS c FROM ' . Db::table('chat_red_packets')
                . ' WHERE group_id=? AND scope_type=2 AND status=1 AND remain_count>0',
                [$groupId]
            );
            if ((int)($open['c'] ?? 0) > 0) {
                $this->markRelayRetry($packetId, 'open_packets');
                return null;
            }
        } catch (\Throwable $e) {
        }

        $bal = $this->wallet->getBalance($senderUid, true);
        if ($bal + 0.00001 < $amount) {
            error_log(sprintf(
                '[RP_RELAY][ALERT] pending: worst balance=%.2f need=%.2f uid=%d group=%d packet_id=%d',
                $bal,
                $amount,
                $senderUid,
                $groupId,
                $packetId
            ));
            $this->markRelayRetry($packetId, 'balance_not_enough');
            return null;
        }

        // 先占住「待续发」，避免自动任务在延迟窗口插队发包
        $this->markRelayRetry($packetId, 'scheduled');

        $doSend = function () use ($groupId, $senderUid, $amount, $count, $packet, $packetId) {
            try {
                $open = Db::fetch(
                    'SELECT COUNT(*) AS c FROM ' . Db::table('chat_red_packets')
                    . ' WHERE group_id=? AND scope_type=2 AND status=1 AND remain_count>0',
                    [$groupId]
                );
                if ((int)($open['c'] ?? 0) > 0) {
                    error_log('[RP_RELAY] defer: open packets remain group=' . $groupId . ' from_packet=' . $packetId);
                    $this->markRelayRetry($packetId, 'open_packets');
                    return null;
                }
                if (!$this->groups->isMember($groupId, $senderUid)) {
                    $this->groups->addMembers($groupId, [$senderUid], 1);
                }
                // 续发前：解冻最少者已锁定的续发额（只冻够赔付/续发的金额）
                $worstRec = Db::fetch(
                    'SELECT id, freeze_status, frozen_amount FROM ' . Db::table('chat_red_packet_records')
                    . ' WHERE packet_id=? AND is_worst=1 LIMIT 1',
                    [$packetId]
                );
                if ($worstRec && (int)($worstRec['freeze_status'] ?? 0) === 1) {
                    $uf = round((float)($worstRec['frozen_amount'] ?? 0), 2);
                    if ($uf > 0.00001) {
                        $this->wallet->unfreeze(
                            $senderUid,
                            $uf,
                            'red_packet_unfreeze',
                            '红宝接龙续发解冻',
                            ['biz_no' => (string)($packet['packet_no'] ?? ''), 'ref_type' => 'red_packet', 'ref_id' => $packetId]
                        );
                    }
                    Db::exec(
                        'UPDATE ' . Db::table('chat_red_packet_records')
                        . ' SET freeze_status=2 WHERE id=?',
                        [(int)$worstRec['id']]
                    );
                }
                $result = $this->send([
                    'from_user_id' => $senderUid,
                    'scope_type'   => 2,
                    'group_id'     => $groupId,
                    'packet_type'  => 5,
                    'total_amount' => $amount,
                    'total_count'  => $count,
                    'blessing'     => (string)(($packet['blessing'] ?? '') !== '' ? $packet['blessing'] : '红宝接龙'),
                    'robot_send'   => true,
                    'trusted_robot'=> true,
                    'robot_relay'  => true,
                ]);
                // 标记上一包最差赔付已通过「续发扣款」完成（流水=red_packet_send，user=最差）
                try {
                    Db::exec(
                        'UPDATE ' . Db::table('chat_red_packet_records')
                        . ' SET compensate_status=2 WHERE packet_id=? AND is_worst=1 AND compensate_status=1',
                        [$packetId]
                    );
                } catch (\Throwable $eMark) {
                }
                $this->clearRelayRetry($packetId);
                $msg = $result['message'] ?? null;
                if (is_array($msg)) {
                    try {
                        $uids = $this->groups->pushTargetUserIds($groupId);
                        if ($uids) {
                            \Im\Support\PushBus::toUsers($uids, 'group.message', ['message' => $msg]);
                        }
                    } catch (\Throwable $e) {
                        error_log('[RP_RELAY] push fail group=' . $groupId . ' ' . $e->getMessage());
                    }
                }
                error_log(sprintf(
                    '[RP_RELAY] next round sent group=%d amount=%.2f count=%d worst_sender=%d from_packet=%d',
                    $groupId,
                    $amount,
                    $count,
                    $senderUid,
                    $packetId
                ));
                return is_array($msg) ? $msg : null;
            } catch (\Throwable $e) {
                error_log('[RP_RELAY][ALERT] next round fail group=' . $groupId . ' packet=' . $packetId . ' err=' . $e->getMessage());
                $this->markRelayRetry($packetId, 'send_fail:' . $e->getMessage());
                return null;
            }
        };

        // 延迟 2～5 秒续发，更像真人节奏；失败由 cron 重试
        $delay = 2.0 + (mt_rand(0, 3000) / 1000.0);
        try {
            Timer::add($delay, function () use ($doSend) {
                $doSend();
            }, [], false);
            return ['scheduled' => true, 'delay' => $delay, 'sender_uid' => $senderUid];
        } catch (\Throwable $e) {
            return $doSend();
        }
    }

    /** 接龙续发待重试标记（Redis） */
    protected function markRelayRetry($packetId, $reason = '')
    {
        $packetId = (int)$packetId;
        if ($packetId <= 0) {
            return;
        }
        try {
            $key = RedisClient::key('rp:relay_retry:' . $packetId);
            $r = RedisClient::conn();
            $r->hMSet($key, [
                'packet_id' => (string)$packetId,
                'reason'    => mb_substr((string)$reason, 0, 120),
                'at'        => (string)time(),
                'tries'     => (string)((int)$r->hGet($key, 'tries') + 1),
            ]);
            $r->expire($key, 86400 * 3);
            $r->sAdd(RedisClient::key('rp:relay_retry_set'), (string)$packetId);
        } catch (\Throwable $e) {
            error_log('[RP_ROBOT] markRelayRetry fail packet=' . $packetId . ' ' . $e->getMessage());
        }
    }

    protected function clearRelayRetry($packetId)
    {
        $packetId = (int)$packetId;
        if ($packetId <= 0) {
            return;
        }
        try {
            $r = RedisClient::conn();
            $r->del(RedisClient::key('rp:relay_retry:' . $packetId));
            $r->sRem(RedisClient::key('rp:relay_retry_set'), (string)$packetId);
        } catch (\Throwable $e) {
        }
    }

    /**
     * 接龙：全局扫描全部群中「已结算、最少者尚未续发」的包并重试。
     * @return int 成功续发数
     */
    public function retryPendingRelayRounds($limit = 20)
    {
        $limit = max(1, min(50, (int)$limit));
        $done = 0;
        $ids = [];
        try {
            $ids = RedisClient::conn()->sMembers(RedisClient::key('rp:relay_retry_set')) ?: [];
        } catch (\Throwable $e) {
            $ids = [];
        }
        // 库内兜底：不限群 —— 所有已结算接龙、最差仍待续发
        $rows = Db::fetchAll(
            'SELECT p.id FROM ' . Db::table('chat_red_packets') . ' p'
            . ' INNER JOIN ' . Db::table('chat_red_packet_records') . ' r ON r.packet_id=p.id AND r.is_worst=1'
            . ' WHERE p.packet_type=5 AND p.scope_type=2 AND p.status=5'
            . ' AND r.compensate_status=1'
            . ' ORDER BY p.id ASC LIMIT ' . $limit
        );
        foreach ($rows ?: [] as $row) {
            $ids[] = (string)(int)$row['id'];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $ids = array_slice($ids, 0, $limit);
        foreach ($ids as $packetId) {
            try {
                $full = Db::fetch(
                    'SELECT * FROM ' . Db::table('chat_red_packets') . ' WHERE id=? LIMIT 1',
                    [$packetId]
                );
                if (!$full || (int)$full['packet_type'] !== 5 || (int)$full['status'] !== 5) {
                    $this->clearRelayRetry($packetId);
                    continue;
                }
                $worst = Db::fetch(
                    'SELECT id, compensate_status FROM ' . Db::table('chat_red_packet_records')
                    . ' WHERE packet_id=? AND is_worst=1 LIMIT 1',
                    [$packetId]
                );
                if (!$worst || (int)($worst['compensate_status'] ?? 0) === 2) {
                    $this->clearRelayRetry($packetId);
                    continue;
                }
                $ret = $this->trySendRobotNextRound($full);
                if ($ret !== null && empty($ret['scheduled'])) {
                    // 同步成功或已安排；scheduled 时等 Timer
                    $check = Db::fetch(
                        'SELECT compensate_status FROM ' . Db::table('chat_red_packet_records')
                        . ' WHERE packet_id=? AND is_worst=1 LIMIT 1',
                        [$packetId]
                    );
                    if ($check && (int)($check['compensate_status'] ?? 0) === 2) {
                        $done++;
                    }
                }
            } catch (\Throwable $e) {
                error_log('[RP_ROBOT][ALERT] retryPendingRelayRounds packet=' . $packetId . ' ' . $e->getMessage());
            }
        }
        return $done;
    }

    /**
     * 拼手气过期：分笔收回已领；余额不足部分记欠款结算单（status=0），避免整单卡死。
     * @return float 实际收回金额
     */
    protected function clawbackGrabPartial($uid, $amount, $packetId, $packetNo, $fromUserId, array $bizMeta, $now)
    {
        $uid = (int)$uid;
        $amount = round((float)$amount, 2);
        $packetId = (int)$packetId;
        $fromUserId = (int)$fromUserId;
        if ($uid <= 0 || $amount <= 0.00001) {
            return 0.0;
        }
        $avail = 0.0;
        try {
            $avail = $this->wallet->getBalance($uid, true);
        } catch (\Throwable $e) {
            $avail = 0.0;
        }
        $take = round(min($amount, max(0.0, $avail)), 2);
        $debt = round($amount - $take, 2);
        $ledgerId = 0;
        if ($take > 0.00001) {
            try {
                $outCb = $this->wallet->change(
                    $uid,
                    -$take,
                    'red_packet_expire_clawback',
                    '未领完此包作废收回金额',
                    $bizMeta
                );
                $ledgerId = (int)($outCb['ledger_id'] ?? 0);
                Db::exec(
                    'INSERT INTO ' . Db::table('chat_red_packet_settlements')
                    . ' (packet_id,packet_no,settle_type,from_user_id,to_user_id,amount,ledger_id,status,remark,createtime)'
                    . ' VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [
                        $packetId,
                        $packetNo,
                        'expire_clawback',
                        $uid,
                        $fromUserId,
                        sprintf('%.2f', $take),
                        $ledgerId,
                        1,
                        $debt > 0.00001 ? ('未领完此包作废收回金额(分笔 ' . sprintf('%.2f', $take) . ')') : '未领完此包作废收回金额',
                        $now,
                    ]
                );
            } catch (\Throwable $e) {
                error_log(sprintf(
                    '[RP_EXPIRE][ALERT] clawback fail uid=%d want=%.2f err=%s — record full debt',
                    $uid,
                    $amount,
                    $e->getMessage()
                ));
                $debt = $amount;
                $take = 0.0;
            }
        }
        if ($debt > 0.00001) {
            error_log(sprintf(
                '[RP_EXPIRE][ALERT] clawback debt uid=%d packet=%d taken=%.2f debt=%.2f',
                $uid,
                $packetId,
                $take,
                $debt
            ));
            Db::exec(
                'INSERT INTO ' . Db::table('chat_red_packet_settlements')
                . ' (packet_id,packet_no,settle_type,from_user_id,to_user_id,amount,ledger_id,status,remark,createtime)'
                . ' VALUES (?,?,?,?,?,?,?,?,?,?)',
                [
                    $packetId,
                    $packetNo,
                    'expire_clawback_debt',
                    $uid,
                    $fromUserId,
                    sprintf('%.2f', $debt),
                    0,
                    0,
                    '未领完此包作废收回金额(欠款待收)',
                    $now,
                ]
            );
        }
        return $take;
    }

    /**
     * 收回拼手气过期欠款（status=0 的 expire_clawback_debt）。
     * @return int 成功笔数
     */
    public function collectExpireClawbackDebts($limit = 30)
    {
        $limit = max(1, min(100, (int)$limit));
        $rows = Db::fetchAll(
            'SELECT * FROM ' . Db::table('chat_red_packet_settlements')
            . " WHERE settle_type='expire_clawback_debt' AND status=0 AND amount>0"
            . ' ORDER BY id ASC LIMIT ' . $limit
        );
        $done = 0;
        foreach ($rows ?: [] as $row) {
            $id = (int)$row['id'];
            $uid = (int)$row['from_user_id'];
            $toUid = (int)$row['to_user_id'];
            $need = round((float)$row['amount'], 2);
            $packetId = (int)$row['packet_id'];
            $packetNo = (string)$row['packet_no'];
            if ($id <= 0 || $uid <= 0 || $need <= 0.00001) {
                continue;
            }
            try {
                Db::begin();
                $fresh = Db::fetch(
                    'SELECT * FROM ' . Db::table('chat_red_packet_settlements') . ' WHERE id=? FOR UPDATE',
                    [$id]
                );
                if (!$fresh || (int)$fresh['status'] !== 0) {
                    Db::rollBack();
                    continue;
                }
                $need = round((float)$fresh['amount'], 2);
                $avail = $this->wallet->getBalance($uid, true);
                $take = round(min($need, max(0.0, $avail)), 2);
                if ($take <= 0.00001) {
                    Db::rollBack();
                    continue;
                }
                $bizMeta = ['biz_no' => $packetNo, 'ref_type' => 'red_packet', 'ref_id' => $packetId];
                $out = $this->wallet->change(
                    $uid,
                    -$take,
                    'red_packet_expire_clawback',
                    '未领完此包作废收回金额',
                    $bizMeta
                );
                if ($toUid > 0) {
                    $this->wallet->change(
                        $toUid,
                        $take,
                        'red_packet_refund',
                        '未领完此包作废收回金额',
                        $bizMeta
                    );
                }
                $left = round($need - $take, 2);
                if ($left <= 0.00001) {
                    Db::exec(
                        'UPDATE ' . Db::table('chat_red_packet_settlements')
                        . ' SET status=1, amount=?, ledger_id=?, remark=? WHERE id=?',
                        [
                            sprintf('%.2f', $take),
                            (int)($out['ledger_id'] ?? 0),
                            '未领完此包作废收回金额',
                            $id,
                        ]
                    );
                } else {
                    Db::exec(
                        'UPDATE ' . Db::table('chat_red_packet_settlements')
                        . ' SET amount=?, remark=? WHERE id=?',
                        [
                            sprintf('%.2f', $left),
                            '未领完此包作废收回金额(已收 ' . sprintf('%.2f', $take) . ')',
                            $id,
                        ]
                    );
                    Db::exec(
                        'INSERT INTO ' . Db::table('chat_red_packet_settlements')
                        . ' (packet_id,packet_no,settle_type,from_user_id,to_user_id,amount,ledger_id,status,remark,createtime)'
                        . ' VALUES (?,?,?,?,?,?,?,?,?,?)',
                        [
                            $packetId,
                            $packetNo,
                            'expire_clawback',
                            $uid,
                            $toUid,
                            sprintf('%.2f', $take),
                            (int)($out['ledger_id'] ?? 0),
                            1,
                            '未领完此包作废收回金额',
                            time(),
                        ]
                    );
                }
                Db::commit();
                $done++;
                error_log(sprintf(
                    '[RP_EXPIRE] debt collect ok settle_id=%d uid=%d take=%.2f left=%.2f',
                    $id,
                    $uid,
                    $take,
                    max(0, $left)
                ));
            } catch (\Throwable $e) {
                try {
                    Db::rollBack();
                } catch (\Throwable $e2) {
                }
                error_log('[RP_EXPIRE][ALERT] debt collect fail id=' . $id . ' ' . $e->getMessage());
            }
        }
        return $done;
    }

    /** 手气最差抢包用户（is_worst=1） */
    protected function findWorstGrabberUserId($packetId)
    {
        $packetId = (int)$packetId;
        if ($packetId <= 0) {
            return 0;
        }
        $row = Db::fetch(
            'SELECT user_id FROM ' . Db::table('chat_red_packet_records')
            . ' WHERE packet_id=? AND is_worst=1 ORDER BY id ASC LIMIT 1',
            [$packetId]
        );
        if ($row) {
            return (int)($row['user_id'] ?? 0);
        }
        // 兜底：按金额最小
        $row = Db::fetch(
            'SELECT user_id FROM ' . Db::table('chat_red_packet_records')
            . ' WHERE packet_id=? ORDER BY amount ASC, id ASC LIMIT 1',
            [$packetId]
        );
        return $row ? (int)($row['user_id'] ?? 0) : 0;
    }

    /**
     * 过期退回：发出 1 分钟后未抢完 → 剩余金额原路退回发包方。
     * 由 Workerman Timer 每 5 秒扫描（worker0），幂等依赖 status/expire_status。
     * @return int 处理单数
     */
    public function refundExpired($limit = 50)
    {
        $limit = max(1, min(200, (int)$limit));
        $now = time();
        $rows = Db::fetchAll(
            'SELECT * FROM ' . Db::table('chat_red_packets')
            . ' WHERE status=1 AND expire_status=0 AND expiretime>0 AND expiretime<?'
            . ' ORDER BY id ASC LIMIT ' . $limit,
            [$now]
        );
        $done = 0;
        foreach ($rows as $packet) {
            try {
                $this->refundOne($packet);
                $done++;
                error_log('[RP_EXPIRE] refunded packet_id=' . (int)$packet['id'] . ' no=' . $packet['packet_no']);
            } catch (\Throwable $e) {
                error_log('[RP_EXPIRE][ERROR] packet_id=' . (int)$packet['id'] . ' ' . $e->getMessage());
            }
        }
        return $done;
    }

    /**
     * 抢完但结算失败（status=2）的补偿重试：中雷/手续费/返点。
     * @return int 成功结算数
     */
    public function retryPendingSettlements($limit = 30)
    {
        $limit = max(1, min(100, (int)$limit));
        $rows = Db::fetchAll(
            'SELECT id, packet_type, tron_status FROM ' . Db::table('chat_red_packets')
            . ' WHERE status=2 AND remain_count<=0'
            . ' ORDER BY id ASC LIMIT ' . $limit
        );
        $done = 0;
        $settler = new RedPacketSettlementService($this->wallet, ['red_packet' => $this->cfg]);
        foreach ($rows as $row) {
            $packetId = (int)$row['id'];
            $ptype = (int)$row['packet_type'];
            try {
                $info = $settler->settleAfterFinished($packetId);
                if (!empty($info['settled'])) {
                    $done++;
                    error_log('[RP_SETTLE_RETRY] ok packet_id=' . $packetId);
                    if ($ptype === 5) {
                        $full = Db::fetch(
                            'SELECT * FROM ' . Db::table('chat_red_packets') . ' WHERE id=? LIMIT 1',
                            [$packetId]
                        );
                        if ($full && (int)($full['scope_type'] ?? 0) === 2) {
                            $this->trySendRobotNextRound($full);
                        }
                    }
                    if ($ptype === 2 || $ptype === 5) {
                        $this->revealFairProof($packetId);
                    }
                    if ($ptype === 3) {
                        $this->notifySettled($packetId, $row, $info);
                    }
                }
                if ($ptype === 1) {
                    $this->markBestLuck($packetId);
                }
                if ($ptype === 2 && (int)($row['tron_status'] ?? 0) !== 2) {
                    $this->revealFairProof($packetId);
                }
            } catch (\Throwable $e) {
                error_log('[RP_SETTLE_RETRY][ERROR] packet_id=' . $packetId . ' ' . $e->getMessage());
            }
        }
        return $done;
    }

    /**
     * 单包过期退回（事务）：
     * - 拼手气(2)群包：收回全部已领 + 剩余池一并退庄家（进包本金全退；无人领时另退手续费）
     * - 接龙(5)：已领保留；仅退剩余未领池给发包手；接龙中断不再续发（默认 30 分钟）
     * - 其它类型：仅退剩余未领；已领保留
     * 成功后再清 Redis
     */
    protected function refundOne(array $packet)
    {
        $packetId = (int)$packet['id'];
        $queueKey = RedisClient::key('rp:' . $packetId . ':queue');
        $grabbedKey = RedisClient::key('rp:' . $packetId . ':grabbed');
        $metaKey = RedisClient::key('rp:' . $packetId . ':meta');

        // 先读 Redis 剩余（事务成功后再删，避免 DB 失败丢队列）
        $refundCent = 0;
        $hasRedisLeft = false;
        try {
            $r = RedisClient::conn();
            $left = $r->lRange($queueKey, 0, -1);
            if (is_array($left) && $left) {
                $hasRedisLeft = true;
                foreach ($left as $c) {
                    $refundCent += (int)$c;
                }
            }
        } catch (\Throwable $e) {
            $refundCent = 0;
        }
        if ($refundCent <= 0) {
            $refundCent = (int)round((float)$packet['remain_amount'] * 100);
        }
        $refund = round($refundCent / 100, 2);
        $now = time();
        $isRelayExpire = false;
        $fresh = null;
        $packetNo = (string)($packet['packet_no'] ?? '');

        Db::begin();
        try {
            $fresh = Db::fetch(
                'SELECT * FROM ' . Db::table('chat_red_packets') . ' WHERE id=? FOR UPDATE',
                [$packetId]
            );
            if (!$fresh || (int)$fresh['status'] !== 1 || (int)($fresh['expire_status'] ?? 0) === 1) {
                Db::rollBack();
                return;
            }
            // 已在过期瞬间被抢完：交给结算，不再退回
            if ((int)$fresh['remain_count'] <= 0) {
                Db::rollBack();
                return;
            }
            // 库内 remain 更保守时，取较小者避免超退
            $dbRemain = round((float)$fresh['remain_amount'], 2);
            if ($dbRemain > 0 && ($refund <= 0 || $refund > $dbRemain)) {
                $refund = $dbRemain;
            }

            $packetNo = (string)$fresh['packet_no'];
            $fromUserId = (int)$fresh['from_user_id'];
            $packetType = (int)($fresh['packet_type'] ?? 0);
            $scopeType = (int)($fresh['scope_type'] ?? 0);
            $bizMeta = ['biz_no' => $packetNo, 'ref_type' => 'red_packet', 'ref_id' => $packetId];
            // 拼手气群包：超时作废 → 已领全收回 + 进包本金退庄家
            // 接龙群包：超时结束 → 已领保留，只退剩余未领（禁止 clawback）
            $luckyClawback = ($packetType === 2 && $scopeType !== 1);
            $isRelayExpire = ($packetType === 5 && $scopeType !== 1);

            $records = Db::fetchAll(
                'SELECT * FROM ' . Db::table('chat_red_packet_records')
                . ' WHERE packet_id=? FOR UPDATE',
                [$packetId]
            ) ?: [];

            $clawed = 0.0;
            if ($luckyClawback && $records) {
                foreach ($records as $rec) {
                    $uid = (int)($rec['user_id'] ?? 0);
                    $amt = round((float)($rec['amount'] ?? 0), 2);
                    $rid = (int)($rec['id'] ?? 0);
                    $freezeAmt = 0.0;
                    if ((int)($rec['freeze_status'] ?? 0) === 1) {
                        $freezeAmt = round((float)($rec['frozen_amount'] ?? 0), 2);
                    }
                    if ($uid > 0 && $freezeAmt > 0.00001) {
                        try {
                            $this->wallet->unfreeze(
                                $uid,
                                $freezeAmt,
                                'red_packet_unfreeze',
                                '红宝拼手气解冻',
                                $bizMeta
                            );
                        } catch (\Throwable $eUf) {
                            error_log('[RP_EXPIRE][WARN] unfreeze fail uid=' . $uid . ' ' . $eUf->getMessage());
                        }
                        if ($rid > 0) {
                            Db::exec(
                                'UPDATE ' . Db::table('chat_red_packet_records')
                                . ' SET freeze_status=2 WHERE id=?',
                                [$rid]
                            );
                        }
                    }
                    if ($uid > 0 && $amt > 0.00001) {
                        $taken = $this->clawbackGrabPartial(
                            $uid,
                            $amt,
                            $packetId,
                            $packetNo,
                            $fromUserId,
                            $bizMeta,
                            $now
                        );
                        $clawed = round($clawed + $taken, 2);
                    }
                }
                $refund = round($refund + $clawed, 2);
            }

            // 埋雷超时：先补结未扣的中雷（未中雷已领保留），再退剩余池
            if ($packetType === 3 && $scopeType !== 1 && $records) {
                $mineDigit = max(0, min(9, (int)($fresh['mine_digit'] ?? 0)));
                $minePay = $this->mineCompensateAmount(
                    round((float)($fresh['total_amount'] ?? 0), 2),
                    (int)($fresh['total_count'] ?? 0)
                );
                foreach ($records as $rec) {
                    $cent = (int)($rec['amount_cent'] ?? round((float)($rec['amount'] ?? 0) * 100));
                    $tail = (int)($rec['tail_digit'] ?? ($cent % 10));
                    if ($tail !== $mineDigit) {
                        continue;
                    }
                    $this->payMineHitForRecord(
                        [
                            'id'           => $packetId,
                            'packet_no'    => $packetNo,
                            'from_user_id' => $fromUserId,
                        ],
                        $rec,
                        $minePay,
                        $bizMeta
                    );
                }
            }

            $neverGrabbed = ((int)$fresh['remain_count'] === (int)$fresh['total_count']) && !$records;
            if ($luckyClawback && $records) {
                $neverGrabbed = false;
            }
            $feeRefund = 0.0;
            if ($neverGrabbed) {
                $feeRefund = round((float)($fresh['platform_fee'] ?? 0), 2);
            }
            $platformUserId = (int)($this->cfg['platform_user_id'] ?? 0);
            $ledgerId = 0;
            $totalRefund = $refund;
            if ($refund > 0) {
                if ($luckyClawback && $clawed > 0.00001) {
                    $remarkRefund = '未领完此包作废收回金额';
                } elseif (!empty($isRelayExpire)) {
                    $remarkRefund = '接龙超时退回未领金额';
                } else {
                    $remarkRefund = '红包过期退回';
                }
                $out = $this->wallet->change(
                    $fromUserId,
                    $refund,
                    'red_packet_refund',
                    $remarkRefund,
                    $bizMeta
                );
                $ledgerId = (int)($out['ledger_id'] ?? 0);
            }
            // 无人领取时：先收回发时已分出的群主/推荐返佣，再把手续费原路退回发包人
            if ($feeRefund > 0 && $platformUserId > 0) {
                $this->clawbackSendTimeFeeSplit($packetId, $packetNo, $platformUserId);
                $this->wallet->change(
                    $platformUserId,
                    -$feeRefund,
                    'red_packet_fee',
                    '红包未领手续费退回支出 ' . $packetNo,
                    $bizMeta
                );
                $feeOut = $this->wallet->change(
                    $fromUserId,
                    $feeRefund,
                    'red_packet_refund',
                    '红包手续费退回 ' . $packetNo,
                    $bizMeta
                );
                if ($ledgerId <= 0) {
                    $ledgerId = (int)($feeOut['ledger_id'] ?? 0);
                }
                $totalRefund = round($totalRefund + $feeRefund, 2);
            }
            Db::exec(
                'UPDATE ' . Db::table('chat_red_packets')
                . ' SET remain_count=0, remain_amount=0, status=3, expire_status=1,'
                . ' refund_amount=?, updatetime=? WHERE id=?',
                [sprintf('%.2f', $totalRefund), $now, $packetId]
            );
            if ($refund > 0 || $feeRefund > 0) {
                $settleRemark = '过期未抢完退回剩余池';
                if ($neverGrabbed && $feeRefund > 0) {
                    $settleRemark = '过期无人领取，池+手续费原路退回';
                } elseif ($luckyClawback && $clawed > 0.00001) {
                    $settleRemark = '未领完此包作废收回金额';
                } elseif (!empty($isRelayExpire)) {
                    $settleRemark = $neverGrabbed
                        ? '接龙超时无人领取，退回发包手'
                        : '接龙超时结束，已领保留，未领退回发包手';
                }
                Db::exec(
                    'INSERT INTO ' . Db::table('chat_red_packet_settlements')
                    . ' (packet_id,packet_no,settle_type,from_user_id,to_user_id,amount,ledger_id,status,remark,createtime)'
                    . ' VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [
                        $packetId,
                        $packetNo,
                        'refund',
                        $feeRefund > 0 ? $platformUserId : 0,
                        $fromUserId,
                        sprintf('%.2f', $totalRefund),
                        $ledgerId,
                        1,
                        $settleRemark,
                        $now,
                    ]
                );
            }
            // 非拼手气收回路径：同事务内解冻领取人潜在赔付锁定
            if (!$luckyClawback || !$records) {
                $freezeRows = Db::fetchAll(
                    'SELECT id, user_id, frozen_amount FROM ' . Db::table('chat_red_packet_records')
                    . ' WHERE packet_id=? AND freeze_status=1 AND frozen_amount>0 FOR UPDATE',
                    [$packetId]
                );
                foreach ($freezeRows ?: [] as $fr) {
                    $fAmt = round((float)($fr['frozen_amount'] ?? 0), 2);
                    $fUid = (int)($fr['user_id'] ?? 0);
                    $fRid = (int)($fr['id'] ?? 0);
                    if ($fUid > 0 && $fAmt > 0.00001) {
                        $this->wallet->unfreeze(
                            $fUid,
                            $fAmt,
                            'red_packet_unfreeze',
                            !empty($isRelayExpire) ? '接龙超时解冻' : ('红包过期解冻 ' . $packetNo),
                            $bizMeta
                        );
                    }
                    if ($fRid > 0) {
                        Db::exec(
                            'UPDATE ' . Db::table('chat_red_packet_records')
                            . ' SET freeze_status=2 WHERE id=?',
                            [$fRid]
                        );
                    }
                }
            }
            // 接龙超时：清除最少者续发标记，禁止事后再扣/续发
            if (!empty($isRelayExpire)) {
                Db::exec(
                    'UPDATE ' . Db::table('chat_red_packet_records')
                    . ' SET need_compensate=0, compensate_status=0, is_worst=0,'
                    . ' compensate_amount=0, frozen_amount=0, freeze_status=IF(freeze_status=1,2,freeze_status)'
                    . ' WHERE packet_id=?',
                    [$packetId]
                );
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            throw $e;
        }

        try {
            RedisClient::conn()->del($queueKey, $grabbedKey, $metaKey);
        } catch (\Throwable $e) {
            if ($hasRedisLeft) {
                error_log('[RP_EXPIRE] redis cleanup fail packet_id=' . $packetId . ' ' . $e->getMessage());
            }
        }
        if ((int)($fresh['packet_type'] ?? $packet['packet_type'] ?? 0) === 5
            || (int)($packet['packet_type'] ?? 0) === 5) {
            try {
                $this->clearRelayRetry($packetId);
            } catch (\Throwable $eClr) {
            }
        }
        // 接龙超时：群内公示自动结束（不扣最少者）
        if (!empty($isRelayExpire) && (int)($fresh['scope_type'] ?? 0) === 2) {
            try {
                $gid = (int)($fresh['group_id'] ?? 0);
                if ($gid > 0) {
                    $tip = '接龙超时自动结束：已领金额保留，未领已退回发包手，本轮不再续发、不扣最少者';
                    $msg = $this->messages->sendGroupSystem($gid, $tip, 0, [
                        'packet_id' => $packetId,
                        'packet_no' => $packetNo,
                        'event'     => 'relay_expire',
                    ]);
                    if (is_array($msg)) {
                        $uids = $this->groups->pushTargetUserIds($gid);
                        if ($uids) {
                            PushBus::toUsers($uids, 'group.message', ['message' => $msg]);
                        }
                    }
                }
            } catch (\Throwable $eTip) {
                error_log('[RP_EXPIRE] relay tip fail packet=' . $packetId . ' ' . $eTip->getMessage());
            }
        }
        $this->revealFairProof($packetId);
    }

    /**
     * 解冻某红包下所有仍冻结的潜在赔付额（结算完成 / 过期退回）
     */
    public function releasePacketFreezes($packetId, $packetNo = '')
    {
        $packetId = (int)$packetId;
        if ($packetId <= 0) {
            return 0;
        }
        $packetNo = (string)$packetNo;
        if ($packetNo === '') {
            $p = Db::fetch(
                'SELECT packet_no FROM ' . Db::table('chat_red_packets') . ' WHERE id=? LIMIT 1',
                [$packetId]
            );
            $packetNo = (string)($p['packet_no'] ?? '');
        }
        $bizMeta = ['biz_no' => $packetNo, 'ref_type' => 'red_packet', 'ref_id' => $packetId];
        $n = 0;
        Db::begin();
        try {
            $rows = Db::fetchAll(
                'SELECT id, user_id, frozen_amount, freeze_status FROM ' . Db::table('chat_red_packet_records')
                . ' WHERE packet_id=? AND freeze_status=1 AND frozen_amount>0 FOR UPDATE',
                [$packetId]
            );
            foreach ($rows ?: [] as $row) {
                $amt = round((float)($row['frozen_amount'] ?? 0), 2);
                $uid = (int)($row['user_id'] ?? 0);
                $rid = (int)($row['id'] ?? 0);
                if ($uid <= 0 || $rid <= 0 || $amt <= 0.00001) {
                    if ($rid > 0) {
                        Db::exec(
                            'UPDATE ' . Db::table('chat_red_packet_records')
                            . ' SET freeze_status=2, frozen_amount=0 WHERE id=?',
                            [$rid]
                        );
                    }
                    continue;
                }
                $this->wallet->unfreeze(
                    $uid,
                    $amt,
                    'red_packet_unfreeze',
                    '红包潜在赔付解冻 ' . $packetNo,
                    $bizMeta
                );
                Db::exec(
                    'UPDATE ' . Db::table('chat_red_packet_records')
                    . ' SET freeze_status=2 WHERE id=?',
                    [$rid]
                );
                $n++;
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            throw $e;
        }
        return $n;
    }


    /**
     * 接龙/拼手气：hash 预拆金额的全局最少（分）。优先 fair_cents，其次 Redis meta。
     */
    protected function packetMinCent(array $packet)
    {
        $hint = (int)($packet['min_cent'] ?? 0);
        if ($hint > 0) {
            return $hint;
        }
        $raw = trim((string)($packet['fair_cents'] ?? ''));
        if ($raw === '') {
            $packetId = (int)($packet['id'] ?? 0);
            if ($packetId > 0) {
                try {
                    $row = Db::fetch(
                        'SELECT fair_cents FROM ' . Db::table('chat_red_packets') . ' WHERE id=? LIMIT 1',
                        [$packetId]
                    );
                    $raw = trim((string)($row['fair_cents'] ?? ''));
                } catch (\Throwable $e) {
                    $raw = '';
                }
            }
        }
        if ($raw !== '') {
            $arr = json_decode($raw, true);
            if (is_array($arr) && $arr) {
                return (int)min(array_map('intval', $arr));
            }
        }
        $packetId = (int)($packet['id'] ?? 0);
        if ($packetId > 0) {
            try {
                $m = RedisClient::conn()->hGet(RedisClient::key('rp:' . $packetId . ':meta'), 'min_cent');
                if ($m !== false && $m !== null && (string)$m !== '') {
                    return (int)$m;
                }
            } catch (\Throwable $e) {
            }
        }
        return 0;
    }

    protected function ensureRedisSeeded(array $packet, $queueKey, $metaKey)
    {
        try {
            $r = RedisClient::conn();
            $len = (int)$r->lLen($queueKey);
            $remainCount = (int)$packet['remain_count'];
            if ($len > 0 || $remainCount <= 0 || (int)$packet['status'] !== 1) {
                return;
            }
            $packetId = (int)$packet['id'];
            $lockKey = RedisClient::key('rp:' . $packetId . ':reseed_lock');
            // 防多 worker 同时补种导致队列翻倍
            if (!$r->set($lockKey, (string)time(), ['nx', 'ex' => 8])) {
                return;
            }
            try {
                if ((int)$r->lLen($queueKey) > 0) {
                    return;
                }
                $remainCent = (int)round((float)$packet['remain_amount'] * 100);
                $minCent = (int)($this->cfg['min_amount_cent'] ?? 1);
                if ($remainCent < $remainCount * $minCent) {
                    return;
                }
                $ptype = (int)($packet['packet_type'] ?? 0);
                if ($ptype === 4) {
                    $cents = $this->splitLucky($remainCent, $remainCount, $minCent);
                } elseif (in_array($ptype, [2, 3, 5], true)) {
                    $cents = $this->splitLucky($remainCent, $remainCount, $minCent);
                } else {
                    $cents = $this->splitEqual($remainCent, $remainCount, $minCent);
                }
                $expireAt = (int)$packet['expiretime'];
                // 关键：补种不得清空 grabbed，否则已领用户可再抢造成超发压力
                $this->seedRedis($packetId, $cents, $expireAt, [
                    'packet_no'    => $packet['packet_no'],
                    'scope_type'   => (int)$packet['scope_type'],
                    'group_id'     => (int)$packet['group_id'],
                    'to_user_id'   => (int)$packet['to_user_id'],
                    'from_user_id' => (int)$packet['from_user_id'],
                    'total_amount' => sprintf('%.2f', (float)$packet['total_amount']),
                    'total_count'  => (int)$packet['total_count'],
                    'total_cent'   => (int)round((float)$packet['total_amount'] * 100),
                    'packet_type'  => (int)$packet['packet_type'],
                    'mine_digit'   => (int)$packet['mine_digit'],
                    'reseed'       => '1',
                ], false);
            } finally {
                try {
                    $r->del($lockKey);
                } catch (\Throwable $e) {
                }
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * @param bool $resetGrabbed 首次播种 true；队列丢失补种必须 false（保留已领集合）
     */
    protected function seedRedis($packetId, array $cents, $expireAt, array $meta, $resetGrabbed = true)
    {
        $r = RedisClient::conn();
        $queueKey = RedisClient::key('rp:' . $packetId . ':queue');
        $grabbedKey = RedisClient::key('rp:' . $packetId . ':grabbed');
        $metaKey = RedisClient::key('rp:' . $packetId . ':meta');
        if ($resetGrabbed) {
            $r->del($queueKey, $grabbedKey, $metaKey);
        } else {
            $r->del($queueKey);
            // 保留 grabbed；刷新 meta
            $r->del($metaKey);
        }
        if ($cents) {
            $r->rPush($queueKey, ...array_map('strval', $cents));
            if (!isset($meta['min_cent'])) {
                $meta['min_cent'] = (string)min(array_map('intval', $cents));
            }
        }
        $r->hMSet($metaKey, array_merge($meta, [
            'expire_at' => (string)$expireAt,
            'packet_id' => (string)$packetId,
        ]));
        $ttl = max(60, $expireAt - time() + 3600);
        $r->expire($queueKey, $ttl);
        $r->expire($grabbedKey, $ttl);
        $r->expire($metaKey, $ttl);
    }

    protected function evalGrabPacket($queueKey, $grabbedKey, $metaKey, $userId)
    {
        $raw = RedisClient::evalFile('grab_red_packet.lua', [$queueKey, $grabbedKey, $metaKey], [
            (string)$userId,
            (string)time(),
        ]);
        $result = json_decode((string)$raw, true);
        if (!is_array($result)) {
            error_log('[RP_GRAB][ERROR] lua invalid response user=' . (int)$userId . ' raw=' . substr((string)$raw, 0, 200));
            return null;
        }
        return $result;
    }

    protected function packetMetaForGrab($packetId)
    {
        try {
            $meta = RedisClient::conn()->hMGet(
                RedisClient::key('rp:' . (int)$packetId . ':meta'),
                ['packet_no', 'scope_type', 'group_id', 'to_user_id', 'from_user_id', 'total_amount', 'total_count', 'packet_type', 'mine_digit', 'expire_at', 'min_cent']
            );
            if (!is_array($meta) || trim((string)($meta['packet_no'] ?? '')) === '') {
                return null;
            }
            return [
                'id'           => (int)$packetId,
                'packet_no'    => (string)$meta['packet_no'],
                'scope_type'   => (int)($meta['scope_type'] ?? 0),
                'group_id'     => (int)($meta['group_id'] ?? 0),
                'to_user_id'   => (int)($meta['to_user_id'] ?? 0),
                'from_user_id' => (int)($meta['from_user_id'] ?? 0),
                'total_amount' => round((float)($meta['total_amount'] ?? 0), 2),
                'total_count'  => (int)($meta['total_count'] ?? 0),
                'packet_type'  => (int)($meta['packet_type'] ?? 0),
                'mine_digit'   => (int)($meta['mine_digit'] ?? 0),
                'expire_at'    => (int)($meta['expire_at'] ?? 0),
                'expiretime'   => (int)($meta['expire_at'] ?? 0),
                'min_cent'     => (int)($meta['min_cent'] ?? 0),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function splitEqual($totalCent, $count, $minCent)
    {
        $base = intdiv($totalCent, $count);
        if ($base < $minCent) {
            throw new \InvalidArgumentException('equal split too small');
        }
        $arr = array_fill(0, $count, $base);
        $arr[$count - 1] += $totalCent - $base * $count;
        return $arr;
    }

    /** 二倍均值法（整数分，本地随机；普通等额以外兜底） */
    protected function splitLucky($totalCent, $count, $minCent)
    {
        $leftCent = $totalCent;
        $leftCount = $count;
        $arr = [];
        for ($i = 0; $i < $count - 1; $i++) {
            $max = (int)floor($leftCent / $leftCount * 2);
            $max = max($minCent, $max);
            $money = random_int($minCent, max($minCent, $max));
            $remainAfter = $leftCent - $money;
            $remainPeople = $leftCount - 1;
            if ($remainAfter < $remainPeople * $minCent) {
                $money = $leftCent - $remainPeople * $minCent;
            }
            $arr[] = $money;
            $leftCent -= $money;
            $leftCount--;
        }
        $arr[] = $leftCent;
        shuffle($arr);
        return $arr;
    }

    /**
     * 用波场区块哈希 + 包号确定性拆拼手气（可复算、不打节点）
     * @return int[]
     */
    protected function splitLuckyFromHash($totalCent, $count, $minCent, $blockId, $packetNo)
    {
        $totalCent = (int)$totalCent;
        $count = (int)$count;
        $minCent = max(1, (int)$minCent);
        if ($count <= 0 || $totalCent < $count * $minCent) {
            throw new \InvalidArgumentException('invalid hash split params');
        }
        $state = hash('sha256', strtolower(trim((string)$blockId)) . '|' . trim((string)$packetNo) . '|rp-split', true);
        $nextInt = function ($min, $max) use (&$state) {
            $min = (int)$min;
            $max = (int)$max;
            if ($max <= $min) {
                return $min;
            }
            $state = hash('sha256', $state, true);
            $u = unpack('N', substr($state, 0, 4));
            $n = (int)$u[1];
            if ($n < 0) {
                $n = $n & 0x7fffffff;
            }
            return $min + ($n % ($max - $min + 1));
        };

        $leftCent = $totalCent;
        $leftCount = $count;
        $arr = [];
        for ($i = 0; $i < $count - 1; $i++) {
            $max = (int)floor($leftCent / $leftCount * 2);
            $max = max($minCent, $max);
            $money = $nextInt($minCent, max($minCent, $max));
            $remainAfter = $leftCent - $money;
            $remainPeople = $leftCount - 1;
            if ($remainAfter < $remainPeople * $minCent) {
                $money = $leftCent - $remainPeople * $minCent;
            }
            $arr[] = $money;
            $leftCent -= $money;
            $leftCount--;
        }
        $arr[] = $leftCent;

        // 确定性洗牌
        for ($i = count($arr) - 1; $i > 0; $i--) {
            $j = $nextInt(0, $i);
            $tmp = $arr[$i];
            $arr[$i] = $arr[$j];
            $arr[$j] = $tmp;
        }
        return $arr;
    }

    protected function markBestLuck($packetId)
    {
        $row = Db::fetch(
            'SELECT id FROM ' . Db::table('chat_red_packet_records')
            . ' WHERE packet_id=? ORDER BY amount DESC, id ASC LIMIT 1',
            [(int)$packetId]
        );
        if ($row) {
            Db::exec(
                'UPDATE ' . Db::table('chat_red_packet_records') . ' SET is_best=1 WHERE id=?',
                [(int)$row['id']]
            );
        }
    }

    public function detail($packetId, $userId = 0, $viewerRole = 0)
    {
        $packetId = (int)$packetId;
        $userId = (int)$userId;
        if ($userId <= 0) {
            throw new \RuntimeException('forbidden');
        }
        // 短缓存：同一用户连点/回刷详情时免重复多表查询（抢包后 bump ver 失效）
        $ver = 0;
        try {
            $ver = (int)RedisClient::conn()->get(RedisClient::key('rp:detail:ver:' . $packetId));
        } catch (\Throwable $e) {
        }
        $cacheKey = RedisClient::key('rp:detail:' . $packetId . ':' . $userId . ':' . (int)$viewerRole . ':v' . $ver);
        try {
            $cached = RedisClient::conn()->get($cacheKey);
            if ($cached !== false && $cached !== null && $cached !== '') {
                $decoded = json_decode($cached, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        } catch (\Throwable $e) {
        }

        $packet = Db::fetch('SELECT * FROM ' . Db::table('chat_red_packets') . ' WHERE id=? LIMIT 1', [$packetId]);
        if (!$packet) {
            return null;
        }
        // 授权：群红包须为成员；私聊红包须为收/发双方
        if ((int)$packet['scope_type'] === 2) {
            if (!$this->groups->isMember((int)$packet['group_id'], $userId)) {
                throw new \RuntimeException('not in group');
            }
        } elseif ((int)$packet['scope_type'] === 1) {
            if ($userId !== (int)$packet['to_user_id'] && $userId !== (int)$packet['from_user_id']) {
                throw new \RuntimeException('not in conversation');
            }
        } else {
            throw new \RuntimeException('forbidden');
        }
        $records = Db::fetchAll(
            'SELECT id,packet_id,user_id,amount,is_best,is_worst,is_mine_hit,tail_digit,createtime FROM '
            . Db::table('chat_red_packet_records') . ' WHERE packet_id=? ORDER BY id ASC',
            [$packetId]
        );
        $mine = null;
        foreach ($records as $r) {
            if ((int)$r['user_id'] === $userId) {
                $mine = $r;
                break;
            }
        }
        // 本人已领 / 已领完 / 过期结算：展示领取列表；未领过且进行中不公开，避免偷看金额
        $remainCount = (int)($packet['remain_count'] ?? 0);
        $status = (int)($packet['status'] ?? 0);
        $finished = ($remainCount <= 0) || in_array($status, [2, 3, 4, 5], true);
        $claimsVisible = $finished || ($mine !== null);
        if (!$claimsVisible) {
            $records = [];
        }
        // 详情页不拉钱包余额（抢包接口会回写）；省一次账户表查询
        $balance = null;
        $policy = null;
        $profileClickable = true;
        $privacyMode = 'open';
        if ((int)$packet['scope_type'] === 2 && (int)$packet['group_id'] > 0) {
            $group = $this->groups->get((int)$packet['group_id']) ?: [];
            $role = (int)$viewerRole;
            if ($role <= 0) {
                $role = $this->groups->memberRole((int)$packet['group_id'], $userId);
            }
            $policy = $this->groups->buildPolicy($group, $role);
            $privacyMode = (string)($policy['privacy_mode'] ?? 'private');
            $profileClickable = empty($policy['rp_detail_locked']);
        }
        $uids = array_map(function ($r) {
            return (int)$r['user_id'];
        }, $records);
        $authBrief = new AuthService([]);
        $users = $authBrief->usersBriefMap($uids);
        $enriched = [];
        foreach ($records as $r) {
            $uid = (int)$r['user_id'];
            $u = $users[$uid] ?? null;
            $nick = $authBrief->displayNameFromBrief($u, $uid);
            $avatar = is_array($u) ? (string)($u['avatar'] ?? '') : '';
            $isSelf = $uid === $userId;
            $rowClickable = $profileClickable && !$isSelf;
            // 领取列表始终展示真实昵称/头像；隐私群仅禁止点进资料
            $enriched[] = array_merge($r, [
                'nickname'          => $nick,
                'avatar'            => $avatar,
                'profile_clickable' => $rowClickable,
                'avatar_gray'       => false,
                'name_masked'       => false,
            ]);
        }
        $result = [
            'packet'             => $this->sanitizePacketFair($packet),
            'records'            => $enriched,
            'mine'               => $mine,
            'claims_visible'     => $claimsVisible,
            'balance'            => $balance,
            'wallet'             => $this->wallet->field(),
            'profile_clickable'  => $profileClickable,
            'privacy_mode'       => $privacyMode,
            'rp_detail_locked'   => !$profileClickable,
            'policy'             => $policy,
        ];
        try {
            RedisClient::conn()->setex($cacheKey, 15, json_encode($result, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
        }
        return $result;
    }

    /**
     * 开奖：绑定波场官方区块哈希（异步，无 sleep）
     * 分润结算仍在抢完/过期路径同步执行；此处只负责 Tron 证明。
     */
    public function revealFairProof($packetId)
    {
        return TronFair::scheduleReveal((int)$packetId);
    }

  /**
   * 按单号查询公平性（公开接口）
   */
    public function publicFairByNo($packetNo)
    {
        $packetNo = trim((string)$packetNo);
        if ($packetNo === '') {
            return null;
        }
        $packet = Db::fetch(
            'SELECT * FROM ' . Db::table('chat_red_packets') . ' WHERE packet_no=? LIMIT 1',
            [$packetNo]
        );
        if (!$packet) {
            return null;
        }
        if (!in_array((int)($packet['packet_type'] ?? 0), [2, 3, 5], true)) {
            return null;
        }
        $records = [];
        if ((int)($packet['tron_status'] ?? 0) === 2 || (int)($packet['fair_revealed_at'] ?? 0) > 0) {
            $records = Db::fetchAll(
                'SELECT user_id, amount, amount_cent, tail_digit, is_best, is_worst, is_mine_hit, createtime'
                . ' FROM ' . Db::table('chat_red_packet_records') . ' WHERE packet_id=? ORDER BY id ASC',
                [(int)$packet['id']]
            );
        }
        return TronFair::publicView($packet, $records);
    }

    /**
     * 历史消息补齐红包封面字段（雷号 / 过期 / 本人是否已领）
     * 红包元数据按 packet_id 短缓存；本人领取状态仍按用户查
     */
    public function enrichMessageExtras(array $messages, $userId)
    {
        $userId = (int)$userId;
        $pids = [];
        foreach ($messages as $m) {
            if ((int)($m['msg_type'] ?? 0) !== 2) {
                continue;
            }
            $ex = $m['extra'] ?? null;
            if (is_string($ex) && $ex !== '') {
                $ex = json_decode($ex, true);
            }
            if (!is_array($ex)) {
                continue;
            }
            $pid = (int)($ex['packet_id'] ?? 0);
            if ($pid > 0) {
                $pids[$pid] = true;
            }
        }
        if (!$pids) {
            return $messages;
        }
        $idList = array_keys($pids);
        $byId = [];
        $missing = [];
        try {
            $r = RedisClient::conn();
            $keys = [];
            foreach ($idList as $pid) {
                $keys[] = RedisClient::key('rp:cover:' . $pid);
            }
            $vals = $r->mGet($keys);
            if (!is_array($vals)) {
                $vals = [];
            }
            foreach ($idList as $i => $pid) {
                $raw = $vals[$i] ?? false;
                if ($raw !== false && $raw !== null && $raw !== '') {
                    $decoded = json_decode((string)$raw, true);
                    if (is_array($decoded)) {
                        $byId[$pid] = $decoded;
                        continue;
                    }
                }
                $missing[] = $pid;
            }
        } catch (\Throwable $e) {
            $missing = $idList;
        }
        if ($missing) {
            $placeholders = implode(',', array_fill(0, count($missing), '?'));
            $packets = Db::fetchAll(
                'SELECT id, packet_type, mine_digit, status, expiretime, remain_count, tron_status, tron_block_id'
                . ' FROM ' . Db::table('chat_red_packets')
                . ' WHERE id IN (' . $placeholders . ')',
                $missing
            );
            try {
                $r = RedisClient::conn();
                foreach ($packets as $p) {
                    $pid = (int)$p['id'];
                    $byId[$pid] = $p;
                    $r->setex(RedisClient::key('rp:cover:' . $pid), 15, json_encode($p, JSON_UNESCAPED_UNICODE));
                }
            } catch (\Throwable $e) {
                foreach ($packets as $p) {
                    $byId[(int)$p['id']] = $p;
                }
            }
        }
        $grabbed = [];
        if ($userId > 0 && $idList) {
            $placeholders = implode(',', array_fill(0, count($idList), '?'));
            $recs = Db::fetchAll(
                'SELECT packet_id FROM ' . Db::table('chat_red_packet_records')
                . ' WHERE user_id=? AND packet_id IN (' . $placeholders . ')',
                array_merge([$userId], $idList)
            );
            foreach ($recs as $r) {
                $grabbed[(int)$r['packet_id']] = true;
            }
        }
        $now = time();
        foreach ($messages as &$m) {
            if ((int)($m['msg_type'] ?? 0) !== 2) {
                continue;
            }
            $ex = $m['extra'] ?? null;
            if (is_string($ex) && $ex !== '') {
                $ex = json_decode($ex, true);
            }
            if (!is_array($ex)) {
                $ex = [];
            }
            $pid = (int)($ex['packet_id'] ?? 0);
            $p = $pid > 0 ? ($byId[$pid] ?? null) : null;
            if ($p) {
                if ((int)$p['packet_type'] === 3) {
                    $tronDone = (int)($p['tron_status'] ?? 0) === 2
                        && trim((string)($p['tron_block_id'] ?? '')) !== '';
                    $ex['mine_digit'] = (int)($p['mine_digit'] ?? 0);
                    $ex['mine_pending'] = !$tronDone;
                }
                $ex['packet_type'] = (int)$p['packet_type'];
                $ex['expiretime'] = (int)($p['expiretime'] ?? 0);
                $ex['packet_status'] = (int)($p['status'] ?? 0);
                $ex['remain_count'] = (int)($p['remain_count'] ?? 0);
            }
            $isGrabbed = $pid > 0 && !empty($grabbed[$pid]);
            $status = (int)($ex['packet_status'] ?? 0);
            $expireAt = (int)($ex['expiretime'] ?? 0);
            $isExpired = $status === 3 || ($expireAt > 0 && $now >= $expireAt && $status !== 2 && $status !== 5);
            // status: 1进行中 2已抢完 3已过期 4已关闭 5已结算
            if (in_array($status, [3, 4], true)) {
                $isExpired = true;
            }
            $ex['cover_grabbed'] = $isGrabbed;
            $ex['cover_expired'] = $isExpired;
            $ex['cover_faded'] = $isGrabbed || $isExpired;
            $m['extra'] = $ex;
        }
        unset($m);
        return $messages;
    }

    protected function sanitizePacketFair(array $packet)
    {
        // 未开出波场哈希前不暴露 block_id；扫雷雷号为发包人手填，始终公示
        $tronDone = (int)($packet['tron_status'] ?? 0) === 2
            && trim((string)($packet['tron_block_id'] ?? '')) !== '';
        if (!$tronDone) {
            unset($packet['tron_block_id'], $packet['tron_lucky'], $packet['fair_seed'], $packet['fair_cents'], $packet['fair_payload']);
        }
        if ((int)($packet['packet_type'] ?? 0) === 3) {
            $packet['mine_pending'] = !$tronDone;
            $packet['mine_digit'] = (int)($packet['mine_digit'] ?? 0);
        }
        return $packet;
    }
}
