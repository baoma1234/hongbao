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
                    $seedId = (int)$rows[0]['id'];
                    $salt = 'round:' . $roundId . ':user:' . $uid;
                    $tail = self::deriveTail($tronId, $seedId, $salt);
                    $meta = self::calcNiu($tail);
                    foreach ($rows as $s) {
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
                            'share_id' => (int)$s['id'],
                            'share_no' => (int)($s['share_no'] ?? 0),
                            'user_id' => $uid,
                            'computed_tail' => $meta['tail'],
                            'computed_niu' => $meta['label'],
                            'stored_tail' => $storedTail !== '' ? $storedTail : null,
                            'stored_niu' => (string)($s['niu_label'] ?? ''),
                            'match' => $ok,
                        ];
                        $computed[] = $row;
                        if ($storedTail !== '') {
                            $stored[] = $row;
                        }
                    }
                }
            } else {
                foreach ($shares as $s) {
                    $sid = (int)$s['id'];
                    $salt = 'round:' . $roundId;
                    $tail = self::deriveTail($tronId, $sid, $salt);
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
                        'share_no' => (int)($s['share_no'] ?? 0),
                        'user_id' => (int)$s['user_id'],
                        'computed_tail' => $meta['tail'],
                        'computed_niu' => $meta['label'],
                        'stored_tail' => $storedTail !== '' ? $storedTail : null,
                        'stored_niu' => (string)($s['niu_label'] ?? ''),
                        'match' => $ok,
                    ];
                    $computed[] = $row;
                    if ($storedTail !== '') {
                        $stored[] = $row;
                    }
                }
            }
        }

        if ($status === self::STATUS_CLAIMING) {
            $hint = '领取阶段仅展示已领结果；结算完成后开放 Block Hash 与全部尾数复算。';
        } elseif ($revealed) {
            $hint = '尾数由 Block Hash + 份号 SHA256 派生（00-99），可与下方复算结果对照。';
        } else {
            $hint = '购入结束后绑定波场区块哈希，再派生每包尾数；页面将自动重试。';
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
