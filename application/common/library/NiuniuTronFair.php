<?php

namespace app\common\library;

use think\Db;

/**
 * 尾数牛牛：波场 Block Hash 公平性校验视图
 */
class NiuniuTronFair
{
    const STATUS_BUYING = 1;
    const STATUS_CLAIMING = 2;
    const STATUS_SETTLED = 3;
    const STATUS_VOID = 4;
    const STATUS_REFUND = 5;

    const MODE_NORMAL = 1;
    const MODE_SINGLE = 2;

    public static function deriveTail($randomness, $shareId, $salt = '')
    {
        $raw = hash('sha256', strtolower(trim((string)$randomness)) . ':' . (int)$shareId . ':' . (string)$salt, true);
        $n = unpack('N', substr($raw, 0, 4))[1];
        $tail = $n % 100;
        return str_pad((string)$tail, 2, '0', STR_PAD_LEFT);
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
                'point' => 0, 'label' => '牛牛',
            ];
        }
        return [
            'tail' => $tail, 'a' => $a, 'b' => $b, 'sum' => $sum,
            'point' => $point, 'label' => '牛' . $point,
        ];
    }

    public static function statusLabel($st)
    {
        $m = [
            self::STATUS_BUYING => '购入中',
            self::STATUS_CLAIMING => '领取中',
            self::STATUS_SETTLED => '已结算',
            self::STATUS_VOID => '流局',
            self::STATUS_REFUND => '已退回',
        ];
        return $m[(int)$st] ?? ('status ' . $st);
    }

    public static function modeLabel($mode)
    {
        return ((int)$mode === self::MODE_SINGLE) ? '尾数牛牛' : '尾数牛牛(多包)';
    }

    /**
     * @param array $round
     * @param array $shares rows from chat_niuniu_shares
     * @return array
     */
    public static function publicView(array $round, array $shares = [])
    {
        $roundId = (int)($round['id'] ?? 0);
        $status = (int)($round['status'] ?? 0);
        $mode = ((int)($round['game_mode'] ?? self::MODE_NORMAL) === self::MODE_SINGLE)
            ? self::MODE_SINGLE
            : self::MODE_NORMAL;
        $tronNum = (int)($round['tron_block_num'] ?? 0);
        if ($tronNum <= 0) {
            $tronNum = (int)($round['drand_round'] ?? 0);
        }
        $tronId = strtolower(trim((string)($round['tron_block_id'] ?? '')));
        if ($tronId === '') {
            $tronId = strtolower(trim((string)($round['drand_randomness'] ?? '')));
        }
        $tronStatus = (int)($round['tron_status'] ?? 0);
        if ($tronStatus <= 0 && $tronId !== '') {
            $tronStatus = 2;
        }
        // 仅结算后开放完整复算；领取中不返回 computed_tails / block hash
        $fullReveal = ($status >= self::STATUS_SETTLED || $status === self::STATUS_REFUND) && $tronId !== '';
        $revealed = $fullReveal;
        $tronscan = $tronNum > 0
            ? ('https://tronscan.org/#/block/' . $tronNum)
            : ($fullReveal && $tronId !== '' ? ('https://tronscan.org/#/block/' . $tronId) : '');

        $computed = [];
        $stored = [];
        $matchAll = true;
        $matchCount = 0;
        $checkCount = 0;

        if ($revealed && $shares) {
            if ($mode === self::MODE_SINGLE) {
                $byUser = [];
                foreach ($shares as $s) {
                    $uid = (int)$s['user_id'];
                    if (!isset($byUser[$uid])) {
                        $byUser[$uid] = [];
                    }
                    $byUser[$uid][] = $s;
                }
                foreach ($byUser as $uid => $rows) {
                    // 按真实领取序号；未领不参与复算展示
                    usort($rows, function ($a, $b) {
                        $ca = (int)($a['claimed'] ?? 0);
                        $cb = (int)($b['claimed'] ?? 0);
                        if ($ca !== $cb) {
                            return $cb - $ca;
                        }
                        $sa = (int)($a['claim_seq'] ?? 0);
                        $sb = (int)($b['claim_seq'] ?? 0);
                        if ($sa !== $sb) {
                            return $sa - $sb;
                        }
                        return ((int)$a['id']) - ((int)$b['id']);
                    });
                    $head = $rows[0];
                    $claimed = (int)($head['claimed'] ?? 0) === 1;
                    $seq = (int)($head['claim_seq'] ?? 0);
                    $seedId = $seq > 0 ? $seq : (int)$head['id'];
                    $salt = $seq > 0
                        ? ('round:' . $roundId . ':claim')
                        : ('round:' . $roundId . ':user:' . $uid);
                    $tail = self::deriveTail($tronId, $seedId, $salt);
                    $meta = self::calcNiu($tail);
                    $storedTail = (string)($head['tail_digits'] ?? '');
                    $ok = ($storedTail === '' || $storedTail === $meta['tail']);
                    if ($storedTail !== '') {
                        $checkCount++;
                        if ($ok) {
                            $matchCount++;
                        } else {
                            $matchAll = false;
                        }
                    }
                    $row = [
                        'share_id' => (int)$head['id'],
                        'claim_seq' => $seq,
                        'share_no' => (int)($head['share_no'] ?? 0),
                        'user_id' => $uid,
                        'share_count' => count($rows),
                        'claimed' => $claimed,
                        'claimed_at' => (int)($head['claimed_at'] ?? 0),
                        'computed_tail' => $claimed ? $meta['tail'] : null,
                        'computed_niu' => $claimed ? $meta['label'] : '未领取',
                        'stored_tail' => $storedTail !== '' ? $storedTail : null,
                        'stored_niu' => (string)($head['niu_label'] ?? ''),
                        'match' => $claimed ? $ok : null,
                    ];
                    $computed[] = $row;
                    if ($storedTail !== '') {
                        $stored[] = $row;
                    }
                }
            } else {
                foreach ($shares as $s) {
                    $sid = (int)$s['id'];
                    $claimed = (int)($s['claimed'] ?? 0) === 1;
                    $seq = (int)($s['claim_seq'] ?? 0);
                    $seedId = $seq > 0 ? $seq : $sid;
                    $salt = $seq > 0 ? ('round:' . $roundId . ':claim') : ('round:' . $roundId);
                    $tail = self::deriveTail($tronId, $seedId, $salt);
                    $meta = self::calcNiu($tail);
                    $storedTail = (string)($s['tail_digits'] ?? '');
                    $ok = ($storedTail === '' || $storedTail === $meta['tail']);
                    if ($storedTail !== '') {
                        $checkCount++;
                        if ($ok) {
                            $matchCount++;
                        } else {
                            $matchAll = false;
                        }
                    }
                    $row = [
                        'share_id' => $sid,
                        'claim_seq' => $seq,
                        'share_no' => (int)($s['share_no'] ?? 0),
                        'user_id' => (int)$s['user_id'],
                        'share_count' => 1,
                        'claimed' => $claimed,
                        'claimed_at' => (int)($s['claimed_at'] ?? 0),
                        'computed_tail' => $claimed ? $meta['tail'] : null,
                        'computed_niu' => $claimed ? $meta['label'] : '未领取',
                        'stored_tail' => $storedTail !== '' ? $storedTail : null,
                        'stored_niu' => (string)($s['niu_label'] ?? ''),
                        'match' => $claimed ? $ok : null,
                    ];
                    $computed[] = $row;
                    if ($storedTail !== '') {
                        $stored[] = $row;
                    }
                }
            }
            // 与领取明细一致：按真实领取时间 / 领取序号
            $sortClaim = function ($a, $b) {
                $ca = !empty($a['claimed']) ? 0 : 1;
                $cb = !empty($b['claimed']) ? 0 : 1;
                if ($ca !== $cb) {
                    return $ca - $cb;
                }
                $ta = (int)($a['claimed_at'] ?? 0);
                $tb = (int)($b['claimed_at'] ?? 0);
                if ($ta !== $tb) {
                    return $ta - $tb;
                }
                $sa = (int)($a['claim_seq'] ?? 0);
                $sb = (int)($b['claim_seq'] ?? 0);
                if ($sa !== $sb) {
                    return $sa - $sb;
                }
                return ((int)($a['share_id'] ?? 0)) - ((int)($b['share_id'] ?? 0));
            };
            usort($computed, $sortClaim);
            usort($stored, $sortClaim);
        }

        if ($status === self::STATUS_CLAIMING) {
            $hint = '领取后才按领取顺序从 Block Hash 派生尾数；未领取不出结果。结算后开放全部复算。';
        } elseif ($revealed) {
            $hint = '尾数由 Block Hash + 领取序号 SHA256 派生（00-99），可与下方复算结果对照。';
        } else {
            $hint = '购入结束后绑定波场区块哈希；领取时才按领取顺序派生尾数；页面将自动重试。';
        }

        return [
            'kind' => 'niuniu',
            'type_label' => self::modeLabel($mode),
            'round_id' => $roundId,
            'game_mode' => $mode,
            'status' => $status,
            'status_label' => self::statusLabel($status),
            'tron_status' => $tronStatus,
            'pool_amount' => round((float)($round['pool_amount'] ?? 0), 2),
            'distributable' => round((float)($round['distributable'] ?? 0), 2),
            'share_count' => (int)($round['share_count'] ?? 0),
            'share_price' => round((float)($round['share_price'] ?? 0), 2),
            'proof_type' => ($tronNum > 0 || $tronId !== '') ? 'tron' : 'drand',
            'tron_block_num' => $fullReveal ? $tronNum : (($status >= self::STATUS_CLAIMING) ? $tronNum : 0),
            'targetBlockNum' => $fullReveal ? $tronNum : (($status >= self::STATUS_CLAIMING) ? $tronNum : 0),
            'tron_block_id' => $fullReveal ? $tronId : '',
            'block_id' => $fullReveal ? $tronId : '',
            'fair_hash' => $fullReveal ? $tronId : '',
            'tronscan_url' => $fullReveal ? $tronscan : '',
            'revealed' => $revealed,
            'verify_hint' => $hint,
            'tail_verify' => [
                'ok' => $revealed && $checkCount > 0 ? $matchAll : null,
                'checked' => $checkCount,
                'matched' => $matchCount,
                'has_stored' => $checkCount > 0,
            ],
            'computed_tails' => $computed,
            'stored_tails' => $stored,
        ];
    }
}
