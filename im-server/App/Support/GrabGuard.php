<?php

namespace Im\Support;

/**
 * 红包抢包风控：超人手速 / 多群并发 → 强制滑块验证
 *
 * 热路径用 mGet / pipeline 合并 Redis 往返，判定逻辑不变。
 */
class GrabGuard
{
  /** 连续间隔低于该毫秒数视为异常 */
  const MIN_INTERVAL_MS = 150;
  /** 连续异常次数触发滑块 */
  const SPEED_STREAK = 3;
  /** 多群并发检测窗口（毫秒） */
  const MULTI_WINDOW_MS = 2000;
  /** 窗口内不同群数量阈值 */
  const MULTI_GROUP_LIMIT = 3;
  /** 历史采样条数 */
  const HISTORY = 12;
  /** 需滑块标记 TTL */
  const NEED_TTL = 600;
  /** 滑块挑战 TTL */
  const SLIDER_TTL = 300;

  public static function cfg()
  {
    static $c = null;
    if ($c !== null) {
      return $c;
    }
    $c = [
      'enabled'           => true,
      'min_interval_ms'   => self::MIN_INTERVAL_MS,
      'speed_streak'      => self::SPEED_STREAK,
      'multi_window_ms'   => self::MULTI_WINDOW_MS,
      'multi_group_limit' => self::MULTI_GROUP_LIMIT,
    ];
    try {
      $app = require dirname(__DIR__, 2) . '/config/app.php';
      if (!empty($app['grab_guard']) && is_array($app['grab_guard'])) {
        $c = array_merge($c, $app['grab_guard']);
      }
    } catch (\Throwable $e) {
    }
    return $c;
  }

  /**
   * 抢包前检查。通过返回 null；需滑块抛 RuntimeException(slider_required)
   *
   * @param array $sliderPayload slider_token/slider_x/slider_duration/slider_max
   */
  public static function assertGrabAllowed($userId, $ip, $deviceFp, $groupId, array $sliderPayload = [])
  {
    $cfg = self::cfg();
    if (empty($cfg['enabled'])) {
      return;
    }
    $userId = (int)$userId;
    if ($userId <= 0) {
      return;
    }
    $ip = self::normIp($ip);
    $fp = self::normFp($deviceFp);
    $groupId = (int)$groupId;
    $nowMs = (int)floor(microtime(true) * 1000);

    // 一次 mGet 读 challenge；再 pipeline 读速度历史 + 多群窗口
    $challenged = self::anyChallenged($userId, $ip, $fp);
    if ($challenged) {
      if (!self::consumeSlider($userId, $sliderPayload)) {
        throw new \RuntimeException('slider_required');
      }
      self::clearChallenge($userId, $ip, $fp);
      self::recordGrab($userId, $ip, $fp, $groupId, $nowMs);
      return;
    }

    $snap = self::fetchDetectSnapshot($userId, $ip, $fp, $groupId, $nowMs, $cfg);
    $hit = self::speedAbuseFromTimes($snap['times'], $nowMs, $cfg)
      || self::multiGroupFromMembers($snap['multi_members'], $groupId, $cfg);

    if ($hit) {
      self::markChallenge($userId, $ip, $fp);
      if (!self::consumeSlider($userId, $sliderPayload)) {
        throw new \RuntimeException('slider_required');
      }
      self::clearChallenge($userId, $ip, $fp);
    }

    self::recordGrab($userId, $ip, $fp, $groupId, $nowMs);
  }

  /**
   * @return array{times:array<string,int[]>,multi_members:string[]}
   */
  protected static function fetchDetectSnapshot($userId, $ip, $fp, $groupId, $nowMs, array $cfg)
  {
    $dims = self::speedDims($userId, $ip, $fp);
    $times = [];
    foreach ($dims as $dim) {
      $times[$dim] = [];
    }
    $multiMembers = [];
    $window = max(500, (int)$cfg['multi_window_ms']);

    try {
      $r = RedisClient::conn();
      $pipe = $r->multi(\Redis::PIPELINE);
      foreach ($dims as $dim) {
        $pipe->lRange(RedisClient::key('grab:spd:' . $dim), 0, self::HISTORY - 1);
      }
      if ($groupId > 0) {
        $multiKey = RedisClient::key('grab:multi:u:' . (int)$userId);
        // 清理过期 + 读窗口内成员，一次 pipeline
        $pipe->zRemRangeByScore($multiKey, 0, $nowMs - $window);
        $pipe->zRangeByScore($multiKey, $nowMs - $window, $nowMs);
      }
      $rets = $pipe->exec();
      if (!is_array($rets)) {
        return ['times' => $times, 'multi_members' => $multiMembers];
      }
      $i = 0;
      foreach ($dims as $dim) {
        $raw = $rets[$i++] ?? [];
        if (is_array($raw)) {
          $times[$dim] = array_map('intval', $raw);
        }
      }
      if ($groupId > 0) {
        $i++; // skip zRem result
        $members = $rets[$i] ?? [];
        if (is_array($members)) {
          $multiMembers = $members;
        }
      }
    } catch (\Throwable $e) {
    }
    return ['times' => $times, 'multi_members' => $multiMembers];
  }

  /**
   * @param array<string,int[]> $timesByDim
   */
  protected static function speedAbuseFromTimes(array $timesByDim, $nowMs, array $cfg)
  {
    $minMs = max(50, (int)$cfg['min_interval_ms']);
    $streakNeed = max(2, (int)$cfg['speed_streak']);
    foreach ($timesByDim as $times) {
      if (count($times) < $streakNeed) {
        continue;
      }
      $ok = true;
      for ($i = 0; $i < $streakNeed; $i++) {
        $idx = count($times) - 1 - $i;
        $prev = $idx - 1;
        if ($prev < 0) {
          $ok = false;
          break;
        }
        $delta = (int)$times[$idx] - (int)$times[$prev];
        if ($delta >= $minMs) {
          $ok = false;
          break;
        }
      }
      if ($ok) {
        $last = (int)$times[count($times) - 1];
        if (($nowMs - $last) < $minMs) {
          return true;
        }
      }
    }
    return false;
  }

  protected static function multiGroupFromMembers(array $members, $groupId, array $cfg)
  {
    if ($groupId <= 0) {
      return false;
    }
    $limit = max(2, (int)$cfg['multi_group_limit']);
    $groups = [];
    foreach ($members as $m) {
      $gid = (int)explode(':', (string)$m, 2)[0];
      if ($gid > 0) {
        $groups[$gid] = true;
      }
    }
    $groups[$groupId] = true;
    return count($groups) >= $limit;
  }

  protected static function recordGrab($userId, $ip, $fp, $groupId, $nowMs)
  {
    $dims = self::speedDims($userId, $ip, $fp);
    try {
      $r = RedisClient::conn();
      $pipe = $r->multi(\Redis::PIPELINE);
      foreach ($dims as $dim) {
        $key = RedisClient::key('grab:spd:' . $dim);
        $pipe->rPush($key, (string)$nowMs);
        $pipe->lTrim($key, -self::HISTORY, -1);
        $pipe->expire($key, 120);
      }
      if ($groupId > 0) {
        $key = RedisClient::key('grab:multi:u:' . (int)$userId);
        $pipe->zAdd($key, $nowMs, $groupId . ':' . $nowMs);
        $pipe->expire($key, 30);
      }
      $pipe->exec();
    } catch (\Throwable $e) {
    }
  }

  /**
   * @return string[]
   */
  protected static function speedDims($userId, $ip, $fp)
  {
    $dims = ['u:' . (int)$userId];
    if ($ip !== '') {
      $dims[] = 'ip:' . $ip;
    }
    if ($fp !== '') {
      $dims[] = 'fp:' . $fp;
    }
    return $dims;
  }

  public static function markChallenge($userId, $ip = '', $fp = '')
  {
    try {
      $r = RedisClient::conn();
      $pipe = $r->multi(\Redis::PIPELINE);
      $pipe->setex(RedisClient::key('grab:need:u:' . (int)$userId), self::NEED_TTL, '1');
      if ($ip !== '') {
        $pipe->setex(RedisClient::key('grab:need:ip:' . $ip), self::NEED_TTL, '1');
      }
      if ($fp !== '') {
        $pipe->setex(RedisClient::key('grab:need:fp:' . $fp), self::NEED_TTL, '1');
      }
      $pipe->exec();
    } catch (\Throwable $e) {
    }
  }

  public static function clearChallenge($userId, $ip = '', $fp = '')
  {
    try {
      $keys = [RedisClient::key('grab:need:u:' . (int)$userId)];
      if ($ip !== '') {
        $keys[] = RedisClient::key('grab:need:ip:' . $ip);
      }
      if ($fp !== '') {
        $keys[] = RedisClient::key('grab:need:fp:' . $fp);
      }
      RedisClient::conn()->del(...$keys);
    } catch (\Throwable $e) {
    }
  }

  protected static function anyChallenged($userId, $ip, $fp)
  {
    try {
      $keys = [RedisClient::key('grab:need:u:' . (int)$userId)];
      if ($ip !== '') {
        $keys[] = RedisClient::key('grab:need:ip:' . $ip);
      }
      if ($fp !== '') {
        $keys[] = RedisClient::key('grab:need:fp:' . $fp);
      }
      $vals = RedisClient::conn()->mGet($keys);
      if (!is_array($vals)) {
        return false;
      }
      foreach ($vals as $v) {
        if ($v) {
          return true;
        }
      }
      return false;
    } catch (\Throwable $e) {
      return false;
    }
  }

  public static function isChallenged($userId)
  {
    try {
      return (bool)RedisClient::conn()->get(RedisClient::key('grab:need:u:' . (int)$userId));
    } catch (\Throwable $e) {
      return false;
    }
  }

  /**
   * 创建滑块挑战（HTTP / IM 共用 Redis）
   */
  public static function createSliderChallenge($width = 280)
  {
    $token = bin2hex(random_bytes(16));
    $width = max(200, (int)$width);
    $payload = json_encode([
      't' => time(),
      'w' => $width,
    ], JSON_UNESCAPED_UNICODE);
    RedisClient::conn()->setex(RedisClient::key('grab_slider:' . $token), self::SLIDER_TTL, $payload);
    return [
      'enabled' => true,
      'mode'    => 'slide',
      'token'   => $token,
      'width'   => $width,
      'hint'    => '检测到异常抢包，请拖动滑块完成验证',
      'pass_ratio' => 0.82,
    ];
  }

  /**
   * 校验并消费滑块（一次性）
   * 以「拖动行程比例」判定，兼容不同屏幕轨道宽度；并校验 max 与挑战宽度大致吻合，防伪造。
   */
  public static function consumeSlider($userId, array $payload)
  {
    $token = trim((string)($payload['slider_token'] ?? ''));
    if ($token === '') {
      return false;
    }
    $x = (int)($payload['slider_x'] ?? 0);
    $max = (int)($payload['slider_max'] ?? 0);
    $duration = (int)($payload['slider_duration'] ?? 0);
    try {
      $r = RedisClient::conn();
      $key = RedisClient::key('grab_slider:' . $token);
      $raw = $r->get($key);
      if (!$raw) {
        return false;
      }
      $data = json_decode($raw, true);
      if (!is_array($data)) {
        return false;
      }
      // 过短视为脚本；略放宽到 180ms，真人跟手更顺
      if ($duration > 0 && $duration < 180) {
        return false;
      }
      $width = max(200, (int)($data['w'] ?? 280));
      $ratio = 0.82;
      if ($max >= 40) {
        $expectedMax = max(40, $width - 42);
        if ($max < (int)floor($expectedMax * 0.55) || $max > (int)ceil($expectedMax * 1.5)) {
          return false;
        }
        if ($x < (int)floor($max * $ratio)) {
          return false;
        }
      } else {
        // 兼容旧客户端：按挑战宽度估算
        if ($x < (int)floor($width * $ratio)) {
          return false;
        }
      }
      $r->del($key);
      return true;
    } catch (\Throwable $e) {
      return false;
    }
  }

  protected static function normIp($ip)
  {
    $ip = trim((string)$ip);
    if ($ip === '' || $ip === '127.0.0.1' || $ip === '::1') {
      // 本机也参与统计，但用固定桶
      return $ip !== '' ? $ip : '';
    }
    return substr($ip, 0, 64);
  }

  protected static function normFp($fp)
  {
    $fp = strtolower(trim((string)$fp));
    if ($fp === '' || strlen($fp) < 8) {
      return '';
    }
    return substr($fp, 0, 64);
  }
}
