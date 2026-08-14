<?php

namespace app\common\library;

use think\Db;

/**
 * HTTP 侧红宝原子入账/扣款（与 IM WalletService::change SQL 形态对齐）。
 * 不负责 rights；兑换/调股仍走 FansHubService::changeAssets。
 */
class FansHubHongbaoLedger
{
    /**
     * @param array $meta channel/biz_no/ref_type/ref_id/admin_id
     * @return array{before:float,after:float,delta:float}
     */
    public static function credit($userId, $amount, $type, $remark = '', array $meta = [])
    {
        $userId = (int)$userId;
        $amount = round((float)$amount, 2);
        if ($userId <= 0 || $amount <= 0) {
            throw new \InvalidArgumentException('invalid credit');
        }
        $now = time();
        $ownTrans = !self::inTrans();
        if ($ownTrans) {
            Db::startTrans();
        }
        try {
            $aff = Db::name('fans_account')
                ->where('user_id', $userId)
                ->where('status', 'normal')
                ->inc('hongbao', $amount)
                ->update(['updatetime' => $now]);
            if ($aff <= 0) {
                $row = Db::name('fans_account')->where('user_id', $userId)->find();
                if (!$row) {
                    throw new \RuntimeException('account missing');
                }
                if ((string)($row['status'] ?? '') !== 'normal') {
                    throw new \RuntimeException(FansHubService::h5CopyText('srv_account_frozen') ?: 'account frozen');
                }
                throw new \RuntimeException('credit failed');
            }
            $row = Db::name('fans_account')->where('user_id', $userId)->find();
            $after = round((float)($row['hongbao'] ?? 0), 2);
            $before = round($after - $amount, 2);
            self::insertLedger($userId, (string)$type, $amount, $after, $row, $remark, $meta, $now);
            if ($ownTrans) {
                Db::commit();
            }
        } catch (\Throwable $e) {
            if ($ownTrans) {
                Db::rollback();
            }
            throw $e;
        }
        // 外层事务未提交前不 bust，避免 IM 读到旧快照又写回缓存
        if ($ownTrans) {
            FansHubImCache::bustWallet($userId);
        }
        return ['before' => $before, 'after' => $after, 'delta' => $amount];
    }

    /**
     * @param array $meta channel/biz_no/ref_type/ref_id/admin_id
     * @return array{before:float,after:float,delta:float}
     */
    public static function debit($userId, $amount, $type, $remark = '', array $meta = [], $countTurnover = false)
    {
        $userId = (int)$userId;
        $amount = round((float)$amount, 2);
        if ($userId <= 0 || $amount <= 0) {
            throw new \InvalidArgumentException('invalid debit');
        }
        $now = time();
        $ownTrans = !self::inTrans();
        if ($ownTrans) {
            Db::startTrans();
        }
        try {
            $q = Db::name('fans_account')
                ->where('user_id', $userId)
                ->where('status', 'normal')
                ->where('hongbao', '>=', $amount);
            if ($countTurnover) {
                $aff = $q->dec('hongbao', $amount)->inc('turnover', $amount)->update(['updatetime' => $now]);
            } else {
                $aff = $q->dec('hongbao', $amount)->update(['updatetime' => $now]);
            }
            if ($aff <= 0) {
                $row = Db::name('fans_account')->where('user_id', $userId)->find();
                if (!$row) {
                    throw new \RuntimeException(FansHubService::h5CopyText('srv_insufficient_hongbao') ?: 'insufficient');
                }
                if ((string)($row['status'] ?? '') !== 'normal') {
                    throw new \RuntimeException(FansHubService::h5CopyText('srv_account_frozen') ?: 'account frozen');
                }
                throw new \RuntimeException(FansHubService::h5CopyText('srv_insufficient_hongbao') ?: 'insufficient');
            }
            $row = Db::name('fans_account')->where('user_id', $userId)->find();
            $after = round((float)($row['hongbao'] ?? 0), 2);
            $before = round($after + $amount, 2);
            self::insertLedger($userId, (string)$type, -$amount, $after, $row, $remark, $meta, $now);
            if ($ownTrans) {
                Db::commit();
            }
        } catch (\Throwable $e) {
            if ($ownTrans) {
                Db::rollback();
            }
            throw $e;
        }
        if ($ownTrans) {
            FansHubImCache::bustWallet($userId);
        }
        return ['before' => $before, 'after' => $after, 'delta' => -$amount];
    }

    protected static function insertLedger($userId, $type, $delta, $hongbaoAfter, $row, $remark, array $meta, $now)
    {
        $data = [
            'user_id'         => $userId,
            'type'            => $type,
            'rights_change'   => 0,
            'balance_change'  => 0,
            'hongbao_change'  => $delta,
            'rights_after'    => (float)($row['rights'] ?? 0),
            'balance_after'   => (float)($row['balance'] ?? 0),
            'hongbao_after'   => $hongbaoAfter,
            'remark'          => (string)$remark,
            'channel'         => (string)($meta['channel'] ?? ''),
            'createtime'      => $now,
        ];
        if (array_key_exists('biz_no', $meta)) {
            $data['biz_no'] = mb_substr((string)$meta['biz_no'], 0, 40);
        }
        if (array_key_exists('ref_type', $meta)) {
            $data['ref_type'] = mb_substr((string)$meta['ref_type'], 0, 32);
        }
        if (array_key_exists('ref_id', $meta)) {
            $data['ref_id'] = (int)$meta['ref_id'];
        }
        if (array_key_exists('admin_id', $meta)) {
            $data['admin_id'] = (int)$meta['admin_id'];
        }
        Db::name('fans_ledger')->insert($data);
    }

    protected static function inTrans()
    {
        try {
            return Db::getPdo()->inTransaction();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
