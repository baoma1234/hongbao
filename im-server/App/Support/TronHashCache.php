<?php

namespace Im\Support;

/**
 * 全局唯一波场哈希缓存：仅 worker0 每秒拉取最新块写入 Redis。
 * - tron:latest        最新一块
 * - tron:recent        最近 N 块列表（本地扫雷匹配，不打节点）
 * - tron:digit:{0-9}   各末位数字对应的最近一块（O(1) 命中雷号）
 */
class TronHashCache
{
  const REDIS_KEY = 'tron:latest';
  const REDIS_RECENT = 'tron:recent';
  const REDIS_DIGIT_PREFIX = 'tron:digit:';

  /**
   * @return float 轮询间隔秒（默认 1）
   */
  public static function pollIntervalSec()
  {
    $n = 1;
    try {
      $app = require dirname(__DIR__, 2) . '/config/app.php';
      if (isset($app['tron']['hash_poll_interval'])) {
        $n = (float)$app['tron']['hash_poll_interval'];
      }
    } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Support.TronHashCache');
        }
    return max(1.0, min(30.0, $n));
  }

  /** 最近缓存块数量 */
  public static function recentLimit()
  {
    $n = 40;
    try {
      $app = require dirname(__DIR__, 2) . '/config/app.php';
      if (isset($app['tron']['hash_recent_limit'])) {
        $n = (int)$app['tron']['hash_recent_limit'];
      }
    } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Support.TronHashCache');
        }
    return max(10, min(120, $n));
  }

  /**
   * 从 TronGrid 拉最新块并写入 Redis（仅常驻轮询线程调用）
   * @return array{block_num:int,block_id:string,at:int}|null
   */
  public static function refresh($timeout = 4)
  {
    try {
      $block = TronBlockClient::fetchNowBlock($timeout);
      if (!$block || empty($block['block_id']) || (int)($block['block_num'] ?? 0) <= 0) {
        return null;
      }
      $payload = [
        'block_num' => (int)$block['block_num'],
        'block_id'  => strtolower(trim((string)$block['block_id'])),
        'at'        => time(),
      ];

      // 高度未变：只续期，不重复触发扫雷匹配
      $prev = self::get();
      $changed = !$prev || (int)$prev['block_num'] !== $payload['block_num']
        || (string)$prev['block_id'] !== $payload['block_id'];

      $ttl = max(60, (int)ceil(self::pollIntervalSec() * 60));
      $raw = json_encode($payload, JSON_UNESCAPED_UNICODE);
      try {
        $r = RedisClient::conn();
        $r->setex(RedisClient::key(self::REDIS_KEY), $ttl, $raw);
        $r->setex(
          RedisClient::key('tron:now_num'),
          max(2, (int)ceil(self::pollIntervalSec() * 3)),
          (string)$payload['block_num']
        );
        if ($changed) {
          self::indexBlock($payload);
        }
      } catch (\Throwable $e) {
        error_log('[TRON_HASH] redis write fail: ' . $e->getMessage());
      }

      try {
        TronBlockClient::cachePutBlockPublic([
          'block_num' => $payload['block_num'],
          'block_id'  => $payload['block_id'],
          'confirmed' => true,
        ]);
      } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Support.TronHashCache');
        }

      if ($changed) {
        try {
          TronFair::onLatestHash($payload);
        } catch (\Throwable $e) {
          error_log('[TRON_HASH] onLatestHash fail: ' . $e->getMessage());
        }
      }
      return $payload;
    } catch (\Throwable $e) {
      error_log('[TRON_HASH] refresh fail: ' . $e->getMessage());
      return null;
    }
  }

  /**
   * 写入 recent 列表 + 按末位数字索引（纯 Redis，供扫雷本地秒匹配）
   * @param array{block_num:int,block_id:string,at?:int} $payload
   */
  public static function indexBlock(array $payload)
  {
    $blockId = strtolower(trim((string)($payload['block_id'] ?? '')));
    $blockNum = (int)($payload['block_num'] ?? 0);
    if ($blockId === '' || $blockNum <= 0) {
      return;
    }
    $digit = TronBlockClient::luckyDigitFromBlockId($blockId);
    $row = [
      'block_num' => $blockNum,
      'block_id'  => $blockId,
      'digit'     => $digit,
      'at'        => (int)($payload['at'] ?? time()),
    ];
    $raw = json_encode($row, JSON_UNESCAPED_UNICODE);
    $ttl = 86400;
    try {
      $r = RedisClient::conn();
      $recentKey = RedisClient::key(self::REDIS_RECENT);
      $r->lPush($recentKey, $raw);
      $r->lTrim($recentKey, 0, self::recentLimit() - 1);
      $r->expire($recentKey, $ttl);
      // 每个雷号保留最近一块，发包 O(1) 命中
      $r->setex(RedisClient::key(self::REDIS_DIGIT_PREFIX . $digit), $ttl, $raw);
    } catch (\Throwable $e) {
      error_log('[TRON_HASH] indexBlock fail: ' . $e->getMessage());
    }
  }

  /**
   * 本地 Redis：按雷号找最近匹配块（不打波场）
   * @return array{block_num:int,block_id:string,digit:int,at:int}|null
   */
  public static function findByDigit($digit)
  {
    $digit = max(0, min(9, (int)$digit));
    try {
      $r = RedisClient::conn();
      $raw = $r->get(RedisClient::key(self::REDIS_DIGIT_PREFIX . $digit));
      if ($raw) {
        $j = json_decode((string)$raw, true);
        if (is_array($j) && !empty($j['block_id']) && (int)($j['block_num'] ?? 0) > 0) {
          return [
            'block_num' => (int)$j['block_num'],
            'block_id'  => strtolower(trim((string)$j['block_id'])),
            'digit'     => (int)($j['digit'] ?? $digit),
            'at'        => (int)($j['at'] ?? 0),
          ];
        }
      }
      // 兜底扫 recent 列表
      $list = $r->lRange(RedisClient::key(self::REDIS_RECENT), 0, self::recentLimit() - 1);
      foreach ($list ?: [] as $item) {
        $j = json_decode((string)$item, true);
        if (!is_array($j) || empty($j['block_id'])) {
          continue;
        }
        $d = isset($j['digit'])
          ? (int)$j['digit']
          : TronBlockClient::luckyDigitFromBlockId($j['block_id']);
        if ($d === $digit) {
          return [
            'block_num' => (int)$j['block_num'],
            'block_id'  => strtolower(trim((string)$j['block_id'])),
            'digit'     => $d,
            'at'        => (int)($j['at'] ?? 0),
          ];
        }
      }
    } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Support.TronHashCache');
        }
    return null;
  }

  /**
   * 只读 Redis 最新哈希（不打波场）
   * @return array{block_num:int,block_id:string,at:int}|null
   */
  public static function get()
  {
    try {
      $raw = RedisClient::conn()->get(RedisClient::key(self::REDIS_KEY));
      if ($raw === false || $raw === null || $raw === '') {
        return null;
      }
      $j = json_decode((string)$raw, true);
      if (!is_array($j) || empty($j['block_id']) || (int)($j['block_num'] ?? 0) <= 0) {
        return null;
      }
          return [
        'block_num' => (int)$j['block_num'],
        'block_id'  => strtolower(trim((string)$j['block_id'])),
        'at'        => (int)($j['at'] ?? 0),
      ];
    } catch (\Throwable $e) {
      return null;
    }
  }

  /**
   * 读缓存；若空则刷新一次（冷启动兜底，业务路径尽量不走）
   * @return array{block_num:int,block_id:string,at:int}
   */
  public static function getOrRefresh($timeout = 4)
  {
    $cached = self::get();
    if ($cached !== null) {
      return $cached;
    }
    $fresh = self::refresh($timeout);
    if ($fresh !== null) {
      return $fresh;
    }
    throw new \RuntimeException('tron latest hash unavailable');
  }
}
