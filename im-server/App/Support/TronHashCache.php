<?php

namespace Im\Support;

/**
 * 全局唯一波场最新哈希缓存：仅 worker0 每 N 秒拉取一次写入 Redis，
 * 业务拆包/结算只读本地 Redis，避免万人并发打爆 TronGrid。
 */
class TronHashCache
{
  const REDIS_KEY = 'tron:latest';

  /**
   * @return int 轮询间隔秒（默认 3）
   */
  public static function pollIntervalSec()
  {
    $n = 3;
    try {
      $app = require dirname(__DIR__, 2) . '/config/app.php';
      if (isset($app['tron']['hash_poll_interval'])) {
        $n = (int)$app['tron']['hash_poll_interval'];
      }
    } catch (\Throwable $e) {
    }
    return max(2, min(30, $n));
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
      $ttl = max(30, self::pollIntervalSec() * 10);
      $raw = json_encode($payload, JSON_UNESCAPED_UNICODE);
      try {
        RedisClient::conn()->setex(RedisClient::key(self::REDIS_KEY), $ttl, $raw);
      } catch (\Throwable $e) {
        error_log('[TRON_HASH] redis write fail: ' . $e->getMessage());
      }
      // 同步高度短缓存，供 getNowBlockNum 命中
      try {
        RedisClient::conn()->setex(
          RedisClient::key('tron:now_num'),
          max(2, self::pollIntervalSec()),
          (string)$payload['block_num']
        );
      } catch (\Throwable $e) {
      }
      // 同时写入按高度缓存，便于核对
      try {
        TronBlockClient::cachePutBlockPublic([
          'block_num' => $payload['block_num'],
          'block_id'  => $payload['block_id'],
          'confirmed' => true,
        ]);
      } catch (\Throwable $e) {
      }
      try {
        TronFair::onLatestHash($payload);
      } catch (\Throwable $e) {
        error_log('[TRON_HASH] onLatestHash fail: ' . $e->getMessage());
      }
      return $payload;
    } catch (\Throwable $e) {
      error_log('[TRON_HASH] refresh fail: ' . $e->getMessage());
      return null;
    }
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
