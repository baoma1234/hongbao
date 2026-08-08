<?php

namespace Im\Support;

/**
 * drand (League of Entropy) 公共随机源客户端
 * @see https://api.drand.sh
 */
class DrandClient
{
    /** @var string */
    protected $base;
    /** @var int */
    protected $period;

    public function __construct($base = 'https://api.drand.sh', $period = 30)
    {
        $this->base = rtrim((string)$base, '/');
        if ($this->base === '') {
            $this->base = 'https://api.drand.sh';
        }
        $this->period = max(1, (int)$period);
    }

    /**
     * @return array{round:int,randomness:string,signature?:string,previous_signature?:string}
     */
    public function latest()
    {
        return $this->getJson($this->base . '/public/latest');
    }

    /**
     * @return array{round:int,randomness:string,signature?:string,previous_signature?:string}
     */
    public function getRound($round)
    {
        $round = (int)$round;
        if ($round <= 0) {
            throw new \RuntimeException('invalid drand round');
        }
        return $this->getJson($this->base . '/public/' . $round);
    }

    /**
     * 按购入时长锁定未来轮次（开局展示凭证，结束后再取 randomness）
     */
    public function lockFutureRound($buySeconds)
    {
        $buySeconds = max(30, (int)$buySeconds);
        $latest = $this->latest();
        $cur = (int)($latest['round'] ?? 0);
        if ($cur <= 0) {
            throw new \RuntimeException('drand latest unavailable');
        }
        $ahead = (int)ceil($buySeconds / $this->period) + 2;
        $target = $cur + max(2, $ahead);
        return [
            'round'       => $target,
            'locked_from' => $cur,
            'period'      => $this->period,
            'url'         => $this->base . '/public/' . $target,
        ];
    }

    /**
     * 等待并拉取目标轮次（若尚未出块则重试）
     * @return array{round:int,randomness:string}
     */
    public function fetchWhenReady($round, $maxWaitSec = 90)
    {
        $round = (int)$round;
        $deadline = time() + max(10, (int)$maxWaitSec);
        $lastErr = 'drand fetch failed';
        while (time() <= $deadline) {
            try {
                $row = $this->getRound($round);
                $rand = strtolower(trim((string)($row['randomness'] ?? '')));
                if ($rand !== '' && ctype_xdigit($rand)) {
                    return [
                        'round'       => (int)($row['round'] ?? $round),
                        'randomness'  => $rand,
                        'signature'   => (string)($row['signature'] ?? ''),
                        'url'         => $this->base . '/public/' . $round,
                    ];
                }
                $lastErr = 'empty randomness';
            } catch (\Throwable $e) {
                $lastErr = $e->getMessage();
            }
            usleep(800000);
        }
        throw new \RuntimeException('drand timeout: ' . $lastErr);
    }

    /**
     * 由 randomness + shareId 派生 00-99 尾数
     */
    public static function deriveTail($randomness, $shareId, $salt = '')
    {
        $raw = hash('sha256', strtolower(trim((string)$randomness)) . ':' . (int)$shareId . ':' . (string)$salt, true);
        $n = unpack('N', substr($raw, 0, 4))[1];
        $tail = $n % 100;
        return str_pad((string)$tail, 2, '0', STR_PAD_LEFT);
    }

    /**
     * @return array{round:int,randomness:string,signature?:string}
     */
    protected function getJson($url)
    {
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 8,
                'header'  => "Accept: application/json\r\nUser-Agent: FansHub-Niuniu/1.0\r\n",
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false || $body === '') {
            throw new \RuntimeException('drand http fail');
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new \RuntimeException('drand bad json');
        }
        return $json;
    }
}
