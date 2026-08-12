<?php

namespace Im\Service;

use Im\Support\Db;
use Im\Support\DrandClient;
use Im\Support\NotifyPublisher;
use Im\Support\RedisClient;
use Im\Support\TronBlockClient;
use Im\Support\TronFair;
use Im\Support\TronHashCache;

/**
 * 红宝尾数牛牛
 * - 购入积分进奖池；虚拟红包仅展示尾数，不入账
 * - 购入结束用波场 Block Hash 派生尾数（兼容旧局 drand）
 * - 手动领取才展示；到期强制结算
 */
class NiuniuService
{
    const STATUS_BUYING = 1;
    const STATUS_CLAIMING = 2;
    const STATUS_SETTLED = 3;
    const STATUS_VOID = 4;
    const STATUS_REFUND = 5;

    const TIER_LOW = 1;
    const TIER_SECONDARY = 2;
    const TIER_NIUNIU = 3;

    /** 普通：每份独立尾数 */
    const MODE_NORMAL = 1;
    /** 单结果：同一用户无论买几份，只算一个尾数（盖到该用户全部份上） */
    const MODE_SINGLE = 2;

    const MSG_TYPE = 10;

    /** @var array */
    protected $cfg;
    /** @var MessageService */
    protected $messages;
    /** @var GroupService */
    protected $groups;
    /** @var WalletService */
    protected $wallet;

    public function __construct(array $cfg, MessageService $messages, GroupService $groups)
    {
        $this->cfg = $cfg;
        $this->messages = $messages;
        $this->groups = $groups;
        $this->wallet = new WalletService($cfg);
    }

    public function config()
    {
        $rp = is_array($this->cfg['red_packet'] ?? null) ? $this->cfg['red_packet'] : [];
        $nn = is_array($this->cfg['niuniu'] ?? null) ? $this->cfg['niuniu'] : [];
        $merged = array_merge([
            'share_price'      => 100.0,
            'buy_seconds'      => 120,
            'claim_seconds'    => 60,
            'fee_rate'         => 0.03,
            'niuniu_rate'      => 0.60,
            'secondary_rate'   => 0.40,
            'platform_user_id' => (int)($rp['platform_user_id'] ?? 56960815),
            'drand_api'        => 'https://api.drand.sh',
            'drand_period'     => 30,
            'enabled_global'   => 1,
            'rule_text'        => '',
            'robot_user_id'    => (int)($rp['group_robot_user_id'] ?? 74282747),
            'loop_gap_sec'     => 5,
        ], $nn);
        // 兼容 runtime 里 niuniu_* 扁平键
        foreach ([
            'niuniu_share_price' => 'share_price',
            'niuniu_buy_seconds' => 'buy_seconds',
            'niuniu_claim_seconds' => 'claim_seconds',
            'niuniu_fee_rate' => 'fee_rate',
            'niuniu_pool_rate' => 'niuniu_rate',
            'niuniu_secondary_rate' => 'secondary_rate',
            'niuniu_platform_user_id' => 'platform_user_id',
            'niuniu_drand_api' => 'drand_api',
            'niuniu_drand_period' => 'drand_period',
            'niuniu_enabled_global' => 'enabled_global',
            'niuniu_rule_text' => 'rule_text',
            'niuniu_loop_gap_sec' => 'loop_gap_sec',
        ] as $from => $to) {
            if (isset($rp[$from]) && !isset($nn[$to])) {
                $merged[$to] = $rp[$from];
            }
        }
        return $merged;
    }

    public function isLooping($groupId)
    {
        $g = $this->groups->get((int)$groupId);
        return $g && (int)($g['niuniu_loop'] ?? 0) === 1;
    }

    public function enableLoop($groupId, $starterUserId, $gameMode = self::MODE_NORMAL)
    {
        $groupId = (int)$groupId;
        $starterUserId = (int)$starterUserId;
        $gameMode = ((int)$gameMode === self::MODE_SINGLE) ? self::MODE_SINGLE : self::MODE_NORMAL;
        try {
            Db::exec(
                'UPDATE ' . Db::table('chat_groups')
                . ' SET niuniu_loop=1, niuniu_loop_starter=?, niuniu_loop_mode=?, updatetime=? WHERE id=?',
                [$starterUserId > 0 ? $starterUserId : 0, $gameMode, time(), $groupId]
            );
        } catch (\Throwable $e) {
            // 兼容尚未跑 patch 的库
            Db::exec(
                'UPDATE ' . Db::table('chat_groups')
                . ' SET niuniu_loop=1, niuniu_loop_starter=?, updatetime=? WHERE id=?',
                [$starterUserId > 0 ? $starterUserId : 0, time(), $groupId]
            );
        }
        try {
            $this->groups->bumpViewerInfoCache($groupId);
        } catch (\Throwable $e) {
        }
    }

    public function disableLoop($groupId)
    {
        $groupId = (int)$groupId;
        Db::exec(
            'UPDATE ' . Db::table('chat_groups')
            . ' SET niuniu_loop=0, updatetime=? WHERE id=?',
            [time(), $groupId]
        );
        $this->clearLoopNext($groupId);
        try {
            $this->groups->bumpViewerInfoCache($groupId);
        } catch (\Throwable $e) {
        }
    }

    public function isGroupEnabled($groupId)
    {
        $c = $this->config();
        if ((int)$c['enabled_global'] !== 1) {
            return false;
        }
        $g = $this->groups->get((int)$groupId);
        return $g && (int)($g['niuniu_enabled'] ?? 0) === 1;
    }

    public function activeBuyingRoundId($groupId)
    {
        $groupId = (int)$groupId;
        try {
            $v = RedisClient::conn()->get(RedisClient::key('niuniu:mute:' . $groupId));
            if ($v) {
                return (int)$v;
            }
        } catch (\Throwable $e) {
        }
        $row = Db::fetch(
            'SELECT id FROM ' . Db::table('chat_niuniu_rounds')
            . ' WHERE group_id=? AND status=? LIMIT 1',
            [$groupId, self::STATUS_BUYING]
        );
        return $row ? (int)$row['id'] : 0;
    }

    /**
     * 开启对局（默认开启连开：结束后自动开下一局，直至管理员关闭）
     * @param array $opts trusted_loop=true 时跳过管理员校验（连开自动续局）
     */
    public function start($userId, $groupId, array $opts = [])
    {
        $userId = (int)$userId;
        $groupId = (int)$groupId;
        $trusted = !empty($opts['trusted_loop']);
        if (!$this->isGroupEnabled($groupId)) {
            throw new \RuntimeException('本群未开启尾数牛牛');
        }
        if (!$trusted) {
            if (!$this->groups->isMember($groupId, $userId)) {
                throw new \RuntimeException('not in group');
            }
            $role = $this->groups->memberRole($groupId, $userId);
            if ($role < 2) {
                throw new \RuntimeException('仅群主/管理员可开启对局');
            }
        }
        $busy = Db::fetch(
            'SELECT id FROM ' . Db::table('chat_niuniu_rounds')
            . ' WHERE group_id=? AND status IN (?,?) LIMIT 1',
            [$groupId, self::STATUS_BUYING, self::STATUS_CLAIMING]
        );
        $gameMode = ((int)($opts['game_mode'] ?? self::MODE_NORMAL) === self::MODE_SINGLE)
            ? self::MODE_SINGLE
            : self::MODE_NORMAL;
        if ($busy) {
            if (!$trusted) {
                // 已在连开中：再点开始只确保 loop 打开（沿用当前局玩法）
                $this->enableLoop($groupId, $userId, $gameMode);
                throw new \RuntimeException('本群已有进行中的牛牛对局（连开已开启）');
            }
            return null;
        }

        $c = $this->config();
        $buySec = max(30, (int)$c['buy_seconds']);
        $claimSec = max(15, (int)$c['claim_seconds']);
        $price = round((float)$c['share_price'], 2);
        if ($price <= 0) {
            throw new \RuntimeException('份额价格无效');
        }

        // 人工点开始 → 开启连开；续局沿用原 starter / 玩法
        if (!$trusted) {
            $this->enableLoop($groupId, $userId, $gameMode);
        } else {
            $g = $this->groups->get($groupId) ?: [];
            if ($userId <= 0) {
                $userId = (int)($g['niuniu_loop_starter'] ?? 0);
                if ($userId <= 0) {
                    $userId = (int)$c['robot_user_id'];
                }
            }
            $loopMode = (int)($g['niuniu_loop_mode'] ?? 0);
            if ($loopMode === self::MODE_SINGLE || $loopMode === self::MODE_NORMAL) {
                $gameMode = $loopMode;
            }
        }

        $tronTarget = 0;
        $tronStatus = TronFair::STATUS_PENDING;
        try {
            $nowNum = (int)TronBlockClient::getNowBlockNum(3);
            // 波场约 3s/块：购入期内锁定未来块，购入结束时再取该块哈希
            $blocksAhead = max(TronFair::commitOffset(), (int)ceil($buySec / 3) + 2);
            if ($nowNum > 0) {
                $tronTarget = $nowNum + $blocksAhead;
            }
        } catch (\Throwable $e) {
            error_log('[NIUNIU][start] tron commit fail: ' . $e->getMessage());
        }
        // 兼容旧字段：drand_* 在波场模式下仅作占位/展示回退
        $drandRound = $tronTarget;
        $drandUrl = $tronTarget > 0
            ? ('https://tronscan.org/#/block/' . $tronTarget)
            : '';
        $now = time();
        $buyEnd = $now + $buySec;

        Db::begin();
        try {
            // 锁群行，避免并发开出两局
            Db::fetch(
                'SELECT id FROM ' . Db::table('chat_groups') . ' WHERE id=? FOR UPDATE',
                [$groupId]
            );
            $busy2 = Db::fetch(
                'SELECT id FROM ' . Db::table('chat_niuniu_rounds')
                . ' WHERE group_id=? AND status IN (?,?) LIMIT 1',
                [$groupId, self::STATUS_BUYING, self::STATUS_CLAIMING]
            );
            if ($busy2) {
                Db::rollBack();
                if (!$trusted) {
                    $this->enableLoop($groupId, $userId, $gameMode);
                    throw new \RuntimeException('本群已有进行中的牛牛对局（连开已开启）');
                }
                return null;
            }
            try {
                Db::exec(
                    'INSERT INTO ' . Db::table('chat_niuniu_rounds')
                    . ' (group_id,starter_user_id,status,game_mode,share_price,buy_seconds,claim_seconds,buy_end_at,'
                    . 'fee_rate,niuniu_rate,secondary_rate,drand_round,drand_url,'
                    . 'tron_block_num,tron_block_id,tron_status,'
                    . 'platform_user_id,createtime,updatetime)'
                    . ' VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        $groupId, $userId, self::STATUS_BUYING, $gameMode, sprintf('%.2f', $price),
                        $buySec, $claimSec, $buyEnd,
                        sprintf('%.4f', (float)$c['fee_rate']),
                        sprintf('%.4f', (float)$c['niuniu_rate']),
                        sprintf('%.4f', (float)$c['secondary_rate']),
                        $drandRound,
                        $drandUrl,
                        $tronTarget,
                        '',
                        $tronStatus,
                        (int)$c['platform_user_id'],
                        $now, $now,
                    ]
                );
            } catch (\Throwable $eIns) {
                // 未 patch tron / game_mode 列时回退
                try {
                    Db::exec(
                        'INSERT INTO ' . Db::table('chat_niuniu_rounds')
                        . ' (group_id,starter_user_id,status,game_mode,share_price,buy_seconds,claim_seconds,buy_end_at,'
                        . 'fee_rate,niuniu_rate,secondary_rate,drand_round,drand_url,platform_user_id,createtime,updatetime)'
                        . ' VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                        [
                            $groupId, $userId, self::STATUS_BUYING, $gameMode, sprintf('%.2f', $price),
                            $buySec, $claimSec, $buyEnd,
                            sprintf('%.4f', (float)$c['fee_rate']),
                            sprintf('%.4f', (float)$c['niuniu_rate']),
                            sprintf('%.4f', (float)$c['secondary_rate']),
                            $drandRound,
                            $drandUrl,
                            (int)$c['platform_user_id'],
                            $now, $now,
                        ]
                    );
                } catch (\Throwable $eIns2) {
                    Db::exec(
                        'INSERT INTO ' . Db::table('chat_niuniu_rounds')
                        . ' (group_id,starter_user_id,status,share_price,buy_seconds,claim_seconds,buy_end_at,'
                        . 'fee_rate,niuniu_rate,secondary_rate,drand_round,drand_url,platform_user_id,createtime,updatetime)'
                        . ' VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                        [
                            $groupId, $userId, self::STATUS_BUYING, sprintf('%.2f', $price),
                            $buySec, $claimSec, $buyEnd,
                            sprintf('%.4f', (float)$c['fee_rate']),
                            sprintf('%.4f', (float)$c['niuniu_rate']),
                            sprintf('%.4f', (float)$c['secondary_rate']),
                            $drandRound,
                            $drandUrl,
                            (int)$c['platform_user_id'],
                            $now, $now,
                        ]
                    );
                }
            }
            $roundId = (int)Db::lastId();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            throw $e;
        }

        $this->clearLoopNext($groupId);
        $this->setMuteFlag($groupId, $roundId, $buySec + 5);
        $round = $this->getRound($roundId);
        $msg = $this->pushCard($round, 'buying', (int)$c['robot_user_id'] ?: $userId);
        if (!$msg && $userId > 0 && (int)$c['robot_user_id'] !== $userId) {
            $msg = $this->pushCard($round, 'buying', $userId);
        }
        if (!$msg) {
            // 开局卡片失败则回滚对局，避免“空开局”
            Db::exec(
                'UPDATE ' . Db::table('chat_niuniu_rounds') . ' SET status=?, updatetime=? WHERE id=? AND status=?',
                [self::STATUS_VOID, time(), $roundId, self::STATUS_BUYING]
            );
            $this->clearMuteFlag($groupId);
            throw new \RuntimeException('开局卡片发送失败，请重试（确认已重启 IM）');
        }
        Db::exec(
            'UPDATE ' . Db::table('chat_niuniu_rounds') . ' SET buy_msg_id=?, updatetime=? WHERE id=?',
            [(int)$msg['id'], time(), $roundId]
        );
        $round['buy_msg_id'] = (int)$msg['id'];

        if (!$trusted) {
            $this->pushTip($groupId, '尾数牛牛连开已开启，将持续发局直至管理员关闭', $userId);
        }

        return [
            'round'   => $this->publicRound($round),
            'message' => $msg,
            'looping' => $this->isLooping($groupId),
        ];
    }

    /** 关闭连开（当前局仍会跑完，不再自动开下一局） */
    public function stopLoop($userId, $groupId)
    {
        $userId = (int)$userId;
        $groupId = (int)$groupId;
        if (!$this->groups->isMember($groupId, $userId)) {
            throw new \RuntimeException('not in group');
        }
        $role = $this->groups->memberRole($groupId, $userId);
        if ($role < 2) {
            throw new \RuntimeException('仅群主/管理员可关闭');
        }
        if (!$this->isLooping($groupId)) {
            return ['looping' => false, 'message' => '连开未开启'];
        }
        $this->disableLoop($groupId);
        $this->pushTip($groupId, '尾数牛牛连开已关闭，本局结束后不再自动开局', $userId);
        return ['looping' => false, 'message' => '已关闭连开'];
    }

    public function buy($userId, $roundId, $count = 1)
    {
        $userId = (int)$userId;
        $roundId = (int)$roundId;
        $count = max(1, min(100, (int)$count));
        $round = $this->getRound($roundId);
        if (!$round) {
            throw new \RuntimeException('对局不存在');
        }
        if ((int)$round['status'] !== self::STATUS_BUYING) {
            throw new \RuntimeException('购入已结束');
        }
        if (time() >= (int)$round['buy_end_at']) {
            throw new \RuntimeException('购入倒计时已结束');
        }
        $groupId = (int)$round['group_id'];
        if (!$this->groups->isMember($groupId, $userId)) {
            throw new \RuntimeException('not in group');
        }

        $price = round((float)$round['share_price'], 2);
        $total = round($price * $count, 2);
        $now = time();

        Db::begin();
        try {
            $locked = Db::fetch(
                'SELECT * FROM ' . Db::table('chat_niuniu_rounds') . ' WHERE id=? FOR UPDATE',
                [$roundId]
            );
            if (!$locked || (int)$locked['status'] !== self::STATUS_BUYING) {
                throw new \RuntimeException('购入已结束');
            }
            if (time() >= (int)$locked['buy_end_at']) {
                throw new \RuntimeException('购入倒计时已结束');
            }
            $this->wallet->change($userId, -$total, 'niuniu_buy', '尾数牛牛购入×' . $count, [
                'biz_no'   => 'niuniu_buy_' . $roundId . '_' . $userId . '_' . $now . '_' . mt_rand(1000, 9999),
                'ref_type' => 'niuniu_round',
                'ref_id'   => $roundId,
            ]);
            $shareNos = [];
            for ($i = 0; $i < $count; $i++) {
                $aff = Db::exec(
                    'UPDATE ' . Db::table('chat_niuniu_rounds')
                    . ' SET share_count=share_count+1, pool_amount=pool_amount+?, updatetime=? WHERE id=? AND status=?',
                    [sprintf('%.2f', $price), $now, $roundId, self::STATUS_BUYING]
                );
                if ((int)$aff <= 0) {
                    throw new \RuntimeException('购入已结束');
                }
                $fresh = $this->getRound($roundId);
                $shareNo = (int)$fresh['share_count'];
                Db::exec(
                    'INSERT INTO ' . Db::table('chat_niuniu_shares')
                    . ' (round_id,group_id,user_id,share_no,amount,createtime,updatetime)'
                    . ' VALUES (?,?,?,?,?,?,?)',
                    [$roundId, $groupId, $userId, $shareNo, sprintf('%.2f', $price), $now, $now]
                );
                $shareNos[] = $shareNo;
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            throw $e;
        }

        $round = $this->getRound($roundId);
        $this->refreshBuyingCard($round);
        return [
            'round'     => $this->publicRound($round, $userId),
            'bought'    => $count,
            'share_nos' => $shareNos,
            'paid'      => $total,
        ];
    }

    /**
     * 手动领取：展示该用户本局全部份的尾数（不入账）
     */
    /**
     * 领取红宝。
     * - 普通模式：一次领一包（可传 share_id）；全部领完后客户端再进明细
     * - 单结果：无论多少包，一次领完该用户全部未领份额
     */
    public function claim($userId, $roundId, $shareId = 0)
    {
        $userId = (int)$userId;
        $roundId = (int)$roundId;
        $shareId = (int)$shareId;
        $round = $this->getRound($roundId);
        if (!$round) {
            throw new \RuntimeException('对局不存在');
        }
        if ((int)$round['status'] !== self::STATUS_CLAIMING) {
            throw new \RuntimeException('当前不在领取阶段');
        }
        if (!$this->groups->isMember((int)$round['group_id'], $userId)) {
            throw new \RuntimeException('not in group');
        }

        $joined = Db::fetch(
            'SELECT COUNT(*) AS c FROM ' . Db::table('chat_niuniu_shares')
            . ' WHERE round_id=? AND user_id=?',
            [$roundId, $userId]
        );
        if ((int)($joined['c'] ?? 0) <= 0) {
            throw new \RuntimeException('你未参与本局');
        }

        $mode = $this->normalizeMode($round['game_mode'] ?? self::MODE_NORMAL);
        $now = time();

        // 单结果：一次领完该用户全部未领包；尾数按真实领取序号从 hash 派生
        if ($mode === self::MODE_SINGLE) {
            $pending = Db::fetchAll(
                'SELECT * FROM ' . Db::table('chat_niuniu_shares')
                . ' WHERE round_id=? AND user_id=? AND claimed=0 ORDER BY id ASC',
                [$roundId, $userId]
            );
            if (!$pending) {
                throw new \RuntimeException('你已领完本局红宝');
            }
            $ids = [];
            foreach ($pending as $s) {
                $ids[] = (int)$s['id'];
            }
            $first = $this->claimSharesWithHash($round, $ids, $userId, $now, true);
            $all = Db::fetchAll(
                'SELECT * FROM ' . Db::table('chat_niuniu_shares')
                . ' WHERE round_id=? AND user_id=? ORDER BY claim_seq ASC, id ASC',
                [$roundId, $userId]
            );
            $row = $this->publicShare($all[0] ?? $first, true);
            $row['share_count'] = count($all);
            $row['weight'] = count($all);
            $winSum = 0.0;
            foreach ($all as $s) {
                $winSum += (float)$s['win_amount'];
            }
            $row['win_amount'] = round($winSum, 4);
            $row['claimed'] = true;
            $row['claimed_at'] = $now;
            return [
                'round'            => $this->publicRound($round, $userId),
                'share'            => $row,
                'shares'           => [$row],
                'remain_unclaimed' => 0,
                'done'             => true,
                'note'             => '单结果：按领取顺序从 hash 取尾数；明细按真实领取时间排序',
            ];
        }

        // 普通：一次一包
        if ($shareId > 0) {
            $share = Db::fetch(
                'SELECT * FROM ' . Db::table('chat_niuniu_shares')
                . ' WHERE id=? AND round_id=? AND user_id=? LIMIT 1',
                [$shareId, $roundId, $userId]
            );
            if (!$share) {
                throw new \RuntimeException('份额不存在');
            }
            if ((int)$share['claimed'] === 1) {
                throw new \RuntimeException('该包已领取');
            }
        } else {
            $share = Db::fetch(
                'SELECT * FROM ' . Db::table('chat_niuniu_shares')
                . ' WHERE round_id=? AND user_id=? AND claimed=0 ORDER BY id ASC LIMIT 1',
                [$roundId, $userId]
            );
            if (!$share) {
                throw new \RuntimeException('你已领完本局红宝');
            }
        }

        $share = $this->claimSharesWithHash($round, [(int)$share['id']], $userId, $now, false);
        $left = Db::fetch(
            'SELECT COUNT(*) AS c FROM ' . Db::table('chat_niuniu_shares')
            . ' WHERE round_id=? AND user_id=? AND claimed=0',
            [$roundId, $userId]
        );
        $remain = (int)($left['c'] ?? 0);
        $row = $this->publicShare($share, true);
        $row['share_count'] = 1;
        $row['weight'] = 1;

        return [
            'round'            => $this->publicRound($round, $userId),
            'share'            => $row,
            'shares'           => [$row],
            'remain_unclaimed' => $remain,
            'done'             => $remain <= 0,
            'note'             => '按领取顺序从 hash 取尾数入账；明细按真实领取时间排序',
        ];
    }

    /**
     * 锁定局后按领取序号从波场 hash 派生尾数并标记领取（真实 claimed_at）
     * @param int[] $shareIds
     */
    protected function claimSharesWithHash(array $round, array $shareIds, $userId, $now, $singleModePacketOnce)
    {
        $roundId = (int)$round['id'];
        $userId = (int)$userId;
        $now = (int)$now;
        $shareIds = array_values(array_filter(array_map('intval', $shareIds)));
        if (!$shareIds) {
            throw new \RuntimeException('份额不存在');
        }

        Db::begin();
        try {
            // 锁局，避免并发领取抢同一 claim_seq
            $locked = Db::fetch(
                'SELECT id, tron_block_id, drand_randomness FROM ' . Db::table('chat_niuniu_rounds')
                . ' WHERE id=? FOR UPDATE',
                [$roundId]
            );
            if (!$locked) {
                throw new \RuntimeException('对局不存在');
            }
            $seed = $this->roundHashSeed(array_merge($round, $locked ?: []));

            $rows = Db::fetchAll(
                'SELECT * FROM ' . Db::table('chat_niuniu_shares')
                . ' WHERE round_id=? AND user_id=? AND id IN (' . implode(',', $shareIds) . ') FOR UPDATE',
                [$roundId, $userId]
            );
            if (count($rows) !== count($shareIds)) {
                throw new \RuntimeException('份额不存在');
            }
            foreach ($rows as $r) {
                if ((int)$r['claimed'] === 1) {
                    throw new \RuntimeException('该包已领取');
                }
            }

            // 旧局：购入结束已写尾数 → 只记真实领取时间，不再改尾数
            $legacyTail = (string)($rows[0]['tail_digits'] ?? '');
            if ($legacyTail !== '') {
                $affected = Db::exec(
                    'UPDATE ' . Db::table('chat_niuniu_shares')
                    . ' SET claimed=1, claimed_at=?, updatetime=? WHERE round_id=? AND user_id=?'
                    . ' AND id IN (' . implode(',', $shareIds) . ') AND claimed=0',
                    [$now, $now, $roundId, $userId]
                );
                if ((int)$affected <= 0) {
                    throw new \RuntimeException('该包已领取');
                }
                // 入账与 claimed 同事务：失败整单回滚，避免「已领却未到账」无法重试
                $firstRow = null;
                foreach ($rows as $r) {
                    if ((int)$r['id'] === (int)$shareIds[0]) {
                        $firstRow = $r;
                        break;
                    }
                }
                if (!$firstRow) {
                    $firstRow = $rows[0];
                }
                $firstRow['tail_digits'] = $legacyTail;
                $this->creditPacketOnClaim($firstRow, $roundId);
                if ($singleModePacketOnce && count($shareIds) > 1) {
                    $pktAmt = self::packetAmountFromTail($legacyTail);
                    foreach ($shareIds as $i => $sid) {
                        if ($i === 0) {
                            continue;
                        }
                        Db::exec(
                            'UPDATE ' . Db::table('chat_niuniu_shares')
                            . ' SET amount=?, packet_paid=1, updatetime=? WHERE id=? AND IFNULL(packet_paid,0)=0',
                            [sprintf('%.2f', $pktAmt), $now, $sid]
                        );
                    }
                }
                Db::commit();
            } else {
                if ($seed === '') {
                    throw new \RuntimeException('波场哈希不可用，请稍后重试');
                }
                $seqRow = Db::fetch(
                    'SELECT COALESCE(MAX(claim_seq),0) AS m FROM ' . Db::table('chat_niuniu_shares')
                    . ' WHERE round_id=?',
                    [$roundId]
                );
                $seq = (int)($seqRow['m'] ?? 0) + 1;
                $meta = self::calcNiu(DrandClient::deriveTail($seed, $seq, 'round:' . $roundId . ':claim'));
                $pkt = self::packetAmountFromTail($meta['tail']);
                $affected = 0;
                foreach ($shareIds as $sid) {
                    $affected += (int)Db::exec(
                        'UPDATE ' . Db::table('chat_niuniu_shares')
                        . ' SET claimed=1, claimed_at=?, claim_seq=?,'
                        . ' tail_digits=?, digit_a=?, digit_b=?, digit_sum=?, niu_point=?, niu_tier=?, niu_label=?,'
                        . ' amount=?, updatetime=?'
                        . ' WHERE id=? AND claimed=0',
                        [
                            $now, $seq,
                            $meta['tail'], $meta['a'], $meta['b'], $meta['sum'],
                            $meta['point'], $meta['tier'], $meta['label'],
                            sprintf('%.2f', $pkt), $now, $sid,
                        ]
                    );
                }
                if ($affected <= 0) {
                    throw new \RuntimeException('该包已领取');
                }
                $firstRow = [
                    'id' => $shareIds[0],
                    'user_id' => $userId,
                    'tail_digits' => $meta['tail'],
                    'amount' => $pkt,
                    'packet_paid' => 0,
                ];
                $this->creditPacketOnClaim($firstRow, $roundId);
                if ($singleModePacketOnce && count($shareIds) > 1) {
                    foreach ($shareIds as $i => $sid) {
                        if ($i === 0) {
                            continue;
                        }
                        Db::exec(
                            'UPDATE ' . Db::table('chat_niuniu_shares')
                            . ' SET amount=?, packet_paid=1, updatetime=? WHERE id=? AND IFNULL(packet_paid,0)=0',
                            [sprintf('%.2f', $pkt), $now, $sid]
                        );
                    }
                }
                Db::commit();
            }
        } catch (\Throwable $e) {
            Db::rollBack();
            throw $e;
        }

        $first = Db::fetch(
            'SELECT * FROM ' . Db::table('chat_niuniu_shares') . ' WHERE id=? LIMIT 1',
            [$shareIds[0]]
        );
        if (!$first) {
            throw new \RuntimeException('份额不存在');
        }

        return $first;
    }

    /** 局种子：tron_block_id 优先，否则 drand_randomness */
    protected function roundHashSeed(array $round)
    {
        $seed = strtolower(trim((string)($round['tron_block_id'] ?? '')));
        if ($seed === '') {
            $seed = strtolower(trim((string)($round['drand_randomness'] ?? '')));
        }
        return $seed;
    }

    public function detail($roundId, $userId = 0)
    {
        $round = $this->getRound((int)$roundId);
        if (!$round) {
            return null;
        }
        $userId = (int)$userId;
        $status = (int)$round['status'];
        $mode = $this->normalizeMode($round['game_mode'] ?? self::MODE_NORMAL);
        // 领取阶段起：已领按真实领取时间排序；未领排后且不出结果
        $shares = Db::fetchAll(
            'SELECT * FROM ' . Db::table('chat_niuniu_shares')
            . ' WHERE round_id=? ORDER BY'
            . ' CASE WHEN claimed=1 THEN 0 ELSE 1 END ASC,'
            . ' claimed_at ASC, claim_seq ASC, id ASC',
            [(int)$roundId]
        );
        $mine = [];
        $all = [];
        $seenUser = [];
        foreach ($shares as $s) {
            $uid = (int)$s['user_id'];
            $isMine = $userId > 0 && $uid === $userId;
            // 仅已领取且已有尾数才出结果；购入结束不预生成尾数
            $reveal = ((int)$s['claimed'] === 1) && (string)($s['tail_digits'] ?? '') !== '';
            // 单结果：每人只保留领取序中的第一行
            if ($mode === self::MODE_SINGLE) {
                if (isset($seenUser[$uid])) {
                    $idx = $seenUser[$uid];
                    $all[$idx]['weight'] = ((int)($all[$idx]['weight'] ?? 1)) + 1;
                    $all[$idx]['share_count'] = ((int)($all[$idx]['share_count'] ?? 1)) + 1;
                    if ($reveal) {
                        $all[$idx]['win_amount'] = round(
                            (float)($all[$idx]['win_amount'] ?? 0) + (float)$s['win_amount'],
                            4
                        );
                    }
                    if ($isMine && isset($mine[$uid])) {
                        $mine[$uid] = $all[$idx];
                    }
                    continue;
                }
            }
            $row = $this->publicShare($s, $reveal);
            $row['weight'] = 1;
            $row['share_count'] = 1;
            if ($mode === self::MODE_SINGLE) {
                $seenUser[$uid] = count($all);
            }
            $all[] = $row;
            if ($isMine) {
                $mine[$uid] = $row;
            }
        }
        return [
            'round'  => $this->publicRound($round, $userId),
            'mine'   => array_values($mine),
            'shares' => $status >= self::STATUS_CLAIMING ? $all : [],
            'rule'   => $this->ruleText((int)$round['group_id']),
        ];
    }

    /** cron：结束购入 / 强制结算 / 连开续局 */
    public function tick($limit = 20)
    {
        $limit = max(1, (int)$limit);
        $now = time();
        $ended = 0;
        $settled = 0;
        $restarted = 0;

        $buyRows = Db::fetchAll(
            'SELECT id FROM ' . Db::table('chat_niuniu_rounds')
            . ' WHERE status=? AND buy_end_at<=? ORDER BY id ASC LIMIT ' . $limit,
            [self::STATUS_BUYING, $now]
        );
        foreach ($buyRows as $r) {
            try {
                $this->closeBuy((int)$r['id']);
                $ended++;
            } catch (\Throwable $e) {
                error_log('[NIUNIU][closeBuy] #' . $r['id'] . ' ' . $e->getMessage());
            }
        }

        $settleRows = Db::fetchAll(
            'SELECT id FROM ' . Db::table('chat_niuniu_rounds')
            . ' WHERE status=? AND claim_end_at<=? ORDER BY id ASC LIMIT ' . $limit,
            [self::STATUS_CLAIMING, $now]
        );
        foreach ($settleRows as $r) {
            try {
                $this->settle((int)$r['id']);
                $settled++;
            } catch (\Throwable $e) {
                error_log('[NIUNIU][settle] #' . $r['id'] . ' ' . $e->getMessage());
            }
        }

        $restarted = $this->tickLoopRestarts($limit);

        return ['closed_buy' => $ended, 'settled' => $settled, 'restarted' => $restarted];
    }

    /** 连开：到期自动开下一局 */
    public function tickLoopRestarts($limit = 20)
    {
        $limit = max(1, (int)$limit);
        $n = 0;
        try {
            $rows = Db::fetchAll(
                'SELECT id, niuniu_loop_starter FROM ' . Db::table('chat_groups')
                . ' WHERE niuniu_enabled=1 AND niuniu_loop=1 ORDER BY id ASC LIMIT 80'
            );
        } catch (\Throwable $e) {
            return 0;
        }
        foreach ($rows ?: [] as $g) {
            if ($n >= $limit) {
                break;
            }
            $gid = (int)$g['id'];
            if (!$this->isGroupEnabled($gid)) {
                continue;
            }
            $busy = Db::fetch(
                'SELECT id FROM ' . Db::table('chat_niuniu_rounds')
                . ' WHERE group_id=? AND status IN (?,?) LIMIT 1',
                [$gid, self::STATUS_BUYING, self::STATUS_CLAIMING]
            );
            if ($busy) {
                continue;
            }
            $nextAt = $this->getLoopNextAt($gid);
            if ($nextAt > 0 && $nextAt > time()) {
                continue;
            }
            // 尚无 nextAt：说明刚关局未排程，或旧数据；给个短等待避免狂刷
            if ($nextAt <= 0) {
                $this->scheduleLoopNext($gid, 2);
                continue;
            }
            $starter = (int)($g['niuniu_loop_starter'] ?? 0);
            $mode = (int)($g['niuniu_loop_mode'] ?? self::MODE_NORMAL);
            try {
                $this->start($starter, $gid, [
                    'trusted_loop' => true,
                    'game_mode'    => $mode,
                ]);
                $n++;
            } catch (\Throwable $e) {
                error_log('[NIUNIU][loopRestart] g' . $gid . ' ' . $e->getMessage());
                $this->scheduleLoopNext($gid, 15);
            }
        }
        return $n;
    }

    public function closeBuy($roundId)
    {
        $roundId = (int)$roundId;
        $round = $this->getRound($roundId);
        if (!$round || (int)$round['status'] !== self::STATUS_BUYING) {
            return false;
        }

        $shareCount = (int)$round['share_count'];
        $groupId = (int)$round['group_id'];
        $now = time();

        // 0 份作废
        if ($shareCount <= 0) {
            Db::exec(
                'UPDATE ' . Db::table('chat_niuniu_rounds')
                . ' SET status=?, settle_at=?, updatetime=? WHERE id=? AND status=?',
                [self::STATUS_VOID, $now, $now, $roundId, self::STATUS_BUYING]
            );
            $this->clearMuteFlag($groupId);
            $round = $this->getRound($roundId);
            $this->pushCard($round, 'void', $this->robotOrStarter($round));
            $this->onRoundFinished($groupId, (int)$round['starter_user_id']);
            return true;
        }

        $c = $this->config();
        try {
            $proof = $this->resolveTronProof($round);
        } catch (\Throwable $eProof) {
            $proof = ['block_num' => 0, 'randomness' => '', 'url' => ''];
            error_log('[NIUNIU] closeBuy tron fail round=' . $roundId . ' ' . $eProof->getMessage());
        }
        $seed = strtolower(trim((string)($proof['randomness'] ?? '')));
        if ($seed === '') {
            // 购入结束已久仍无目标块哈希：全额退款关局，避免永久卡 BUYING
            $buyEnd = (int)($round['buy_end_at'] ?? 0);
            $grace = 600; // 10 分钟
            if ($buyEnd > 0 && ($now - $buyEnd) >= $grace) {
                error_log('[NIUNIU][ALERT] force refund stuck BUYING round=' . $roundId
                    . ' overdue=' . ($now - $buyEnd) . 's');
                return $this->forceRefundBuyingRound($round, $now);
            }
            throw new \RuntimeException('波场哈希不可用，请稍后重试');
        }

        $pool = round((float)$round['pool_amount'], 2);
        $feeRate = (float)$round['fee_rate'];
        $fee = round($pool * $feeRate, 2);
        $dist = round($pool - $fee, 2);
        $claimSec = max(15, (int)$round['claim_seconds']);
        $claimEnd = $now + $claimSec;

        Db::begin();
        try {
            $ok = Db::exec(
                'UPDATE ' . Db::table('chat_niuniu_rounds')
                . ' SET status=?, claim_end_at=?, fee_amount=?, distributable=?,'
                . ' drand_round=?, drand_randomness=?, drand_url=?,'
                . ' tron_block_num=?, tron_block_id=?, tron_status=?, updatetime=?'
                . ' WHERE id=? AND status=?',
                [
                    self::STATUS_CLAIMING, $claimEnd, sprintf('%.2f', $fee), sprintf('%.2f', $dist),
                    (int)$proof['block_num'], $seed, (string)$proof['url'],
                    (int)$proof['block_num'], $seed, TronFair::STATUS_DONE,
                    $now, $roundId, self::STATUS_BUYING,
                ]
            );
            if ($ok <= 0) {
                // 无 tron 列时回退只写 drand_*（种子仍为波场 hash）
                $ok = Db::exec(
                    'UPDATE ' . Db::table('chat_niuniu_rounds')
                    . ' SET status=?, claim_end_at=?, fee_amount=?, distributable=?,'
                    . ' drand_round=?, drand_randomness=?, drand_url=?, updatetime=?'
                    . ' WHERE id=? AND status=?',
                    [
                        self::STATUS_CLAIMING, $claimEnd, sprintf('%.2f', $fee), sprintf('%.2f', $dist),
                        (int)$proof['block_num'], $seed, (string)$proof['url'],
                        $now, $roundId, self::STATUS_BUYING,
                    ]
                );
            }
            if ($ok <= 0) {
                Db::rollBack();
                return false;
            }

            // 购入结束只绑定波场哈希进入领取期；尾数在真实领取时按领取序号派生，不在此预生成

            // 平台手续费入账
            $platformUid = (int)$round['platform_user_id'] ?: (int)$c['platform_user_id'];
            if ($fee > 0 && $platformUid > 0) {
                $this->wallet->change($platformUid, $fee, 'niuniu_fee_in', '尾数牛牛手续费 #' . $roundId, [
                    'biz_no'   => 'niuniu_fee_' . $roundId,
                    'ref_type' => 'niuniu_round',
                    'ref_id'   => $roundId,
                ]);
            }

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            throw $e;
        }

        $this->clearMuteFlag($groupId);
        $round = $this->getRound($roundId);
        $msg = $this->pushCard($round, 'claim', $this->robotOrStarter($round));
        if ($msg) {
            // 与 buy_msg_id 同一条卡片，便于追踪
            $mid = (int)$msg['id'];
            Db::exec(
                'UPDATE ' . Db::table('chat_niuniu_rounds')
                . ' SET claim_msg_id=?, buy_msg_id=IF(buy_msg_id>0,buy_msg_id,?), updatetime=? WHERE id=?',
                [$mid, $mid, time(), $roundId]
            );
        }
        return true;
    }

    public function settle($roundId)
    {
        $roundId = (int)$roundId;
        $round = $this->getRound($roundId);
        if (!$round || (int)$round['status'] !== self::STATUS_CLAIMING) {
            return false;
        }

        $lockKey = RedisClient::key('nn:' . $roundId . ':settle_lock');
        $gotLock = false;
        try {
            $r = RedisClient::conn();
            if (!$r->setnx($lockKey, (string)time())) {
                return false;
            }
            $r->expire($lockKey, 90);
            $gotLock = true;
        } catch (\Throwable $e) {
            // Redis 失败时仍靠 status CAS
        }

        try {
            return $this->settleLocked($roundId, $round);
        } finally {
            if ($gotLock) {
                try {
                    RedisClient::conn()->del($lockKey);
                } catch (\Throwable $e) {
                }
            }
        }
    }

    /** @param array $round */
    protected function settleLocked($roundId, array $round)
    {
        $roundId = (int)$roundId;
        // 领取期结束：未领的统一自动领取（真人/机器人），再结算开奖
        $this->autoClaimAllUnclaimed($roundId);

        // 仍有未领/无尾数份额：禁止分池（否则会被当成低流，奖池错分）
        $pending = Db::fetch(
            'SELECT COUNT(*) AS c FROM ' . Db::table('chat_niuniu_shares')
            . ' WHERE round_id=? AND (claimed=0 OR IFNULL(tail_digits,\'\')=\'\')',
            [$roundId]
        );
        if ((int)($pending['c'] ?? 0) > 0) {
            error_log('[NIUNIU][SETTLE] defer round=' . $roundId
                . ' unclaimed_or_no_tail=' . (int)$pending['c']);
            return false;
        }

        $shares = Db::fetchAll(
            'SELECT * FROM ' . Db::table('chat_niuniu_shares') . ' WHERE round_id=? ORDER BY'
            . self::hashOrderSql(),
            [$roundId]
        );
        $niuniu = [];
        $secondary = [];
        $low = [];
        foreach ($shares as $s) {
            $tier = (int)$s['niu_tier'];
            if ($tier === self::TIER_NIUNIU) {
                $niuniu[] = $s;
            } elseif ($tier === self::TIER_SECONDARY) {
                $secondary[] = $s;
            } else {
                $low[] = $s;
            }
        }

        $dist = round((float)$round['distributable'], 2);
        $nnRate = (float)$round['niuniu_rate'];
        $secRate = (float)$round['secondary_rate'];
        $nnCount = count($niuniu);
        $secCount = count($secondary);
        $lowCount = count($low);
        $now = time();

        // 情况4：全低流局
        if ($nnCount === 0 && $secCount === 0) {
            return $this->settleRefund($round, $shares, $now);
        }

        $case = 1;
        $nnPool = round($dist * $nnRate, 2);
        $secPool = round($dist - $nnPool, 2);
        if ($nnCount === 0) {
            // 情况2：无牛牛 → 次级拿全部
            $case = 2;
            $nnPool = 0.0;
            $secPool = $dist;
        } elseif ($secCount === 0) {
            // 情况3：无次级 → 牛牛拿全部
            $case = 3;
            $nnPool = $dist;
            $secPool = 0.0;
        }

        $nnPer = $nnCount > 0 ? round($nnPool / $nnCount, 4) : 0.0;
        $secPer = $secCount > 0 ? round($secPool / $secCount, 4) : 0.0;

        Db::begin();
        try {
            $ok = Db::exec(
                'UPDATE ' . Db::table('chat_niuniu_rounds')
                . ' SET status=?, settle_at=?, settle_case=?, niuniu_pool=?, secondary_pool=?, niuniu_per_share=?, secondary_per_share=?,'
                . ' niuniu_share_count=?, secondary_share_count=?, low_share_count=?, updatetime=?'
                . ' WHERE id=? AND status=?',
                [
                    self::STATUS_SETTLED, $now, $case,
                    sprintf('%.2f', $nnPool), sprintf('%.2f', $secPool),
                    sprintf('%.4f', $nnPer), sprintf('%.4f', $secPer),
                    $nnCount, $secCount, $lowCount, $now,
                    $roundId, self::STATUS_CLAIMING,
                ]
            );
            if ($ok <= 0) {
                Db::rollBack();
                return false;
            }

            // 同一用户多份奖金合并为一条资金记录（如买 1+2 份 → 开奖只记一次）
            $winBucket = [];
            foreach ($niuniu as $s) {
                $this->payWin($s, $nnPer, $roundId, $now, $winBucket);
            }
            foreach ($secondary as $s) {
                $this->payWin($s, $secPer, $roundId, $now, $winBucket);
            }
            foreach ($low as $s) {
                Db::exec(
                    'UPDATE ' . Db::table('chat_niuniu_shares')
                    . ' SET win_amount=0, paid=1, updatetime=? WHERE id=? AND IFNULL(paid,0)=0',
                    [$now, (int)$s['id']]
                );
            }
            $this->flushWinBucket($winBucket, $roundId);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            throw $e;
        }

        $round = $this->getRound($roundId);
        $msg = $this->pushCard($round, 'result', $this->robotOrStarter($round));
        if ($msg) {
            $mid = (int)$msg['id'];
            Db::exec(
                'UPDATE ' . Db::table('chat_niuniu_rounds')
                . ' SET result_msg_id=?, buy_msg_id=IF(buy_msg_id>0,buy_msg_id,?), updatetime=? WHERE id=?',
                [$mid, $mid, time(), $roundId]
            );
        }
        $this->onRoundFinished((int)$round['group_id'], (int)$round['starter_user_id']);
        return true;
    }

    protected function settleRefund(array $round, array $shares, $now)
    {
        $roundId = (int)$round['id'];
        // 手续费已在 closeBuy 入账；流局退回 = 可发放奖金（每人按份退 share_price * 97%?）
        // 规格：扣除3%手续费，剩余积分原路退回全部参与用户
        // 即每份退回 share_price * (1-fee_rate) = amount * distributable/pool
        $pool = round((float)$round['pool_amount'], 2);
        $dist = round((float)$round['distributable'], 2);
        $per = $pool > 0 ? round($dist / max(1, count($shares)), 2) : 0.0;

        Db::begin();
        try {
            $ok = Db::exec(
                'UPDATE ' . Db::table('chat_niuniu_rounds')
                . ' SET status=?, settle_at=?, settle_case=4, niuniu_pool=0, secondary_pool=0,'
                . ' niuniu_share_count=0, secondary_share_count=0, low_share_count=?, updatetime=?'
                . ' WHERE id=? AND status=?',
                [self::STATUS_REFUND, $now, count($shares), $now, $roundId, self::STATUS_CLAIMING]
            );
            if ($ok <= 0) {
                Db::rollBack();
                return false;
            }
            $refundBucket = [];
            foreach ($shares as $s) {
                $uid = (int)$s['user_id'];
                $sid = (int)$s['id'];
                $claimed = Db::exec(
                    'UPDATE ' . Db::table('chat_niuniu_shares')
                    . ' SET win_amount=?, paid=1, updatetime=? WHERE id=? AND IFNULL(paid,0)=0',
                    [sprintf('%.4f', $per), $now, $sid]
                );
                if ((int)$claimed <= 0) {
                    continue;
                }
                if ($per > 0 && $uid > 0) {
                    if (!isset($refundBucket[$uid])) {
                        $refundBucket[$uid] = ['credit' => 0.0, 'count' => 0];
                    }
                    $refundBucket[$uid]['credit'] += round($per, 2);
                    $refundBucket[$uid]['count']++;
                }
            }
            foreach ($refundBucket as $uid => $b) {
                $credit = round((float)$b['credit'], 2);
                $n = (int)$b['count'];
                if ($credit <= 0 || $uid <= 0) {
                    continue;
                }
                $remark = '尾数牛牛流局退回 #' . $roundId . ($n > 1 ? ('×' . $n) : '');
                $this->wallet->change((int)$uid, $credit, 'niuniu_refund', $remark, [
                    'biz_no'   => 'niuniu_refund_r' . $roundId . '_u' . (int)$uid,
                    'ref_type' => 'niuniu_round',
                    'ref_id'   => $roundId,
                ]);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            throw $e;
        }

        $round = $this->getRound($roundId);
        $this->pushCard($round, 'refund', $this->robotOrStarter($round));
        $this->onRoundFinished((int)$round['group_id'], (int)$round['starter_user_id']);
        return true;
    }

    /**
     * 标记份额奖金；若传入 $bucket 则先按用户汇总，再由 flushWinBucket 一次入账。
     * @param array|null $bucket uid => ['credit'=>float,'count'=>int]
     */
    protected function payWin(array $share, $amount, $roundId, $now, &$bucket = null)
    {
        $amount = round((float)$amount, 4);
        $sid = (int)$share['id'];
        $uid = (int)$share['user_id'];
        $claimed = Db::exec(
            'UPDATE ' . Db::table('chat_niuniu_shares')
            . ' SET win_amount=?, paid=1, updatetime=? WHERE id=? AND IFNULL(paid,0)=0',
            [sprintf('%.4f', $amount), $now, $sid]
        );
        if ((int)$claimed <= 0) {
            return;
        }
        if ($amount <= 0 || $uid <= 0) {
            return;
        }
        // 入账按分取两位；多份时与原先「每份各记一笔再相加」总额一致
        $credit = round($amount, 2);
        if ($credit <= 0) {
            return;
        }
        if (is_array($bucket)) {
            if (!isset($bucket[$uid])) {
                $bucket[$uid] = ['credit' => 0.0, 'count' => 0];
            }
            $bucket[$uid]['credit'] += $credit;
            $bucket[$uid]['count']++;
            return;
        }
        $this->wallet->change($uid, $credit, 'niuniu_win', '尾数牛牛奖金 #' . $roundId, [
            'biz_no'   => 'niuniu_win_' . $sid,
            'ref_type' => 'niuniu_share',
            'ref_id'   => $sid,
        ]);
    }

    /** 将 payWin 汇总的奖金按用户写一条资金记录 */
    protected function flushWinBucket(array $bucket, $roundId)
    {
        $roundId = (int)$roundId;
        foreach ($bucket as $uid => $b) {
            $uid = (int)$uid;
            $credit = round((float)($b['credit'] ?? 0), 2);
            $n = (int)($b['count'] ?? 0);
            if ($uid <= 0 || $credit <= 0) {
                continue;
            }
            $remark = '尾数牛牛奖金 #' . $roundId . ($n > 1 ? ('×' . $n) : '');
            $this->wallet->change($uid, $credit, 'niuniu_win', $remark, [
                'biz_no'   => 'niuniu_win_r' . $roundId . '_u' . $uid,
                'ref_type' => 'niuniu_round',
                'ref_id'   => $roundId,
            ]);
        }
    }

    public static function calcNiu($tail)
    {
        $tail = str_pad(preg_replace('/\D/', '', (string)$tail), 2, '0', STR_PAD_LEFT);
        $tail = substr($tail, -2);
        $a = (int)$tail[0];
        $b = (int)$tail[1];
        $sum = $a + $b;
        $point = $sum % 10;
        if ($point === 0) {
            return [
                'tail' => $tail, 'a' => $a, 'b' => $b, 'sum' => $sum,
                'point' => 0, 'tier' => self::TIER_NIUNIU, 'label' => '牛牛',
            ];
        }
        if ($point >= 7) {
            return [
                'tail' => $tail, 'a' => $a, 'b' => $b, 'sum' => $sum,
                'point' => $point, 'tier' => self::TIER_SECONDARY, 'label' => '牛' . $point,
            ];
        }
        return [
            'tail' => $tail, 'a' => $a, 'b' => $b, 'sum' => $sum,
            'point' => $point, 'tier' => self::TIER_LOW, 'label' => '牛' . $point,
        ];
    }

    /** 尾数 02 → 红包金额 0.02 */
    public static function packetAmountFromTail($tail)
    {
        $tail = str_pad(preg_replace('/\D/', '', (string)$tail), 2, '0', STR_PAD_LEFT);
        $tail = substr($tail, -2);
        return round(((int)$tail) / 100, 2);
    }

    /**
     * 领取期结束时：未领取份额按份序统一自动领取（含机器人），走与手动领取同一套 hash 赋值
     * @return int 新领取份数
     */
    public function autoClaimAllUnclaimed($roundId)
    {
        $roundId = (int)$roundId;
        $round = $this->getRound($roundId);
        if (!$round) {
            return 0;
        }
        $status = (int)$round['status'];
        if ($status !== self::STATUS_CLAIMING && $status !== self::STATUS_SETTLED) {
            return 0;
        }
        $mode = $this->normalizeMode($round['game_mode'] ?? self::MODE_NORMAL);
        $n = 0;

        if ($mode === self::MODE_SINGLE) {
            $users = Db::fetchAll(
                'SELECT user_id, MIN(id) AS mid FROM ' . Db::table('chat_niuniu_shares')
                . ' WHERE round_id=? AND claimed=0 GROUP BY user_id ORDER BY mid ASC',
                [$roundId]
            );
            foreach ($users as $u) {
                $uid = (int)$u['user_id'];
                if ($uid <= 0) {
                    continue;
                }
                try {
                    $this->claim($uid, $roundId, 0);
                    $n++;
                } catch (\Throwable $e) {
                    error_log('[NIUNIU][autoClaim][single] u' . $uid . ' ' . $e->getMessage());
                }
            }
        } else {
            $rows = Db::fetchAll(
                'SELECT id, user_id FROM ' . Db::table('chat_niuniu_shares')
                . ' WHERE round_id=? AND claimed=0 ORDER BY id ASC',
                [$roundId]
            );
            foreach ($rows as $s) {
                try {
                    $this->claim((int)$s['user_id'], $roundId, (int)$s['id']);
                    $n++;
                } catch (\Throwable $e) {
                    error_log('[NIUNIU][autoClaim] s' . (int)$s['id'] . ' ' . $e->getMessage());
                }
            }
        }

        return $n;
    }

    /** hash/尾数排序 SQL 片段（金额/尾数升序） */
    public static function hashOrderSql($alias = '')
    {
        $p = $alias !== '' ? (rtrim($alias, '.') . '.') : '';
        return ' CAST(' . $p . 'tail_digits AS UNSIGNED) ASC, ' . $p . 'id ASC';
    }

    /** 领取时：尾数金额入账（02→0.02）；packet_paid CAS 防并发双发 */
    protected function creditPacketOnClaim(array $share, $roundId)
    {
        $sid = (int)$share['id'];
        $uid = (int)$share['user_id'];
        if ($sid <= 0 || $uid <= 0) {
            return 0.0;
        }
        $fresh = Db::fetch(
            'SELECT id, user_id, amount, tail_digits, packet_paid FROM ' . Db::table('chat_niuniu_shares')
            . ' WHERE id=? LIMIT 1',
            [$sid]
        );
        if (!$fresh) {
            return 0.0;
        }
        if ((int)($fresh['packet_paid'] ?? 0) === 1) {
            return round((float)($fresh['amount'] ?? 0), 2);
        }
        $amt = self::packetAmountFromTail($fresh['tail_digits'] ?? '');
        // 先占位，避免并发双入账；入账失败再回滚标记
        $claimedPay = Db::exec(
            'UPDATE ' . Db::table('chat_niuniu_shares')
            . ' SET amount=?, packet_paid=1, updatetime=? WHERE id=? AND IFNULL(packet_paid,0)=0',
            [sprintf('%.2f', $amt), time(), $sid]
        );
        if ((int)$claimedPay <= 0) {
            $again = Db::fetch(
                'SELECT amount FROM ' . Db::table('chat_niuniu_shares') . ' WHERE id=? LIMIT 1',
                [$sid]
            );
            return round((float)($again['amount'] ?? 0), 2);
        }
        if ($amt <= 0) {
            return 0.0;
        }
        try {
            $this->wallet->change($uid, $amt, 'niuniu_packet', '尾数牛牛红包 #' . (int)$roundId, [
                'biz_no'   => 'niuniu_pkt_' . $sid,
                'ref_type' => 'niuniu_share',
                'ref_id'   => $sid,
            ]);
        } catch (\Throwable $e) {
            Db::exec(
                'UPDATE ' . Db::table('chat_niuniu_shares')
                . ' SET packet_paid=0, updatetime=? WHERE id=? AND packet_paid=1',
                [time(), $sid]
            );
            error_log('[NIUNIU][packet] s' . $sid . ' ' . $e->getMessage());
            throw new \RuntimeException('红包入账失败，请重试');
        }
        return $amt;
    }

    public function getRound($id)
    {
        return Db::fetch('SELECT * FROM ' . Db::table('chat_niuniu_rounds') . ' WHERE id=? LIMIT 1', [(int)$id]);
    }

    public function publicRound(array $round, $userId = 0)
    {
        $status = (int)$round['status'];
        $now = time();
        $remainBuy = max(0, (int)$round['buy_end_at'] - $now);
        $remainClaim = max(0, (int)$round['claim_end_at'] - $now);
        $myCount = 0;
        $myClaimed = false;
        if ($userId > 0) {
            $row = Db::fetch(
                'SELECT COUNT(*) AS c, SUM(CASE WHEN claimed=1 THEN 1 ELSE 0 END) AS claimed_c'
                . ' FROM ' . Db::table('chat_niuniu_shares') . ' WHERE round_id=? AND user_id=?',
                [(int)$round['id'], (int)$userId]
            );
            $myCount = (int)($row['c'] ?? 0);
            $myClaimedC = (int)($row['claimed_c'] ?? 0);
            $myClaimed = $myCount > 0 && $myClaimedC >= $myCount;
            $myUnclaimed = max(0, $myCount - $myClaimedC);
        } else {
            $myUnclaimed = 0;
        }
        $tronNum = (int)($round['tron_block_num'] ?? 0);
        $tronId = strtolower(trim((string)($round['tron_block_id'] ?? '')));
        $tronStatus = (int)($round['tron_status'] ?? 0);
        $isTron = $tronNum > 0 || $tronId !== '';
        $tronscan = $tronNum > 0
            ? ('https://tronscan.org/#/block/' . $tronNum)
            : ($tronId !== '' ? ('https://tronscan.org/#/block/' . $tronId) : '');
        $out = [
            'id'              => (int)$round['id'],
            'group_id'        => (int)$round['group_id'],
            'status'          => $status,
            'status_label'    => $this->statusLabel($status),
            'game_mode'       => $this->normalizeMode($round['game_mode'] ?? self::MODE_NORMAL),
            'game_mode_label' => $this->modeLabel($round['game_mode'] ?? self::MODE_NORMAL),
            'share_price'     => round((float)$round['share_price'], 2),
            'share_count'     => (int)$round['share_count'],
            'pool_amount'     => round((float)$round['pool_amount'], 2),
            'fee_amount'      => round((float)$round['fee_amount'], 2),
            'distributable'   => round((float)$round['distributable'], 2),
            'fee_rate'        => (float)$round['fee_rate'],
            'niuniu_rate'     => (float)$round['niuniu_rate'],
            'secondary_rate'  => (float)$round['secondary_rate'],
            'buy_end_at'      => (int)$round['buy_end_at'],
            'claim_end_at'    => (int)$round['claim_end_at'],
            'remain_buy'      => $remainBuy,
            'remain_claim'    => $remainClaim,
            'proof_type'      => $isTron ? 'tron' : 'drand',
            'drand_round'     => (int)$round['drand_round'],
            'drand_label'     => $isTron
                ? ('tron-#' . ($tronNum ?: (int)$round['drand_round']))
                : ('drand-#' . (int)$round['drand_round']),
            'drand_url'       => $tronscan !== '' ? $tronscan : (string)$round['drand_url'],
            'tron_block_num'  => $tronNum,
            // 领取阶段不公开 block hash，避免提前复算全部尾数；结算后开放校验
            'tron_block_id'   => $status >= self::STATUS_SETTLED ? $tronId : '',
            'tron_status'     => $tronStatus,
            'tronscan_url'    => $tronscan,
            'my_share_count'     => $myCount,
            'my_claimed'         => $myClaimed,
            'my_unclaimed_count' => (int)$myUnclaimed,
            'settle_case'     => (int)$round['settle_case'],
            'niuniu_pool'     => round((float)$round['niuniu_pool'], 2),
            'secondary_pool'  => round((float)$round['secondary_pool'], 2),
            'niuniu_per_share'=> round((float)$round['niuniu_per_share'], 4),
            'secondary_per_share' => round((float)$round['secondary_per_share'], 4),
            'niuniu_share_count' => (int)$round['niuniu_share_count'],
            'secondary_share_count' => (int)$round['secondary_share_count'],
            'low_share_count' => (int)$round['low_share_count'],
            'card_phase'      => $status === self::STATUS_BUYING ? 'buying'
                : ($status === self::STATUS_CLAIMING ? 'claim'
                : ($status === self::STATUS_VOID ? 'void'
                : ($status === self::STATUS_REFUND ? 'refund' : 'result'))),
            'desc'            => $this->groupDesc((int)$round['group_id']),
        ];
        // 仅结算后公开 randomness，领取阶段靠服务端入库尾数展示
        if ($status >= self::STATUS_SETTLED) {
            $seed = $tronId !== '' ? $tronId : (string)($round['drand_randomness'] ?? '');
            $out['has_randomness'] = $seed !== '';
            $out['drand_randomness'] = $seed;
            $out['fair_hash'] = $seed;
        } else {
            $out['has_randomness'] = false;
            $out['drand_randomness'] = '';
            $out['fair_hash'] = '';
        }
        return $out;
    }

    /**
     * 购入结束：取开局锁定的波场目标块哈希（禁止用「最新块」顶替，否则破坏 commit-reveal）
     * @return array{block_num:int,randomness:string,url:string}
     */
    protected function resolveTronProof(array $round)
    {
        $target = (int)($round['tron_block_num'] ?? 0);
        if ($target <= 0) {
            $target = (int)($round['drand_round'] ?? 0);
        }
        $block = null;
        $lastErr = '';
        if ($target > 0) {
            try {
                $block = TronBlockClient::getBlockByNum($target, 6);
            } catch (\Throwable $e) {
                $lastErr = $e->getMessage();
            }
        }
        $num = (int)($block['block_num'] ?? 0);
        $id = strtolower(trim((string)($block['block_id'] ?? '')));
        if ($id === '') {
            throw new \RuntimeException('tron target block hash unavailable'
                . ($target > 0 ? (' num=' . $target) : '')
                . ($lastErr !== '' ? (': ' . $lastErr) : ''));
        }
        return [
            'block_num'  => $num > 0 ? $num : $target,
            'randomness' => $id,
            'url'        => 'https://tronscan.org/#/block/' . ($num > 0 ? $num : $id),
        ];
    }

    /**
     * 购入阶段卡死（波场哈希长期不可用）：不抽手续费，按购入额全额退回。
     */
    protected function forceRefundBuyingRound(array $round, $now)
    {
        $roundId = (int)$round['id'];
        $now = (int)$now;
        $shares = Db::fetchAll(
            'SELECT * FROM ' . Db::table('chat_niuniu_shares') . ' WHERE round_id=? ORDER BY id ASC',
            [$roundId]
        ) ?: [];
        Db::begin();
        try {
            $ok = Db::exec(
                'UPDATE ' . Db::table('chat_niuniu_rounds')
                . ' SET status=?, settle_at=?, settle_case=4, fee_amount=0,'
                . ' distributable=pool_amount, claim_end_at=?, updatetime=?'
                . ' WHERE id=? AND status=?',
                [self::STATUS_REFUND, $now, $now, $now, $roundId, self::STATUS_BUYING]
            );
            if ($ok <= 0) {
                Db::rollBack();
                return false;
            }
            foreach ($shares as $s) {
                $uid = (int)($s['user_id'] ?? 0);
                $sid = (int)($s['id'] ?? 0);
                $amt = round((float)($s['amount'] ?? 0), 2);
                if ($uid > 0 && $sid > 0 && $amt > 0.00001) {
                    $this->wallet->change($uid, $amt, 'niuniu_refund', '尾数牛牛关购失败全额退回 #' . $roundId, [
                        'biz_no'   => 'niuniu_refund_buy_' . $sid,
                        'ref_type' => 'niuniu_share',
                        'ref_id'   => $sid,
                    ]);
                }
                if ($sid > 0) {
                    Db::exec(
                        'UPDATE ' . Db::table('chat_niuniu_shares')
                        . ' SET win_amount=?, paid=1, updatetime=? WHERE id=?',
                        [sprintf('%.2f', $amt), $now, $sid]
                    );
                }
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            throw $e;
        }
        $this->clearMuteFlag((int)$round['group_id']);
        $fresh = $this->getRound($roundId);
        $this->pushCard($fresh ?: $round, 'refund', $this->robotOrStarter($round));
        $this->onRoundFinished((int)$round['group_id'], (int)$round['starter_user_id']);
        return true;
    }

    protected function publicShare(array $s, $reveal)
    {
        $tailRaw = (string)($s['tail_digits'] ?? '');
        $pktAmt = self::packetAmountFromTail($tailRaw);
        $row = [
            'id'         => (int)$s['id'],
            'share_no'   => (int)$s['share_no'],
            'user_id'    => (int)$s['user_id'],
            // 未揭示时不暴露尾数金额（金额=尾数/100，会泄露结果）
            'amount'     => ($reveal && $tailRaw !== '') ? round($pktAmt, 2) : null,
            'claimed'    => (int)$s['claimed'] === 1,
            // 未领取不展示领取时间；已领用真实 claimed_at
            'claimed_at' => ((int)$s['claimed'] === 1) ? (int)($s['claimed_at'] ?? 0) : 0,
            'claim_seq'  => ((int)$s['claimed'] === 1) ? (int)($s['claim_seq'] ?? 0) : 0,
            'win_amount' => $reveal ? round((float)$s['win_amount'], 4) : 0.0,
            'weight'     => 1,
            'packet_paid'=> (int)($s['packet_paid'] ?? 0) === 1,
        ];
        if ($reveal && $tailRaw !== '') {
            $row['tail_digits'] = $tailRaw;
            $row['digit_a'] = (int)$s['digit_a'];
            $row['digit_b'] = (int)$s['digit_b'];
            $row['digit_sum'] = (int)$s['digit_sum'];
            $row['niu_point'] = (int)$s['niu_point'];
            $row['niu_tier'] = (int)$s['niu_tier'];
            $row['niu_label'] = (string)$s['niu_label'];
            $row['category'] = (string)$s['niu_label'];
            $row['result'] = '尾数' . $tailRaw . ' ' . $row['niu_label'];
            $row['calc'] = $row['digit_a'] . '+' . $row['digit_b'] . '=' . $row['digit_sum']
                . ' →【' . $row['niu_label'] . '】';
        } else {
            $row['tail_digits'] = null;
            $row['niu_label'] = $reveal ? '' : '未领取';
            $row['category'] = $reveal ? '' : '未领取';
            $row['result'] = $reveal ? '' : '未领取';
            // 未揭示时隐藏份额主键，降低结合区块哈希穷举复算的可能
            $row['id'] = 0;
            $row['share_no'] = 0;
        }
        // 昵称 / 头像
        try {
            $u = Db::fetch(
                'SELECT nickname, avatar FROM ' . Db::table('user') . ' WHERE id=? LIMIT 1',
                [(int)$s['user_id']]
            );
            $row['nickname'] = $u ? (string)($u['nickname'] ?: '') : '';
            if ($row['nickname'] === '') {
                $row['nickname'] = '用户' . $s['user_id'];
            }
            $row['avatar'] = $u ? (string)($u['avatar'] ?? '') : '';
        } catch (\Throwable $e) {
            $row['nickname'] = '用户' . $s['user_id'];
            $row['avatar'] = '';
        }
        return $row;
    }

    protected function statusLabel($status)
    {
        $map = [
            self::STATUS_BUYING   => '购入中',
            self::STATUS_CLAIMING => '领取中',
            self::STATUS_SETTLED  => '已开奖',
            self::STATUS_VOID     => '已作废',
            self::STATUS_REFUND   => '流局退回',
        ];
        return $map[(int)$status] ?? '未知';
    }

    protected function normalizeMode($mode)
    {
        return ((int)$mode === self::MODE_SINGLE) ? self::MODE_SINGLE : self::MODE_NORMAL;
    }

    protected function modeLabel($mode)
    {
        return $this->normalizeMode($mode) === self::MODE_SINGLE
            ? '尾数牛牛'
            : '尾数牛牛(多包)';
    }

    protected function ruleText($groupId)
    {
        $desc = $this->groupDesc($groupId);
        if ($desc !== '') {
            return $desc;
        }
        return (string)($this->config()['rule_text'] ?? '');
    }

    protected function groupDesc($groupId)
    {
        $g = $this->groups->get((int)$groupId);
        return $g ? trim((string)($g['niuniu_desc'] ?? '')) : '';
    }

    protected function robotOrStarter(array $round)
    {
        $c = $this->config();
        $rid = (int)$c['robot_user_id'];
        return $rid > 0 ? $rid : (int)$round['starter_user_id'];
    }

    protected function setMuteFlag($groupId, $roundId, $ttl)
    {
        try {
            RedisClient::conn()->setex(
                RedisClient::key('niuniu:mute:' . (int)$groupId),
                max(30, (int)$ttl),
                (string)(int)$roundId
            );
        } catch (\Throwable $e) {
        }
    }

    protected function clearMuteFlag($groupId)
    {
        try {
            RedisClient::conn()->del(RedisClient::key('niuniu:mute:' . (int)$groupId));
        } catch (\Throwable $e) {
        }
    }

    /** 本局结束：若连开中则排队下一局 */
    protected function onRoundFinished($groupId, $starterUserId = 0)
    {
        $groupId = (int)$groupId;
        if ($groupId <= 0 || !$this->isLooping($groupId)) {
            return;
        }
        $c = $this->config();
        $gap = max(2, (int)$c['loop_gap_sec']);
        $this->scheduleLoopNext($groupId, $gap);
        if ($starterUserId > 0) {
            // 保持 starter
            Db::exec(
                'UPDATE ' . Db::table('chat_groups') . ' SET niuniu_loop_starter=? WHERE id=? AND niuniu_loop=1',
                [(int)$starterUserId, $groupId]
            );
        }
    }

    protected function scheduleLoopNext($groupId, $gapSec)
    {
        $groupId = (int)$groupId;
        $at = time() + max(1, (int)$gapSec);
        try {
            RedisClient::conn()->setex(
                RedisClient::key('niuniu:loop:next:' . $groupId),
                max(60, (int)$gapSec + 120),
                (string)$at
            );
        } catch (\Throwable $e) {
        }
    }

    protected function getLoopNextAt($groupId)
    {
        try {
            $v = RedisClient::conn()->get(RedisClient::key('niuniu:loop:next:' . (int)$groupId));
            if ($v === false || $v === null || $v === '') {
                return 0;
            }
            return (int)$v;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    protected function clearLoopNext($groupId)
    {
        try {
            RedisClient::conn()->del(RedisClient::key('niuniu:loop:next:' . (int)$groupId));
        } catch (\Throwable $e) {
        }
    }

    protected function pushTip($groupId, $text, $fromUserId = 0)
    {
        $c = $this->config();
        $from = (int)$fromUserId;
        if ($from <= 0) {
            $from = (int)$c['robot_user_id'];
        }
        try {
            $msg = $this->messages->sendGroupSystem((int)$groupId, (string)$text, $from, [
                'niuniu' => 1,
                'tip'    => 'loop',
            ]);
            if (is_array($msg)) {
                NotifyPublisher::publish('group.message', $msg, false, $this->cfg);
            }
        } catch (\Throwable $e) {
            error_log('[NIUNIU][pushTip] ' . $e->getMessage());
        }
    }

    protected function refreshBuyingCard(array $round)
    {
        // 购入份数变化：就地改同一张卡片 + 推送 update（不再发新消息）
        $this->pushCard($round, 'buying', $this->robotOrStarter($round));
    }

    /** 本局唯一卡片消息 id（优先 buy_msg_id） */
    protected function cardMessageId(array $round)
    {
        $id = (int)($round['buy_msg_id'] ?? 0);
        if ($id > 0) {
            return $id;
        }
        $id = (int)($round['claim_msg_id'] ?? 0);
        if ($id > 0) {
            return $id;
        }
        return (int)($round['result_msg_id'] ?? 0);
    }

    /**
     * @param string $phase buying|claim|result|void|refund
     */
    protected function pushCard(array $round, $phase, $fromUserId)
    {
        $content = $this->cardContent($round, $phase);
        $extra = [
            'niuniu'    => 1,
            'phase'     => $phase,
            'round_id'  => (int)$round['id'],
            'round'     => $this->publicRound($round),
            'card_text' => $content,
        ];
        if ($phase === 'result' || $phase === 'refund') {
            $extra['shares'] = $this->resultShareLines($round);
        }
        $cardMsgId = $this->cardMessageId($round);
        try {
            // 已有开局卡片：整局生命周期只改这一条，不再插新消息
            if ($cardMsgId > 0) {
                $msg = $this->messages->updateMessageContentExtra($cardMsgId, $content, $extra);
                if (is_array($msg)) {
                    NotifyPublisher::publish('niuniu.update', [
                        'conversation_type' => 2,
                        'group_id'          => (int)$round['group_id'],
                        'message_id'        => $cardMsgId,
                        'content'           => $content,
                        'extra'             => $extra,
                        'message'           => $msg,
                    ], false, $this->cfg);
                    return $msg;
                }
                // 旧消息丢失时回退为新建一张卡
                error_log('[NIUNIU][pushCard] update miss id=' . $cardMsgId . ', fallback insert');
            }
            // 首张购入卡（或更新失败兜底）
            $msg = $this->messages->insertGroupMessageUnchecked(
                (int)$fromUserId,
                (int)$round['group_id'],
                $content,
                self::MSG_TYPE,
                $extra
            );
            if (is_array($msg)) {
                $mid = (int)$msg['id'];
                Db::exec(
                    'UPDATE ' . Db::table('chat_niuniu_rounds')
                    . ' SET buy_msg_id=?, updatetime=? WHERE id=?',
                    [$mid, time(), (int)$round['id']]
                );
                NotifyPublisher::publish('group.message', $msg, false, $this->cfg);
            }
            return $msg;
        } catch (\Throwable $e) {
            error_log('[NIUNIU][pushCard] ' . $e->getMessage());
            return null;
        }
    }

    protected function cardContent(array $round, $phase)
    {
        $r = $this->publicRound($round);
        $modeTitle = $r['game_mode_label'] ?: '尾数牛牛';
        $mmss = function ($sec) {
            $sec = max(0, (int)$sec);
            return sprintf('%02d:%02d', intdiv($sec, 60), $sec % 60);
        };
        $modeHint = ((int)$r['game_mode'] === self::MODE_SINGLE)
            ? '玩法：同一用户无论购几份，只算一个尾数（份数计权重）'
            : '玩法：每份独立一个尾数';
        if ($phase === 'buying') {
            return "🧧{$modeTitle}｜倒计时 {$mmss($r['remain_buy'])}\n"
                . "{$modeHint}\n"
                . "👥本局参与份数：{$r['share_count']} ｜单人购买份数无限制\n"
                . "💰本局总奖池：{$r['pool_amount']}积分\n"
                . "📌平台抽取" . round($r['fee_rate'] * 100) . "%手续费\n"
                . "🔐本局波场区块：{$r['drand_label']}\n\n"
                . "💡规则：红包需购入结束后手动点击领取查看尾数；买入积分进入奖池。"
                . "牛牛瓜分可分配池" . round($r['niuniu_rate'] * 100) . "%，牛7‑9瓜分" . round($r['secondary_rate'] * 100) . "%\n"
                . "⏰购入结束后可领取红包比对牛数";
        }
        if ($phase === 'claim') {
            return "🔔本局购入已结束，请领取红包查看牛数\n"
                . "{$modeHint}\n"
                . "🔐本局波场区块：{$r['drand_label']}\n"
                . "👥总参与份数：{$r['share_count']}\n"
                . "💰总奖池：{$r['pool_amount']}积分\n\n"
                . "👉点击领取本局红包（领取后才展示尾数牛数）\n"
                . "⚠️领取倒计时结束：未领取的（含机器人）将按序自动领取并结算\n"
                . "📌红包金额=尾数（如 02=0.02），领取时入账";
        }
        if ($phase === 'void') {
            return "⚠️本局作废｜参与份数=0，未扣手续费\n🔐波场区块：{$r['drand_label']}";
        }
        if ($phase === 'refund') {
            return "🌀本局流局｜全部为牛1‑6\n"
                . "总奖池：{$r['pool_amount']}｜平台手续费：{$r['fee_amount']}｜已按可发放奖金原路退回\n"
                . "🔐波场区块：{$r['drand_label']}";
        }
        // result
        $lines = "🎊本局开奖完成｜{$modeTitle}｜波场区块：{$r['drand_label']}\n"
            . "总奖池：{$r['pool_amount']}｜平台手续费：{$r['fee_amount']}｜可发放奖金：{$r['distributable']}积分\n";
        return $lines;
    }

    protected function resultShareLines(array $round)
    {
        $shares = Db::fetchAll(
            'SELECT * FROM ' . Db::table('chat_niuniu_shares') . ' WHERE round_id=? ORDER BY niu_tier DESC, share_no ASC',
            [(int)$round['id']]
        );
        $mode = $this->normalizeMode($round['game_mode'] ?? self::MODE_NORMAL);
        $out = ['niuniu' => [], 'secondary' => [], 'low' => []];
        $seenUser = [];
        foreach ($shares as $s) {
            $uid = (int)$s['user_id'];
            // 单结果开奖列表：同用户只展示一行（奖金合并）
            if ($mode === self::MODE_SINGLE) {
                if (isset($seenUser[$uid])) {
                    $bucket = &$out[$seenUser[$uid]];
                    $last = count($bucket) - 1;
                    if ($last >= 0) {
                        $bucket[$last]['win_amount'] = round(
                            (float)$bucket[$last]['win_amount'] + (float)$s['win_amount'],
                            4
                        );
                        $bucket[$last]['share_count'] = ((int)($bucket[$last]['share_count'] ?? 1)) + 1;
                    }
                    unset($bucket);
                    continue;
                }
            }
            $row = $this->publicShare($s, (int)$s['claimed'] === 1);
            $row['share_count'] = 1;
            $tier = (int)$s['niu_tier'];
            if ($tier === self::TIER_NIUNIU) {
                $out['niuniu'][] = $row;
                $seenUser[$uid] = 'niuniu';
            } elseif ($tier === self::TIER_SECONDARY) {
                $out['secondary'][] = $row;
                $seenUser[$uid] = 'secondary';
            } else {
                $out['low'][] = $row;
                $seenUser[$uid] = 'low';
            }
        }
        return $out;
    }

    /**
     * 历史消息补齐牛牛「我是否购入/已领」字段（广播卡片本身无用户态）
     */
    public function enrichMessageExtras(array $messages, $userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0 || !$messages) {
            return $messages;
        }
        $roundIds = [];
        $indexMap = [];
        foreach ($messages as $i => $m) {
            $ex = $m['extra'] ?? null;
            if (is_string($ex) && $ex !== '') {
                $ex = json_decode($ex, true);
            }
            if (!is_array($ex)) {
                continue;
            }
            $isNn = ((int)($m['msg_type'] ?? 0) === self::MSG_TYPE) || !empty($ex['niuniu']);
            if (!$isNn) {
                continue;
            }
            $rid = (int)($ex['round_id'] ?? 0);
            if ($rid <= 0 && !empty($ex['round']['id'])) {
                $rid = (int)$ex['round']['id'];
            }
            if ($rid <= 0) {
                continue;
            }
            $roundIds[$rid] = true;
            $indexMap[$i] = $rid;
        }
        if (!$roundIds) {
            return $messages;
        }
        $ids = array_keys($roundIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge($ids, [$userId]);
        $rows = [];
        try {
            $rows = Db::fetchAll(
                'SELECT round_id,'
                . ' COUNT(*) AS c,'
                . ' SUM(CASE WHEN claimed=1 THEN 1 ELSE 0 END) AS claimed_c'
                . ' FROM ' . Db::table('chat_niuniu_shares')
                . ' WHERE round_id IN (' . $placeholders . ') AND user_id=?'
                . ' GROUP BY round_id',
                $params
            );
        } catch (\Throwable $e) {
            return $messages;
        }
        $byRound = [];
        foreach ($rows as $r) {
            $rid = (int)$r['round_id'];
            $c = (int)($r['c'] ?? 0);
            $cc = (int)($r['claimed_c'] ?? 0);
            $byRound[$rid] = [
                'my_share_count'     => $c,
                'my_claimed'         => $c > 0 && $cc >= $c,
                'my_unclaimed_count' => max(0, $c - $cc),
            ];
        }
        foreach ($indexMap as $i => $rid) {
            $ex = $messages[$i]['extra'] ?? null;
            if (is_string($ex) && $ex !== '') {
                $ex = json_decode($ex, true);
            }
            if (!is_array($ex)) {
                $ex = [];
            }
            if (empty($ex['round']) || !is_array($ex['round'])) {
                $ex['round'] = [];
            }
            $info = $byRound[$rid] ?? [
                'my_share_count' => 0,
                'my_claimed' => false,
                'my_unclaimed_count' => 0,
            ];
            $ex['round']['my_share_count'] = $info['my_share_count'];
            $ex['round']['my_claimed'] = $info['my_claimed'];
            $ex['round']['my_unclaimed_count'] = $info['my_unclaimed_count'];
            $ex['round_id'] = $rid;
            $messages[$i]['extra'] = $ex;
        }
        return $messages;
    }
}
