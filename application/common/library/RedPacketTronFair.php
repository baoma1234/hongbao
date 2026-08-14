<?php

namespace app\common\library;

use think\Cache;
use think\Db;

/**
 * 红包波场官方哈希公平性（替换本地 SHA-256）
 */
class RedPacketTronFair
{
    const STATUS_NONE = 0;
    const STATUS_PENDING = 1;
    const STATUS_DONE = 2;
    const STATUS_FAIL = 3;

    const CACHE_TTL = 86400;

    public static function typeLabel($packetType)
    {
        $t = (int)$packetType;
        if ($t === 2) {
            return '拼手气接龙';
        }
        if ($t === 3) {
            return '扫雷';
        }
        return '红包';
    }

    public static function cacheKey($packetNo)
    {
        return 'rp:tron:fair:' . trim((string)$packetNo);
    }

    public static function cachePut(array $view)
    {
        $no = (string)($view['packet_no'] ?? '');
        if ($no === '') {
            return;
        }
        try {
            Cache::set(self::cacheKey($no), $view, self::CACHE_TTL);
        } catch (\Throwable $e) {
            // ignore
        }
        try {
            if (class_exists('\\Redis')) {
                $r = new \Redis();
                if (@$r->connect('127.0.0.1', 6379, 1.5)) {
                    $r->setex(self::cacheKey($no), self::CACHE_TTL, json_encode($view, JSON_UNESCAPED_UNICODE));
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }

    public static function cacheGet($packetNo)
    {
        $packetNo = trim((string)$packetNo);
        if ($packetNo === '') {
            return null;
        }
        try {
            $v = Cache::get(self::cacheKey($packetNo));
            if (is_array($v) && !empty($v['tron_block_id'])) {
                return $v;
            }
        } catch (\Throwable $e) {
        }
        try {
            if (class_exists('\\Redis')) {
                $r = new \Redis();
                if (@$r->connect('127.0.0.1', 6379, 1.5)) {
                    $raw = $r->get(self::cacheKey($packetNo));
                    if ($raw) {
                        $j = json_decode($raw, true);
                        if (is_array($j) && !empty($j['tron_block_id'])) {
                            return $j;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
        }
        return null;
    }

    /**
     * 开奖调度已收口到 IM TronFair；ThinkPHP 侧不再写包 / 入队。
     */
    public static function scheduleReveal($packetId, $delaySec = 4)
    {
        return false;
    }

    /**
     * 只读：IM 为唯一 reveal 写者。若已 DONE 返回视图；否则返回 pending（不改库）。
     */
    public static function processReveal($packetId, $allowRetry = true)
    {
        $packetId = (int)$packetId;
        $packet = Db::name('chat_red_packets')->where('id', $packetId)->find();
        if (!$packet) {
            return ['ok' => false, 'msg' => 'not found'];
        }
        if ((int)($packet['tron_status'] ?? 0) === self::STATUS_DONE && trim((string)($packet['tron_block_id'] ?? '')) !== '') {
            $view = self::publicView($packet);
            self::cachePut($view);
            return ['ok' => true, 'msg' => 'already', 'data' => $view];
        }
        $view = self::publicView($packet);
        return ['ok' => false, 'msg' => 'pending_im_reveal', 'data' => $view];
    }

    /**
     * Crontab 兜底已收口到 IM；此处不再扫库写开奖。
     */
    public static function pollPending($limit = 30)
    {
        return [
            'scanned'      => 0,
            'ok'           => 0,
            'fail'         => 0,
            'prefetch_num' => 0,
            'skipped'      => 'im_only_reveal',
        ];
    }

    public static function publicView(array $packet, array $records = [])
    {
        $revealed = (int)($packet['tron_status'] ?? 0) === self::STATUS_DONE
            && trim((string)($packet['tron_block_id'] ?? '')) !== '';
        $type = (int)($packet['packet_type'] ?? 0);
        $blockNum = (int)($packet['tron_block_num'] ?? 0);
        $blockId = trim((string)($packet['tron_block_id'] ?? ''));
        $fairHash = $blockId !== '' ? $blockId : trim((string)($packet['fair_hash'] ?? ''));
        $luckyChar = $revealed
            ? (string)($packet['tron_lucky'] ?? TronBlockClient::luckyFromBlockId($blockId))
            : '';
        $luckyDigit = $revealed ? TronBlockClient::luckyDigitFromBlockId($blockId) : null;
        $mineDigit = $type === 3 ? (int)($packet['mine_digit'] ?? 0) : ($revealed ? (int)$luckyDigit : null);
        $packetNo = (string)($packet['packet_no'] ?? '');
        $poolAmount = (float)($packet['pool_amount'] ?? 0);
        $totalCount = (int)($packet['total_count'] ?? 0);
        $poolCent = (int)round($poolAmount * 100);
        $minCent = 1;

        $storedCents = [];
        $rawFairCents = trim((string)($packet['fair_cents'] ?? ''));
        if ($rawFairCents !== '') {
            $decoded = json_decode($rawFairCents, true);
            if (is_array($decoded)) {
                foreach ($decoded as $c) {
                    $storedCents[] = (int)$c;
                }
            }
        }

        $computedCents = [];
        if ($revealed && $blockId !== '' && $poolCent > 0 && $totalCount > 0) {
            try {
                $computedCents = self::splitLuckyFromHash($poolCent, $totalCount, $minCent, $blockId, $packetNo);
            } catch (\Throwable $e) {
                $computedCents = [];
            }
        }

        $grabCents = [];
        $grabRows = [];
        if ($records) {
            foreach ($records as $r) {
                if (isset($r['amount_cent'])) {
                    $cent = (int)$r['amount_cent'];
                } else {
                    $cent = (int)round((float)($r['amount'] ?? 0) * 100);
                }
                $grabCents[] = $cent;
                $grabRows[] = [
                    'amount'      => round($cent / 100, 2),
                    'amount_cent' => $cent,
                    'tail_digit'  => isset($r['tail_digit']) ? (int)$r['tail_digit'] : ($cent % 10),
                    'is_best'     => (int)($r['is_best'] ?? 0),
                    'is_worst'    => (int)($r['is_worst'] ?? 0),
                    'is_mine_hit' => (int)($r['is_mine_hit'] ?? 0),
                ];
            }
        }

        $matchStored = $computedCents !== [] && $storedCents !== [] && $computedCents === $storedCents;
        $matchGrab = false;
        if ($computedCents !== [] && $grabCents !== []) {
            $prefix = array_slice($computedCents, 0, count($grabCents));
            $matchGrab = $prefix === $grabCents;
        } elseif ($computedCents !== [] && $grabCents === []) {
            $matchGrab = true; // 尚未有人领取，拆包序列本身可核对
        }

        $mineDigitMatch = true;
        if ($type === 3 && $revealed && $luckyDigit !== null) {
            $mineDigitMatch = ((int)$luckyDigit === (int)$mineDigit);
        }

        $amountOk = $computedCents !== [] && ($storedCents === [] || $matchStored)
            && ($grabCents === [] || $matchGrab)
            && array_sum($computedCents) === $poolCent
            && $mineDigitMatch;

        $out = [
            'packet_no'        => $packetNo,
            'packet_type'      => $type,
            'type_label'       => self::typeLabel($type),
            'mine_digit'       => $mineDigit,
            'mine_pending'     => $type === 3 && !$revealed,
            'total_amount'     => (float)($packet['total_amount'] ?? 0),
            'pool_amount'      => $poolAmount,
            'pool_cent'        => $poolCent,
            'total_count'      => $totalCount,
            'min_cent'         => $minCent,
            'status'           => (int)($packet['status'] ?? 0),
            'proof_type'       => 'tron',
            'targetBlockNum'   => $blockNum,
            'tron_block_num'   => $blockNum,
            'block_id'         => $blockId,
            'tron_block_id'    => $blockId,
            'luckyNumber'      => $luckyChar,
            'tron_lucky'       => $luckyChar,
            'lucky_digit'      => $luckyDigit,
            'tron_status'      => (int)($packet['tron_status'] ?? 0),
            'fair_hash'        => $fairHash,
            'revealed'         => $revealed,
            'fair_revealed_at' => (int)($packet['fair_revealed_at'] ?? 0),
            'tronscan_url'     => $blockNum > 0 ? ('https://tronscan.org/#/block/' . $blockNum) : ($blockId !== '' ? ('https://tronscan.org/#/block/' . $blockId) : ''),
            'verify_hint'      => $revealed
                ? ('金额由 Block Hash + 单号链下拆分；哈希末位 ' . $luckyChar
                    . ($type === 3 ? (' 须等于埋雷 ' . (int)$mineDigit . '；中雷看金额尾数') : ''))
                : ($type === 3
                    ? ('等待哈希末位=' . (int)$mineDigit . ' 的官方区块')
                    : ($blockNum > 0 ? ('已锁定区块高度 #' . $blockNum) : '待写入波场哈希')),
            'fair_cents'       => $storedCents,
            'computed_cents'   => $computedCents,
            'grab_cents'       => $grabCents,
            'grab_records'     => $grabRows,
            'amount_verify'    => [
                'ok'              => $amountOk,
                'sum_ok'          => $computedCents !== [] && array_sum($computedCents) === $poolCent,
                'match_stored'    => $matchStored || ($storedCents === [] && $computedCents !== []),
                'match_grabs'     => $matchGrab,
                'mine_digit_match'=> $mineDigitMatch,
                'has_stored'      => $storedCents !== [],
                'has_grabs'       => $grabCents !== [],
                'algorithm'       => 'sha256(block_id|packet_no|rp-split) + double-mean',
            ],
        ];
        return $out;
    }

    /**
     * 与 IM RedPacketService::splitLuckyFromHash 一致：用哈希确定性拆分（分）
     * @return int[]
     */
    public static function splitLuckyFromHash($totalCent, $count, $minCent, $blockId, $packetNo)
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

        for ($i = count($arr) - 1; $i > 0; $i--) {
            $j = $nextInt(0, $i);
            $tmp = $arr[$i];
            $arr[$i] = $arr[$j];
            $arr[$j] = $tmp;
        }
        return $arr;
    }
}
