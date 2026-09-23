<?php

namespace app\common\library;

use app\common\model\User;
use think\Db;

/**
 * OG视讯业务层：与前端 profile（user_id / nickname / hongbao）对齐
 */
class FansHubOg
{
    /**
     * 与前端 profile.user_id 一一对应的 OG player_id
     * 规则：u + userId（不足 7 位数字左侧补 0，总长 ≥ 8）
     */
    public static function playerIdForUser($userId)
    {
        $uid = max(0, (int)$userId);
        return 'u' . str_pad((string)$uid, 7, '0', STR_PAD_LEFT);
    }

    /**
     * 与前端 profile.nickname 对齐：仅保留字母数字下划线；
     * 中文昵称无法直接用时回退 n{userId}
     */
    public static function nicknameForUser($userId, $nickname = null)
    {
        $uid = max(0, (int)$userId);
        if ($nickname === null) {
            $user = User::get($uid);
            $nickname = $user ? (string)($user->nickname ?? '') : '';
        }
        $s = preg_replace('/[^A-Za-z0-9_]/', '', (string)$nickname);
        if (strlen($s) >= 8) {
            return substr($s, 0, 64);
        }
        return 'n' . str_pad((string)$uid, 7, '0', STR_PAD_LEFT);
    }

    /**
     * 前端同步快照（注册前/后都可调）
     *
     * @return array<string,mixed>
     */
    public static function playerSnapshot($userId)
    {
        $uid = (int)$userId;
        $user = User::get($uid);
        if (!$user) {
            FansHubService::throwCopy('srv_user_not_found');
        }
        $account = FansHubService::getOrCreateAccount($uid);
        $playerId = self::playerIdForUser($uid);
        $ogNick = self::nicknameForUser($uid, (string)($user->nickname ?? ''));
        $row = self::playerRow($uid);

        return [
            'og_enabled'       => FansHubOgGateway::isEnabled(),
            'credentials_ok'   => FansHubOgGateway::credentialsReady(),
            'user_id'          => $uid,
            'nickname'         => (string)($user->nickname ?? ''),
            'hongbao'          => round((float)($account->hongbao ?? 0), 2),
            'player_id'        => $playerId,
            'og_nickname'      => $ogNick,
            'registered'       => $row && (string)($row['status'] ?? '') === 'registered',
            'registered_at'    => $row ? (int)($row['registered_at'] ?? 0) : 0,
            'last_rs_code'     => $row ? (string)($row['last_rs_code'] ?? '') : '',
            'last_rs_message'  => $row ? (string)($row['last_rs_message'] ?? '') : '',
        ];
    }

    /**
     * 确保已在 OG 注册（对齐前端当前用户）
     *
     * @param bool $force 忽略本地已注册缓存，重新调 register
     * @return array<string,mixed>
     */
    public static function ensureRegistered($userId, $force = false)
    {
        if (!FansHubOgGateway::isEnabled()) {
            throw new \RuntimeException('OG视讯未开启');
        }
        if (!FansHubOgGateway::credentialsReady()) {
            throw new \RuntimeException('OG商户配置不完整');
        }

        $snap = self::playerSnapshot($userId);
        if (!$force && !empty($snap['registered'])) {
            $snap['just_registered'] = false;
            $snap['rs_code'] = 'S-121';
            $snap['rs_message'] = 'already registered locally';
            return $snap;
        }

        $ret = FansHubOgGateway::registerPlayer($snap['player_id'], $snap['og_nickname']);
        self::upsertPlayer($userId, $snap['player_id'], $snap['og_nickname'], $ret);

        $snap = self::playerSnapshot($userId);
        $snap['just_registered'] = !empty($ret['ok']) && (string)($ret['rs_code'] ?? '') === 'S-100';
        $snap['rs_code'] = (string)($ret['rs_code'] ?? '');
        $snap['rs_message'] = (string)($ret['rs_message'] ?? '');
        if (empty($ret['ok'])) {
            throw new \RuntimeException(
                'OG注册失败：' . ($snap['rs_code'] !== '' ? $snap['rs_code'] . ' ' : '') . $snap['rs_message']
            );
        }
        return $snap;
    }

    /**
     * 玩家转账 · 存入：扣本站红宝 → OG deposit
     *
     * 响应码：
     * - S-100 成功（含 balance）
     * - S-101 流水重复 → 视为已入账，不退款
     * - S-104 玩家不可用 → 强制重注册后重试一次；仍失败则退款
     * - 其它失败 → 退回红宝
     *
     * @return array<string,mixed>
     */
    public static function depositForUser($userId, $amount)
    {
        if (!FansHubOgGateway::isEnabled()) {
            throw new \RuntimeException('OG视讯未开启');
        }
        if (!FansHubOgGateway::credentialsReady()) {
            throw new \RuntimeException('OG商户配置不完整');
        }

        $uid = (int)$userId;
        $amt = round((float)$amount, 2);
        if ($amt <= 0) {
            throw new \RuntimeException('存入金额必须大于 0');
        }
        if ($amt > 99999999999999999999999999999999.99) {
            throw new \RuntimeException('存入金额超出限制');
        }

        $snap = self::ensureRegistered($uid);
        $playerId = (string)$snap['player_id'];
        $txid = self::newTransactionId($uid, 'd');

        $account = FansHubService::getOrCreateAccount($uid);
        $hongbao = round((float)($account->hongbao ?? 0), 2);
        if ($hongbao + 1e-8 < $amt) {
            throw new \RuntimeException('红宝余额不足');
        }

        $now = time();
        $transferId = 0;
        Db::startTrans();
        try {
            $transferId = (int)Db::name('fans_og_transfer')->insertGetId([
                'user_id'         => $uid,
                'player_id'       => $playerId,
                'direction'       => 'deposit',
                'transaction_id'  => $txid,
                'amount'          => $amt,
                'status'          => 'pending',
                'rs_code'         => '',
                'rs_message'      => '',
                'createtime'      => $now,
                'updatetime'      => $now,
            ]);
            FansHubService::changeAssets(
                $uid,
                0,
                0,
                'og_deposit',
                'OG视讯存入 ' . $txid,
                0,
                'og',
                -$amt
            );
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        $ret = FansHubOgGateway::deposit($playerId, $amt, $txid);

        // S-104：玩家不可用 → 强制注册后同 transaction_id 重试一次
        if (!empty($ret['player_missing']) || (string)($ret['rs_code'] ?? '') === 'S-104') {
            self::clearPlayerRegistered($uid);
            try {
                self::ensureRegistered($uid, true);
                $ret = FansHubOgGateway::deposit($playerId, $amt, $txid);
            } catch (\Throwable $e) {
                $ret = [
                    'ok'         => false,
                    'rs_code'    => 'S-104',
                    'rs_message' => $e->getMessage() ?: 'player not available',
                    'balance'    => '',
                ];
            }
        }

        $ok = !empty($ret['ok']);
        $code = (string)($ret['rs_code'] ?? '');
        $msg = (string)($ret['rs_message'] ?? '');
        $ogBalance = isset($ret['balance']) ? (string)$ret['balance'] : '';

        if ($ok) {
            Db::name('fans_og_transfer')->where('id', $transferId)->update([
                'status'     => 'success',
                'rs_code'    => $code,
                'rs_message' => $msg . ($ogBalance !== '' ? (' balance=' . $ogBalance) : ''),
                'updatetime' => time(),
            ]);
        } else {
            try {
                FansHubService::changeAssets(
                    $uid,
                    0,
                    0,
                    'og_deposit_refund',
                    'OG存入失败退回 ' . $txid,
                    0,
                    'og',
                    $amt
                );
            } catch (\Throwable $e) {
                // 退款失败仍标记 fail
            }
            $friendly = self::depositErrorText($code, $msg);
            Db::name('fans_og_transfer')->where('id', $transferId)->update([
                'status'     => 'fail',
                'rs_code'    => $code,
                'rs_message' => $msg !== '' ? $msg : FansHubOgGateway::getLastError(),
                'updatetime' => time(),
            ]);
            throw new \RuntimeException($friendly);
        }

        $account = FansHubService::getOrCreateAccount($uid);
        return [
            'user_id'         => $uid,
            'player_id'       => $playerId,
            'transaction_id'  => $txid,
            'transfer_amount' => FansHubOgGateway::formatAmount($amt),
            'rs_code'         => $code,
            'rs_message'      => $msg,
            'balance'         => $ogBalance,
            'og_balance'      => $ogBalance,
            'hongbao'         => round((float)($account->hongbao ?? 0), 2),
            'transfer_id'     => $transferId,
            'duplicate'       => !empty($ret['duplicate']) || $code === 'S-101',
        ];
    }

    protected static function depositErrorText($code, $msg)
    {
        $code = (string)$code;
        $msg = trim((string)$msg);
        if ($code === 'S-104') {
            return 'OG存入失败：玩家不可用（S-104），请稍后重试';
        }
        if ($code === 'S-101') {
            return 'OG存入失败：流水号重复（S-101）';
        }
        $tail = ($code !== '' ? $code . ' ' : '') . ($msg !== '' ? $msg : FansHubOgGateway::getLastError());
        return 'OG存入失败：' . trim($tail);
    }

    protected static function clearPlayerRegistered($userId)
    {
        try {
            Db::name('fans_og_player')->where('user_id', (int)$userId)->update([
                'status'     => 'fail',
                'updatetime' => time(),
            ]);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    public static function newTransactionId($userId, $prefix = 'd')
    {
        $prefix = strtolower(preg_replace('/[^a-z]/', '', (string)$prefix));
        if ($prefix === '') {
            $prefix = 'd';
        }
        // d + uid + time + random → 仅小写字母数字
        $raw = $prefix . (int)$userId . 't' . time() . 'r' . substr(md5(uniqid((string)mt_rand(), true)), 0, 10);
        return FansHubOgGateway::formatTransactionId($raw);
    }

    protected static function playerRow($userId)
    {
        try {
            return Db::name('fans_og_player')->where('user_id', (int)$userId)->find();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param array{ok?:bool,rs_code?:string,rs_message?:string,player_id?:string,nickname?:string} $ret
     */
    protected static function upsertPlayer($userId, $playerId, $ogNickname, array $ret)
    {
        $now = time();
        $ok = !empty($ret['ok']);
        $data = [
            'user_id'        => (int)$userId,
            'player_id'      => (string)$playerId,
            'og_nickname'    => (string)$ogNickname,
            'status'         => $ok ? 'registered' : 'fail',
            'last_rs_code'   => (string)($ret['rs_code'] ?? ''),
            'last_rs_message'=> (string)($ret['rs_message'] ?? ''),
            'updatetime'     => $now,
        ];
        if ($ok) {
            $data['registered_at'] = $now;
        }
        try {
            $exist = Db::name('fans_og_player')->where('user_id', (int)$userId)->find();
            if ($exist) {
                Db::name('fans_og_player')->where('user_id', (int)$userId)->update($data);
            } else {
                $data['createtime'] = $now;
                Db::name('fans_og_player')->insert($data);
            }
        } catch (\Throwable $e) {
            // 表未安装时仍允许调 OG，仅无本地缓存
        }
    }
}
