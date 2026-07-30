<?php

namespace Im\Support;

use Workerman\Timer;

/**
 * IM 侧波场开奖调度：Timer 延迟 + 写入 think-queue delayed（双通道，无 sleep）
 *
 * 扫雷：抢完后等区块哈希揭晓，用哈希末位映射 0-9 作为官方雷号再结算。
 * 拼手气：金额输赢仍按金额结算；区块哈希用于全球可查公示。
 */
class TronFair
{
  const STATUS_NONE = 0;
  const STATUS_PENDING = 1;
  const STATUS_DONE = 2;
  const STATUS_FAIL = 3;

  public static function revealDelaySec()
  {
    $n = 8;
    try {
      $app = require dirname(__DIR__, 2) . '/config/app.php';
      if (isset($app['tron']['reveal_delay'])) {
        $n = (int)$app['tron']['reveal_delay'];
      }
    } catch (\Throwable $e) {
    }
    return max(3, min(60, $n));
  }

  public static function commitOffset()
  {
    $n = 2;
    try {
      $app = require dirname(__DIR__, 2) . '/config/app.php';
      if (isset($app['tron']['commit_offset'])) {
        $n = (int)$app['tron']['commit_offset'];
      }
    } catch (\Throwable $e) {
    }
    return max(1, min(20, $n));
  }

  /**
   * 发包时锁定未来区块高度（防出块瞬间套利）
   * @return int 目标区块高度，失败返回 0
   */
  public static function commitTargetBlockNum()
  {
    try {
      $now = TronBlockClient::getNowBlockNum(3);
      return $now + self::commitOffset();
    } catch (\Throwable $e) {
      error_log('[TRON] commitTargetBlockNum fail: ' . $e->getMessage());
      return 0;
    }
  }

  /**
   * 抢完/过期后调用：标记 pending → 延迟拉取官方区块哈希
   */
  public static function scheduleReveal($packetId, $delaySec = null)
  {
    $packetId = (int)$packetId;
    if ($packetId <= 0) {
      return false;
    }
    if ($delaySec === null) {
      $delaySec = self::revealDelaySec();
    }
    $delaySec = max(1, (int)$delaySec);

    $packet = Db::fetch(
      'SELECT id, packet_type, tron_status, tron_block_num, tron_block_id FROM ' . Db::table('chat_red_packets') . ' WHERE id=? LIMIT 1',
      [$packetId]
    );
    if (!$packet || !in_array((int)$packet['packet_type'], [2, 3], true)) {
      return false;
    }
    if ((int)($packet['tron_status'] ?? 0) === self::STATUS_DONE && trim((string)($packet['tron_block_id'] ?? '')) !== '') {
      // 已开奖：扫雷若仍 status=2 则补结算
      self::maybeSettleAfterReveal($packetId);
      return true;
    }

    $blockNum = (int)($packet['tron_block_num'] ?? 0);
    if ($blockNum <= 0) {
      try {
        $blockNum = TronBlockClient::getNowBlockNum(3) + self::commitOffset();
      } catch (\Throwable $e) {
        error_log('[TRON] getnowblock fail packet=' . $packetId . ' ' . $e->getMessage());
        $blockNum = 0;
      }
    }
    $now = time();
    Db::exec(
      'UPDATE ' . Db::table('chat_red_packets')
      . ' SET tron_block_num=?, tron_status=?, updatetime=? WHERE id=? AND tron_status<>?',
      [$blockNum, self::STATUS_PENDING, $now, $packetId, self::STATUS_DONE]
    );

    self::enqueueThinkQueue($packetId, $delaySec);

    try {
      Timer::add((float)$delaySec, function () use ($packetId) {
        try {
          self::processReveal($packetId);
        } catch (\Throwable $e) {
          error_log('[TRON] timer reveal fail packet=' . $packetId . ' ' . $e->getMessage());
        }
      }, [], false);
    } catch (\Throwable $e) {
      error_log('[TRON] Timer::add fail ' . $e->getMessage());
    }
    return true;
  }

  public static function processReveal($packetId)
  {
    $packetId = (int)$packetId;
    $packet = Db::fetch(
      'SELECT * FROM ' . Db::table('chat_red_packets') . ' WHERE id=? LIMIT 1',
      [$packetId]
    );
    if (!$packet) {
      return false;
    }
    if ((int)($packet['tron_status'] ?? 0) === self::STATUS_DONE && trim((string)($packet['tron_block_id'] ?? '')) !== '') {
      self::cachePut($packet);
      self::maybeSettleAfterReveal($packetId);
      return true;
    }
    $blockNum = (int)($packet['tron_block_num'] ?? 0);
    try {
      if ($blockNum <= 0) {
        $blockNum = TronBlockClient::getNowBlockNum(4) + self::commitOffset();
        Db::exec(
          'UPDATE ' . Db::table('chat_red_packets') . ' SET tron_block_num=?, tron_status=?, updatetime=? WHERE id=?',
          [$blockNum, self::STATUS_PENDING, time(), $packetId]
        );
      }
      // 已锁定高度：直接拉块（有 Redis 缓存）；未出块由 getblockbynum 失败触发重试
      // 不再额外 getnowblock，节省一半 TronGrid 调用
      $block = TronBlockClient::getBlockByNum($blockNum, 6);
      $luckyChar = TronBlockClient::luckyFromBlockId($block['block_id']);
      $luckyDigit = TronBlockClient::luckyDigitFromBlockId($block['block_id']);
      $now = time();
      $packetType = (int)($packet['packet_type'] ?? 0);
      // 扫雷：官方雷号 = 区块哈希末位映射 0-9
      if ($packetType === 3) {
        Db::exec(
          'UPDATE ' . Db::table('chat_red_packets')
          . ' SET tron_block_num=?, tron_block_id=?, tron_lucky=?, tron_status=?, fair_revealed_at=?,'
          . ' fair_hash=?, fair_seed=\'\', fair_payload=\'\', mine_digit=?, updatetime=?'
          . ' WHERE id=? AND tron_status<>?',
          [
            (int)$block['block_num'],
            $block['block_id'],
            $luckyChar,
            self::STATUS_DONE,
            $now,
            $block['block_id'],
            $luckyDigit,
            $now,
            $packetId,
            self::STATUS_DONE,
          ]
        );
      } else {
        Db::exec(
          'UPDATE ' . Db::table('chat_red_packets')
          . ' SET tron_block_num=?, tron_block_id=?, tron_lucky=?, tron_status=?, fair_revealed_at=?,'
          . ' fair_hash=?, fair_seed=\'\', fair_payload=\'\', updatetime=?'
          . ' WHERE id=? AND tron_status<>?',
          [
            (int)$block['block_num'],
            $block['block_id'],
            $luckyChar,
            self::STATUS_DONE,
            $now,
            $block['block_id'],
            $now,
            $packetId,
            self::STATUS_DONE,
          ]
        );
      }
      $packet = Db::fetch('SELECT * FROM ' . Db::table('chat_red_packets') . ' WHERE id=? LIMIT 1', [$packetId]);
      if ($packet) {
        self::cachePut($packet);
      }
      self::maybeSettleAfterReveal($packetId);
      self::notifyRevealDone($packetId);
      return true;
    } catch (\Throwable $e) {
      error_log('[TRON] processReveal fail packet=' . $packetId . ' ' . $e->getMessage());
      Db::exec(
        'UPDATE ' . Db::table('chat_red_packets') . ' SET tron_status=?, updatetime=? WHERE id=? AND tron_status<>?',
        [self::STATUS_FAIL, time(), $packetId, self::STATUS_DONE]
      );
      try {
        Timer::add(3.0, function () use ($packetId) {
          try {
            self::processReveal($packetId);
          } catch (\Throwable $ignore) {
          }
        }, [], false);
      } catch (\Throwable $ignore) {
      }
      return false;
    }
  }

  /**
   * 波场开奖完成后：扫雷包若已抢完(status=2)则按哈希末位雷号结算并通知
   */
  public static function maybeSettleAfterReveal($packetId)
  {
    $packetId = (int)$packetId;
    $packet = Db::fetch(
      'SELECT id, packet_type, status, tron_status, tron_block_id, scope_type, group_id, from_user_id, to_user_id'
      . ' FROM ' . Db::table('chat_red_packets') . ' WHERE id=? LIMIT 1',
      [$packetId]
    );
    if (!$packet) {
      return;
    }
    if ((int)($packet['packet_type'] ?? 0) !== 3) {
      return;
    }
    if ((int)($packet['status'] ?? 0) !== 2) {
      return;
    }
    try {
      $app = require dirname(__DIR__, 2) . '/config/app.php';
      $wallet = new \Im\Service\WalletService($app);
      $settler = new \Im\Service\RedPacketSettlementService($wallet, $app);
      $info = $settler->settleAfterFinished($packetId);
      if (!empty($info['settled'])) {
        error_log('[TRON] mine settled after reveal packet_id=' . $packetId);
        self::notifyMineSettled($packetId, $packet, $info);
      }
    } catch (\Throwable $e) {
      error_log('[TRON] mine settle after reveal fail packet=' . $packetId . ' ' . $e->getMessage());
    }
  }

  /**
   * 扫雷结算后群内公示（与 RedPacketService::notifySettled 一致）
   */
  protected static function notifyMineSettled($packetId, array $hint, array $settleInfo)
  {
    $packetId = (int)$packetId;
    try {
      $event = [
        'packet_id'  => $packetId,
        'settled'    => true,
        'settlement' => [
          'settled'          => true,
          'compensate_users' => $settleInfo['compensate_users'] ?? [],
          'platform_fee'     => $settleInfo['platform_fee'] ?? 0,
          'agent_rebate'     => $settleInfo['agent_rebate'] ?? 0,
        ],
      ];
      if ((int)($hint['scope_type'] ?? 0) === 2 && (int)($hint['group_id'] ?? 0) > 0) {
        $groupId = (int)$hint['group_id'];
        $uidList = (new \Im\Service\GroupService())->memberUserIds($groupId);
        if ($uidList) {
          PushBus::toUsers($uidList, 'redpacket.update', $event);
        }
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
          $briefs = (new \Im\Service\AuthService([]))->usersBriefMap($hitUids);
          $names = [];
          foreach ($hitUids as $hid) {
            $u = $briefs[$hid] ?? null;
            $n = $u ? trim((string)($u['nickname'] ?: $u['username'] ?: '')) : '';
            $names[] = $n !== '' ? $n : ('ID' . $hid);
          }
          $text = '埋雷结算：雷号 ' . $mineDigit . '（波场哈希末位） · 中雷 ' . count($hitUids) . ' 人（'
            . implode('、', $names) . '）';
        } else {
          $text = '埋雷结算：雷号 ' . $mineDigit . '（波场哈希末位） · 本局无人中雷';
        }
        $sys = (new \Im\Service\MessageService())->sendGroupSystem($groupId, $text, 0, [
          'packet_id'  => $packetId,
          'mine_digit' => $mineDigit,
          'hit_count'  => count($hitUids),
          'kind'       => 'mine_settle',
        ]);
        if ($uidList && is_array($sys)) {
          PushBus::toUsers($uidList, 'group.message', ['message' => $sys]);
        }
      } else {
        $uidList = array_values(array_unique(array_filter([
          (int)($hint['from_user_id'] ?? 0),
          (int)($hint['to_user_id'] ?? 0),
        ])));
        if ($uidList) {
          PushBus::toUsers($uidList, 'redpacket.update', $event);
        }
      }
    } catch (\Throwable $e) {
      error_log('[TRON] notifyMineSettled fail packet=' . $packetId . ' ' . $e->getMessage());
    }
  }

  /**
   * 开奖结果推送到会话：redpacket.update + 群系统提示（可去 TronScan 核对）
   */
  public static function notifyRevealDone($packetId)
  {
    $packetId = (int)$packetId;
    $packet = Db::fetch(
      'SELECT * FROM ' . Db::table('chat_red_packets') . ' WHERE id=? LIMIT 1',
      [$packetId]
    );
    if (!$packet || (int)($packet['tron_status'] ?? 0) !== self::STATUS_DONE) {
      return;
    }
    $blockId = trim((string)($packet['tron_block_id'] ?? ''));
    if ($blockId === '') {
      return;
    }
    $view = self::publicView($packet);
    $blockNum = (int)($view['tron_block_num'] ?? 0);
    $luckyChar = (string)($view['tron_lucky'] ?? '');
    $luckyDigit = $view['lucky_digit'];
    $event = [
      'packet_id'     => $packetId,
      'packet_no'     => (string)($packet['packet_no'] ?? ''),
      'tron_revealed' => true,
      'tron'          => $view,
    ];
    try {
      if ((int)($packet['scope_type'] ?? 0) === 2 && (int)($packet['group_id'] ?? 0) > 0) {
        $groupId = (int)$packet['group_id'];
        $uids = (new \Im\Service\GroupService())->memberUserIds($groupId);
        if ($uids) {
          PushBus::toUsers($uids, 'redpacket.update', $event);
        }
        $ptype = (int)($packet['packet_type'] ?? 0);
        $text = '波场官方开奖：区块 #' . $blockNum
          . ' · 哈希末位 ' . $luckyChar;
        if ($ptype === 3) {
          $text .= ' · 埋雷数字 ' . (int)($packet['mine_digit'] ?? 0);
        }
        $text .= '（可点红包详情前往 TronScan 核对）';
        $sys = (new \Im\Service\MessageService())->sendGroupSystem($groupId, $text, 0, [
          'packet_id'      => $packetId,
          'tron_block_num' => $blockNum,
          'tron_lucky'     => $luckyChar,
          'mine_digit'     => (int)($packet['mine_digit'] ?? 0),
          'kind'           => 'tron_reveal',
        ]);
        if ($uids && is_array($sys)) {
          PushBus::toUsers($uids, 'group.message', ['message' => $sys]);
        }
      } else {
        $uids = array_values(array_unique(array_filter([
          (int)($packet['from_user_id'] ?? 0),
          (int)($packet['to_user_id'] ?? 0),
        ])));
        if ($uids) {
          PushBus::toUsers($uids, 'redpacket.update', $event);
        }
      }
    } catch (\Throwable $e) {
      error_log('[TRON] notifyRevealDone fail packet=' . $packetId . ' ' . $e->getMessage());
    }
  }

  protected static function enqueueThinkQueue($packetId, $delaySec)
  {
    try {
      $q = new \Redis();
      if (!$q->connect('127.0.0.1', 6379, 1.5)) {
        return;
      }
      $q->select(0);
      $payload = json_encode([
        'job'      => 'app\\job\\TronRedPacketReveal',
        'data'     => ['packet_id' => (int)$packetId],
        'id'       => bin2hex(random_bytes(16)),
        'attempts' => 1,
      ], JSON_UNESCAPED_UNICODE);
      $q->zAdd('queues:default:delayed', time() + max(1, (int)$delaySec), $payload);
    } catch (\Throwable $e) {
      error_log('[TRON] enqueue think-queue fail: ' . $e->getMessage());
    }
  }

  public static function cachePut(array $packet)
  {
    $no = trim((string)($packet['packet_no'] ?? ''));
    if ($no === '') {
      return;
    }
    $view = self::publicView($packet);
    try {
      $r = RedisClient::conn();
      $r->setex(RedisClient::key('rp:tron:fair:' . $no), 86400, json_encode($view, JSON_UNESCAPED_UNICODE));
    } catch (\Throwable $e) {
    }
    try {
      $q = new \Redis();
      if ($q->connect('127.0.0.1', 6379, 1.0)) {
        $q->select(0);
        $q->setex('rp:tron:fair:' . $no, 86400, json_encode($view, JSON_UNESCAPED_UNICODE));
      }
    } catch (\Throwable $e) {
    }
  }

  public static function publicView(array $packet, array $records = [])
  {
    $blockNum = (int)($packet['tron_block_num'] ?? 0);
    $blockId = trim((string)($packet['tron_block_id'] ?? ''));
    $fairHash = $blockId !== '' ? $blockId : trim((string)($packet['fair_hash'] ?? ''));
    $revealed = (int)($packet['tron_status'] ?? 0) === self::STATUS_DONE && $blockId !== '';
    $luckyChar = $revealed
      ? (string)($packet['tron_lucky'] ?? TronBlockClient::luckyFromBlockId($blockId))
      : '';
    $luckyDigit = $revealed ? TronBlockClient::luckyDigitFromBlockId($blockId) : null;
    $type = (int)($packet['packet_type'] ?? 0);
    $label = $type === 2 ? '拼手气接龙' : ($type === 3 ? '扫雷' : '红包');
    $mineDigit = null;
    if ($type === 3) {
      $mineDigit = $revealed
        ? (int)($luckyDigit !== null ? $luckyDigit : ($packet['mine_digit'] ?? 0))
        : null;
    } elseif ($revealed) {
      $mineDigit = (int)$luckyDigit;
    }
    $out = [
      'packet_no'         => (string)($packet['packet_no'] ?? ''),
      'packet_type'       => $type,
      'type_label'        => $label,
      // 扫雷官方雷号 = 波场哈希末位；未开奖前为 null
      'mine_digit'        => $mineDigit,
      'mine_pending'      => $type === 3 && !$revealed,
      'total_amount'      => (float)($packet['total_amount'] ?? 0),
      'pool_amount'       => (float)($packet['pool_amount'] ?? 0),
      'total_count'       => (int)($packet['total_count'] ?? 0),
      'status'            => (int)($packet['status'] ?? 0),
      'proof_type'        => 'tron',
      'targetBlockNum'    => $blockNum,
      'tron_block_num'    => $blockNum,
      'block_id'          => $blockId,
      'tron_block_id'     => $blockId,
      'luckyNumber'       => $luckyChar,
      'tron_lucky'        => $luckyChar,
      'lucky_digit'       => $luckyDigit,
      'tron_status'       => (int)($packet['tron_status'] ?? 0),
      'fair_hash'         => $fairHash,
      'revealed'          => $revealed,
      'fair_revealed_at'  => (int)($packet['fair_revealed_at'] ?? 0),
      'tronscan_url'      => $blockNum > 0
        ? ('https://tronscan.org/#/block/' . $blockNum)
        : ($blockId !== '' ? ('https://tronscan.org/#/block/' . $blockId) : ''),
      'verify_hint'       => $revealed
        ? ('TronScan 核对区块 #' . $blockNum . ' 的 Block Hash 末位是否为 ' . $luckyChar
          . ($type === 3 && $mineDigit !== null ? ('；本局埋雷数字=' . $mineDigit) : '')
          . (ctype_digit((string)$luckyChar) ? '' : ('（末位 ' . $luckyChar . ' → ' . (int)$luckyDigit . '）')))
        : ($blockNum > 0 ? ('已锁定官方区块高度 #' . $blockNum . '，出块后开奖') : '待锁定波场区块'),
    ];
    return $out;
  }

  /**
   * Crontab/Timer 兜底：处理 pending/fail 的波场开奖
   * 按 tron_block_num 去重预热，同一高度只打一次 TronGrid
   */
  public static function pollPendingReveals($limit = 20)
  {
    $limit = max(1, min(100, (int)$limit));
    $rows = Db::fetchAll(
      'SELECT id, tron_block_num FROM ' . Db::table('chat_red_packets')
      . ' WHERE packet_type IN (2,3) AND tron_status IN (1,3)'
      . ' AND updatetime<?'
      . ' ORDER BY id ASC LIMIT ' . $limit,
      [time() - 3]
    );
    $stuck = Db::fetchAll(
      'SELECT id, tron_block_num FROM ' . Db::table('chat_red_packets')
      . ' WHERE packet_type=3 AND status=2 AND tron_status=0 AND remain_count<=0'
      . ' ORDER BY id ASC LIMIT ' . $limit
    );
    $ids = [];
    $blockNums = [];
    foreach (array_merge($rows ?: [], $stuck ?: []) as $r) {
      $id = (int)$r['id'];
      $ids[$id] = true;
      $bn = (int)($r['tron_block_num'] ?? 0);
      if ($bn > 0) {
        $blockNums[] = $bn;
      }
    }
    if ($blockNums) {
      TronBlockClient::prefetchBlocks($blockNums, 6);
    }
    $ok = 0;
    $fail = 0;
    foreach (array_keys($ids) as $id) {
      if (self::processReveal((int)$id)) {
        $ok++;
      } else {
        $fail++;
      }
    }
    return [
      'scanned'      => count($ids),
      'ok'           => $ok,
      'fail'         => $fail,
      'prefetch_num' => count(array_unique($blockNums)),
    ];
  }
}
