<?php

namespace Im\Support;

/**
 * 波场 TronGrid 客户端（IM / Workerman 侧）
 * - getnowblock：进程内存 + Redis 短缓存（默认 2s），避免轮询打爆额度
 * - getblockbynum：历史块不可变，Redis 长缓存 + 同高度单飞锁
 * - 禁止 sleep
 */
class TronBlockClient
{
  const API_BASE_DEFAULT = 'https://api.trongrid.io';

  /** @var array|null */
  protected static $cfg;

  /** @var array{num:int,at:float}|null 进程内最新高度缓存 */
  protected static $memNow = null;

  public static function getNowBlockNum($timeout = 3)
  {
    $ttl = self::nowCacheTtl();
    $now = microtime(true);
    if (self::$memNow !== null && ($now - (float)self::$memNow['at']) < $ttl) {
      return (int)self::$memNow['num'];
    }

    // 优先读全局最新哈希缓存（由 TronHashCache 轮询写入）
    try {
      $latest = TronHashCache::get();
      if ($latest !== null && (int)$latest['block_num'] > 0) {
        self::$memNow = ['num' => (int)$latest['block_num'], 'at' => $now];
        return (int)$latest['block_num'];
      }
    } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Support.TronBlockClient');
        }

    $cacheKey = 'tron:now_num';
    try {
      $r = RedisClient::conn();
      $cached = $r->get(RedisClient::key($cacheKey));
      if ($cached !== false && $cached !== null && (int)$cached > 0) {
        $num = (int)$cached;
        self::$memNow = ['num' => $num, 'at' => $now];
        return $num;
      }
    } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Support.TronBlockClient');
        }

    $block = self::fetchNowBlock($timeout);
    $num = (int)$block['block_num'];
    self::$memNow = ['num' => $num, 'at' => $now];
    return $num;
  }

  /**
   * 拉取最新块（含 blockID），供全局哈希轮询写入 Redis
   * @return array{block_num:int,block_id:string,confirmed:bool}
   */
  public static function fetchNowBlock($timeout = 4)
  {
    $json = self::post('/wallet/getnowblock', new \stdClass(), $timeout);
    $num = $json['block_header']['raw_data']['number'] ?? null;
    if ($num === null || (int)$num <= 0) {
      throw new \RuntimeException('tron getnowblock: missing block number');
    }
    $blockId = strtolower(trim((string)($json['blockID'] ?? $json['block_id'] ?? '')));
    if ($blockId === '') {
      throw new \RuntimeException('tron getnowblock: empty blockID');
    }
    $num = (int)$num;
    $out = [
      'block_id'  => $blockId,
      'block_num' => $num,
      'confirmed' => true,
    ];
    self::$memNow = ['num' => $num, 'at' => microtime(true)];
    try {
      RedisClient::conn()->setex(
        RedisClient::key('tron:now_num'),
        max(1, (int)ceil(self::nowCacheTtl())),
        (string)$num
      );
    } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Support.TronBlockClient');
        }
    self::cachePutBlock($out);
    return $out;
  }

  /** 供 TronHashCache 写入按高度缓存 */
  public static function cachePutBlockPublic(array $block)
  {
    self::cachePutBlock($block);
  }

  public static function getBlockByNum($blockNum, $timeout = 5)
  {
    $blockNum = (int)$blockNum;
    if ($blockNum <= 0) {
      throw new \InvalidArgumentException('invalid block num');
    }

    $cached = self::cacheGetBlock($blockNum);
    if ($cached !== null) {
      return $cached;
    }

    // 同高度单飞：抢到锁的请求去拉链，其它等待缓存
    $lockKey = RedisClient::key('tron:lock:block:' . $blockNum);
    $gotLock = false;
    try {
      $r = RedisClient::conn();
      $gotLock = (bool)$r->set($lockKey, (string)time(), ['nx', 'ex' => 8]);
    } catch (\Throwable $e) {
      $gotLock = true; // Redis 不可用则直接请求，避免卡死
    }

    if (!$gotLock) {
      // 不阻塞事件循环：再读一次缓存；未命中则自行请求（偶发重复可接受）
      $cached = self::cacheGetBlock($blockNum);
      if ($cached !== null) {
        return $cached;
      }
    }

    try {
      $json = self::post('/wallet/getblockbynum', ['num' => $blockNum], $timeout);
      $blockId = strtolower(trim((string)($json['blockID'] ?? $json['block_id'] ?? '')));
      if ($blockId === '') {
        throw new \RuntimeException('tron getblockbynum: empty blockID for #' . $blockNum);
      }
      $confirmed = true;
      if (array_key_exists('confirmed', $json)) {
        $confirmed = (bool)$json['confirmed'];
      }
      if (!$confirmed) {
        throw new \RuntimeException('tron block #' . $blockNum . ' not confirmed yet');
      }
      $out = [
        'block_id'  => $blockId,
        'block_num' => (int)($json['block_header']['raw_data']['number'] ?? $blockNum),
        'confirmed' => $confirmed,
      ];
      self::cachePutBlock($out);
      return $out;
    } finally {
      if ($gotLock) {
        try {
          RedisClient::conn()->del($lockKey);
        } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Support.TronBlockClient');
        }
      }
    }
  }

  /**
   * 批量预热：同一批 pending 包按高度去重后只拉一次
   * @param int[] $blockNums
   * @return array<int,array{block_id:string,block_num:int,confirmed:bool}>
   */
  public static function prefetchBlocks(array $blockNums, $timeout = 6)
  {
    $uniq = [];
    foreach ($blockNums as $n) {
      $n = (int)$n;
      if ($n > 0) {
        $uniq[$n] = true;
      }
    }
    $out = [];
    foreach (array_keys($uniq) as $n) {
      try {
        $out[$n] = self::getBlockByNum($n, $timeout);
      } catch (\Throwable $e) {
        // 未出块等错误：跳过，由调用方重试
      }
    }
    return $out;
  }

  /**
   * 区块哈希末位字符（0-9A-F，大写）——玩家去 TronScan 核对用
   */
  public static function luckyFromBlockId($blockId)
  {
    $blockId = trim((string)$blockId);
    if ($blockId === '') {
      return '';
    }
    return strtoupper(substr($blockId, -1));
  }

  /**
   * 区块哈希末位 → 扫雷官方雷号 0-9
   */
  public static function luckyDigitFromBlockId($blockId)
  {
    $c = strtolower(substr(trim((string)$blockId), -1));
    if ($c !== '' && $c >= '0' && $c <= '9') {
      return (int)$c;
    }
    if ($c >= 'a' && $c <= 'f') {
      return hexdec($c) % 10;
    }
    return 0;
  }

  protected static function cacheGetBlock($blockNum)
  {
    try {
      $raw = RedisClient::conn()->get(RedisClient::key('tron:block:' . (int)$blockNum));
      if ($raw) {
        $j = json_decode($raw, true);
        if (is_array($j) && !empty($j['block_id']) && !empty($j['block_num'])) {
          return [
            'block_id'  => (string)$j['block_id'],
            'block_num' => (int)$j['block_num'],
            'confirmed' => !empty($j['confirmed']),
          ];
        }
      }
    } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Support.TronBlockClient');
        }
    return null;
  }

  protected static function cachePutBlock(array $block)
  {
    $num = (int)($block['block_num'] ?? 0);
    if ($num <= 0 || empty($block['block_id'])) {
      return;
    }
    try {
      $ttl = self::blockCacheTtl();
      RedisClient::conn()->setex(
        RedisClient::key('tron:block:' . $num),
        $ttl,
        json_encode([
          'block_id'  => (string)$block['block_id'],
          'block_num' => $num,
          'confirmed' => !empty($block['confirmed']),
        ], JSON_UNESCAPED_UNICODE)
      );
    } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Support.TronBlockClient');
        }
  }

  protected static function nowCacheTtl()
  {
    $n = (float)(self::cfg()['now_cache_ttl'] ?? 2);
    return max(1.0, min(10.0, $n));
  }

  protected static function blockCacheTtl()
  {
    $n = (int)(self::cfg()['block_cache_ttl'] ?? 86400);
    return max(300, min(604800, $n)); // 5 分钟 ~ 7 天
  }

  protected static function cfg()
  {
    if (self::$cfg !== null) {
      return self::$cfg;
    }
    $cfg = [];
    try {
      $app = require dirname(__DIR__, 2) . '/config/app.php';
      if (is_array($app) && !empty($app['tron']) && is_array($app['tron'])) {
        $cfg = $app['tron'];
      }
    } catch (\Throwable $e) {
            CatchLog::quiet($e, 'Support.TronBlockClient');
        }
    self::$cfg = $cfg;
    return self::$cfg;
  }

  protected static function apiBase()
  {
    $c = self::cfg();
    $base = trim((string)($c['api_url'] ?? ''));
    return $base !== '' ? rtrim($base, '/') : self::API_BASE_DEFAULT;
  }

  protected static function apiKey()
  {
    return trim((string)(self::cfg()['api_key'] ?? ''));
  }

  protected static function post($path, $body, $timeout = 5)
  {
    $url = self::apiBase() . $path;
    $ch = curl_init($url);
    if ($ch === false) {
      throw new \RuntimeException('curl init failed');
    }
    $payload = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE);
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    $apiKey = self::apiKey();
    if ($apiKey !== '') {
      $headers[] = 'TRON-PRO-API-KEY: ' . $apiKey;
    }
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST           => true,
      CURLOPT_HTTPHEADER     => $headers,
      CURLOPT_POSTFIELDS     => $payload,
      CURLOPT_CONNECTTIMEOUT => min(3, (int)$timeout),
      CURLOPT_TIMEOUT        => (int)$timeout,
      CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $raw === '') {
      throw new \RuntimeException('tron http fail: ' . ($err ?: 'empty'));
    }
    if ($code < 200 || $code >= 300) {
      throw new \RuntimeException('tron http ' . $code);
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
      throw new \RuntimeException('tron invalid json');
    }
    return $json;
  }
}
