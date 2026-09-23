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

    /**
     * 玩家转账 · 提出：OG withdraw → 成功后再加本站红宝
     *
     * - S-100 成功（含 OG 剩余 balance）
     * - S-101 流水重复 → 视为已提出，补加红宝（仅 pending 时）
     * - S-103 余额不足 → 不加款
     * - S-104 玩家不可用 → 强制重注册后重试一次
     *
     * @return array<string,mixed>
     */
    public static function withdrawForUser($userId, $amount)
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
            throw new \RuntimeException('提出金额必须大于 0');
        }
        if ($amt > 99999999999999999999999999999999.99) {
            throw new \RuntimeException('提出金额超出限制');
        }

        $snap = self::ensureRegistered($uid);
        $playerId = (string)$snap['player_id'];
        $txid = self::newTransactionId($uid, 'w');

        $now = time();
        $transferId = 0;
        try {
            $transferId = (int)Db::name('fans_og_transfer')->insertGetId([
                'user_id'         => $uid,
                'player_id'       => $playerId,
                'direction'       => 'withdraw',
                'transaction_id'  => $txid,
                'amount'          => $amt,
                'status'          => 'pending',
                'rs_code'         => '',
                'rs_message'      => '',
                'createtime'      => $now,
                'updatetime'      => $now,
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException('创建提出流水失败：' . $e->getMessage());
        }

        $ret = FansHubOgGateway::withdraw($playerId, $amt, $txid);

        if (!empty($ret['player_missing']) || (string)($ret['rs_code'] ?? '') === 'S-104') {
            self::clearPlayerRegistered($uid);
            try {
                self::ensureRegistered($uid, true);
                $ret = FansHubOgGateway::withdraw($playerId, $amt, $txid);
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
            try {
                FansHubService::changeAssets(
                    $uid,
                    0,
                    0,
                    'og_withdraw',
                    'OG视讯提出 ' . $txid,
                    0,
                    'og',
                    $amt
                );
            } catch (\Throwable $e) {
                Db::name('fans_og_transfer')->where('id', $transferId)->update([
                    'status'     => 'fail',
                    'rs_code'    => $code,
                    'rs_message' => 'OG已扣但本站入账失败：' . $e->getMessage(),
                    'updatetime' => time(),
                ]);
                throw new \RuntimeException('OG已提出但本站入账失败，请联系客服（' . $txid . '）');
            }
            Db::name('fans_og_transfer')->where('id', $transferId)->update([
                'status'     => 'success',
                'rs_code'    => $code,
                'rs_message' => $msg . ($ogBalance !== '' ? (' balance=' . $ogBalance) : ''),
                'updatetime' => time(),
            ]);
        } else {
            Db::name('fans_og_transfer')->where('id', $transferId)->update([
                'status'     => 'fail',
                'rs_code'    => $code,
                'rs_message' => $msg !== '' ? $msg : FansHubOgGateway::getLastError(),
                'updatetime' => time(),
            ]);
            throw new \RuntimeException(self::withdrawErrorText($code, $msg));
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

    protected static function withdrawErrorText($code, $msg)
    {
        $code = (string)$code;
        $msg = trim((string)$msg);
        if ($code === 'S-103') {
            return 'OG提出失败：余额不足（S-103）';
        }
        if ($code === 'S-104') {
            return 'OG提出失败：玩家不可用（S-104），请稍后重试';
        }
        if ($code === 'S-101') {
            return 'OG提出失败：流水号重复（S-101）';
        }
        $tail = ($code !== '' ? $code . ' ' : '') . ($msg !== '' ? $msg : FansHubOgGateway::getLastError());
        return 'OG提出失败：' . trim($tail);
    }

    /**
     * OG 可用游戏列表（带短缓存；正式/沙箱 game_id 不同）
     *
     * @param array{game_id?:int,game_name?:string,game_type?:string,refresh?:bool} $opts
     * @return array<string,mixed>
     */
    public static function gameList(array $opts = [])
    {
        if (!FansHubOgGateway::isEnabled()) {
            throw new \RuntimeException('OG视讯未开启');
        }
        if (!FansHubOgGateway::credentialsReady()) {
            throw new \RuntimeException('OG商户配置不完整');
        }

        $query = [];
        if (isset($opts['game_id']) && $opts['game_id'] !== '' && $opts['game_id'] !== null) {
            $query['game_id'] = (int)$opts['game_id'];
        }
        $gname = trim((string)($opts['game_name'] ?? ''));
        if ($gname !== '') {
            $query['game_name'] = $gname;
        }
        $gtype = trim((string)($opts['game_type'] ?? ''));
        if ($gtype !== '') {
            $query['game_type'] = $gtype;
        }

        $refresh = !empty($opts['refresh']);
        $cfg = FansHubOgGateway::config();
        $cacheKey = 'fanshub_og_game_list_' . md5(json_encode([
            !empty($cfg['sandbox']) ? 'sb' : 'live',
            $cfg['merchant_code'] ?? '',
            $query,
        ], JSON_UNESCAPED_UNICODE));

        if (!$refresh && !$query) {
            try {
                $cached = \think\Cache::get($cacheKey);
                if (is_array($cached) && !empty($cached['records'])) {
                    $cached['cached'] = true;
                    return $cached;
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        $ret = FansHubOgGateway::gameList($query);
        if (empty($ret['ok'])) {
            $code = (string)($ret['rs_code'] ?? '');
            $msg = (string)($ret['rs_message'] ?? FansHubOgGateway::getLastError());
            throw new \RuntimeException(
                'OG游戏列表失败：' . trim(($code !== '' ? $code . ' ' : '') . $msg)
            );
        }

        $payload = [
            'rs_code'    => (string)($ret['rs_code'] ?? ''),
            'rs_message' => (string)($ret['rs_message'] ?? ''),
            'sandbox'    => !empty($ret['sandbox']),
            'records'    => is_array($ret['records'] ?? null) ? $ret['records'] : [],
            'fetched_at' => time(),
            'cached'     => false,
        ];

        // 全量列表缓存 5 分钟，方便大厅定期刷新
        if (!$query) {
            try {
                \think\Cache::set($cacheKey, $payload, 300);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return $payload;
    }

    /**
     * OG 投注限红组列表（短缓存；正式/沙箱 id 不同）
     *
     * @param array{id?:int,refresh?:bool} $opts
     * @return array<string,mixed>
     */
    public static function betLimitList(array $opts = [])
    {
        if (!FansHubOgGateway::isEnabled()) {
            throw new \RuntimeException('OG视讯未开启');
        }
        if (!FansHubOgGateway::credentialsReady()) {
            throw new \RuntimeException('OG商户配置不完整');
        }

        $query = [];
        if (isset($opts['id']) && $opts['id'] !== '' && $opts['id'] !== null) {
            $query['id'] = (int)$opts['id'];
        }
        $refresh = !empty($opts['refresh']);
        $cfg = FansHubOgGateway::config();
        $cacheKey = 'fanshub_og_betlimit_' . md5(json_encode([
            !empty($cfg['sandbox']) ? 'sb' : 'live',
            $cfg['merchant_code'] ?? '',
            $query,
        ], JSON_UNESCAPED_UNICODE));

        if (!$refresh && !$query) {
            try {
                $cached = \think\Cache::get($cacheKey);
                if (is_array($cached) && isset($cached['records'])) {
                    $cached['cached'] = true;
                    return $cached;
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        $ret = FansHubOgGateway::betLimit($query);
        if (empty($ret['ok'])) {
            $code = (string)($ret['rs_code'] ?? '');
            $msg = (string)($ret['rs_message'] ?? FansHubOgGateway::getLastError());
            throw new \RuntimeException(
                'OG限红列表失败：' . trim(($code !== '' ? $code . ' ' : '') . $msg)
            );
        }

        $payload = [
            'rs_code'    => (string)($ret['rs_code'] ?? ''),
            'rs_message' => (string)($ret['rs_message'] ?? ''),
            'sandbox'    => !empty($ret['sandbox']),
            'records'    => is_array($ret['records'] ?? null) ? $ret['records'] : [],
            'fetched_at' => time(),
            'cached'     => false,
        ];

        if (!$query) {
            try {
                \think\Cache::set($cacheKey, $payload, 300);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return $payload;
    }

    /**
     * 进入游戏：确保已注册 → launch → 返回 game_link
     *
     * @param array{game_id?:int,betlimit?:int,lang?:string,extra?:string} $opts
     * @return array<string,mixed>
     */
    public static function launchForUser($userId, array $opts = [])
    {
        if (!FansHubOgGateway::isEnabled()) {
            throw new \RuntimeException('OG视讯未开启');
        }
        if (!FansHubOgGateway::credentialsReady()) {
            throw new \RuntimeException('OG商户配置不完整');
        }

        $uid = (int)$userId;
        $snap = self::ensureRegistered($uid);
        $cfg = FansHubOgGateway::config();

        $gameId = (int)($opts['game_id'] ?? 0);
        if ($gameId <= 0) {
            $gameId = (int)($cfg['default_game_id'] ?? 0);
        }
        $betlimit = (int)($opts['betlimit'] ?? 0);
        if ($betlimit <= 0) {
            $betlimit = (int)($cfg['default_betlimit'] ?? 0);
        }
        if ($betlimit <= 0) {
            // 未配置时取限红列表第一组
            try {
                $bl = self::betLimitList(['refresh' => false]);
                $rows = is_array($bl['records'] ?? null) ? $bl['records'] : [];
                if ($rows) {
                    $betlimit = (int)($rows[0]['id'] ?? 0);
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
        if ($gameId <= 0) {
            throw new \RuntimeException('请指定 game_id（或后台配置默认游戏）');
        }
        if ($betlimit <= 0) {
            throw new \RuntimeException('请指定 betlimit（或后台配置默认限红组）');
        }

        $lang = trim((string)($opts['lang'] ?? ''));
        if ($lang === '') {
            $lang = (string)($cfg['language'] ?? 'zh') ?: 'zh';
        }
        $token = self::newLaunchToken($uid);

        $ret = FansHubOgGateway::launchGame([
            'player_id' => (string)$snap['player_id'],
            'nickname'  => (string)$snap['og_nickname'],
            'token'     => $token,
            'game_id'   => $gameId,
            'betlimit'  => $betlimit,
            'lang'      => $lang,
            'extra'     => trim((string)($opts['extra'] ?? '')),
        ]);

        if (empty($ret['ok']) || trim((string)($ret['game_link'] ?? '')) === '') {
            $code = (string)($ret['rs_code'] ?? '');
            $msg = (string)($ret['rs_message'] ?? FansHubOgGateway::getLastError());
            throw new \RuntimeException(
                'OG进游戏失败：' . trim(($code !== '' ? $code . ' ' : '') . $msg)
            );
        }

        // 短期缓存 token，便于排查
        try {
            \think\Cache::set('fanshub_og_launch_token_' . $token, [
                'user_id'   => $uid,
                'player_id' => (string)$snap['player_id'],
                'game_id'   => $gameId,
                'betlimit'  => $betlimit,
                'at'        => time(),
            ], 3600);
        } catch (\Throwable $e) {
            // ignore
        }

        return [
            'user_id'    => $uid,
            'player_id'  => (string)$snap['player_id'],
            'og_nickname'=> (string)$snap['og_nickname'],
            'game_id'    => $gameId,
            'betlimit'   => $betlimit,
            'lang'       => $lang,
            'token'      => $token,
            'game_link'  => (string)$ret['game_link'],
            'rs_code'    => (string)($ret['rs_code'] ?? ''),
            'rs_message' => (string)($ret['rs_message'] ?? ''),
            'sandbox'    => !empty($cfg['sandbox']),
        ];
    }

    /** 运营商侧玩家识别 token（小写字母数字） */
    public static function newLaunchToken($userId)
    {
        $raw = 't' . (int)$userId . 'x' . strtolower(bin2hex(random_bytes(8)));
        return FansHubOgGateway::formatTransactionId($raw);
    }

    /**
     * 查询当前用户 OG 筹码余额（可先 ensureRegistered）
     *
     * @param array{ensure_register?:bool} $opts
     * @return array<string,mixed>
     */
    public static function balanceForUser($userId, array $opts = [])
    {
        if (!FansHubOgGateway::isEnabled()) {
            throw new \RuntimeException('OG视讯未开启');
        }
        if (!FansHubOgGateway::credentialsReady()) {
            throw new \RuntimeException('OG商户配置不完整');
        }

        $uid = (int)$userId;
        $ensure = !isset($opts['ensure_register']) || !empty($opts['ensure_register']);
        if ($ensure) {
            $snap = self::ensureRegistered($uid);
            $playerId = (string)$snap['player_id'];
        } else {
            $playerId = self::playerIdForUser($uid);
        }

        $ret = FansHubOgGateway::getBalance($playerId);

        // S-104：强制注册后再查一次
        if (!empty($ret['player_missing']) || (string)($ret['rs_code'] ?? '') === 'S-104') {
            self::clearPlayerRegistered($uid);
            try {
                $snap = self::ensureRegistered($uid, true);
                $playerId = (string)$snap['player_id'];
                $ret = FansHubOgGateway::getBalance($playerId);
            } catch (\Throwable $e) {
                throw new \RuntimeException('OG余额查询失败：玩家不可用（S-104）');
            }
        }

        if (empty($ret['ok'])) {
            $code = (string)($ret['rs_code'] ?? '');
            $msg = (string)($ret['rs_message'] ?? FansHubOgGateway::getLastError());
            throw new \RuntimeException(
                'OG余额查询失败：' . trim(($code !== '' ? $code . ' ' : '') . $msg)
            );
        }

        $account = FansHubService::getOrCreateAccount($uid);
        return [
            'user_id'         => $uid,
            'player_id'       => (string)($ret['player_id'] ?? $playerId),
            'current_balance' => (string)($ret['current_balance'] ?? '0'),
            'og_balance'      => (string)($ret['current_balance'] ?? '0'),
            'hongbao'         => round((float)($account->hongbao ?? 0), 2),
            'rs_code'         => (string)($ret['rs_code'] ?? ''),
            'rs_message'      => (string)($ret['rs_message'] ?? ''),
        ];
    }

    /**
     * 拉取 OG 转账历史并与本站 fans_og_transfer 同步
     *
     * @param array{fetch_id?:int,limit?:int,transaction_id?:string,sync?:bool} $opts
     * @return array<string,mixed>
     */
    public static function transferHistoryForUser($userId, array $opts = [])
    {
        if (!FansHubOgGateway::isEnabled()) {
            throw new \RuntimeException('OG视讯未开启');
        }
        if (!FansHubOgGateway::credentialsReady()) {
            throw new \RuntimeException('OG商户配置不完整');
        }

        $uid = (int)$userId;
        $playerId = self::playerIdForUser($uid);
        $fetchId = max(1, (int)($opts['fetch_id'] ?? 1));
        $limit = (int)($opts['limit'] ?? 100);
        if ($limit < 1) {
            $limit = 100;
        }
        if ($limit > 8000) {
            $limit = 8000;
        }
        $txid = trim((string)($opts['transaction_id'] ?? ''));
        $doSync = !isset($opts['sync']) || !empty($opts['sync']);

        $query = [
            'fetch_id'  => $fetchId,
            'limit'     => $limit,
            'player_id' => $playerId,
        ];
        if ($txid !== '') {
            $query['transaction_id'] = $txid;
        }

        $ret = FansHubOgGateway::transferHistory($query);
        if (empty($ret['ok'])) {
            $code = (string)($ret['rs_code'] ?? '');
            $msg = (string)($ret['rs_message'] ?? FansHubOgGateway::getLastError());
            throw new \RuntimeException(
                'OG转账历史失败：' . trim(($code !== '' ? $code . ' ' : '') . $msg)
            );
        }

        $records = is_array($ret['records'] ?? null) ? $ret['records'] : [];
        $synced = 0;
        if ($doSync && $records) {
            $synced = self::syncTransferRecords($uid, $playerId, $records);
        }

        // 本站本地流水（同 player），便于前端一次展示
        $local = [];
        try {
            $rows = Db::name('fans_og_transfer')
                ->where('user_id', $uid)
                ->order('id', 'desc')
                ->limit(min(200, max(20, $limit)))
                ->select();
            foreach ((array)$rows as $row) {
                $local[] = [
                    'id'              => (int)($row['id'] ?? 0),
                    'transaction_id'  => (string)($row['transaction_id'] ?? ''),
                    'direction'       => (string)($row['direction'] ?? ''),
                    'amount'          => FansHubOgGateway::formatAmount($row['amount'] ?? 0),
                    'status'          => (string)($row['status'] ?? ''),
                    'rs_code'         => (string)($row['rs_code'] ?? ''),
                    'rs_message'      => (string)($row['rs_message'] ?? ''),
                    'createtime'      => (int)($row['createtime'] ?? 0),
                    'updatetime'      => (int)($row['updatetime'] ?? 0),
                ];
            }
        } catch (\Throwable $e) {
            $local = [];
        }

        return [
            'user_id'       => $uid,
            'player_id'     => $playerId,
            'rs_code'       => (string)($ret['rs_code'] ?? ''),
            'rs_message'    => (string)($ret['rs_message'] ?? ''),
            'last_fetch_id' => (int)($ret['last_fetch_id'] ?? 0),
            'fetch_id'      => $fetchId,
            'limit'         => $limit,
            'synced'        => $synced,
            'records'       => $records,
            'local'         => $local,
        ];
    }

    /**
     * 把 OG records 写入/更新 fans_og_transfer
     *
     * @param array<int,array<string,mixed>> $records
     */
    protected static function syncTransferRecords($userId, $playerId, array $records)
    {
        $n = 0;
        $uid = (int)$userId;
        $now = time();
        foreach ($records as $row) {
            if (!is_array($row)) {
                continue;
            }
            $txid = trim((string)($row['transaction_id'] ?? ''));
            if ($txid === '') {
                continue;
            }
            $type = strtolower(trim((string)($row['transaction_type'] ?? '')));
            $direction = ($type === 'withdraw') ? 'withdraw' : 'deposit';
            $amount = round((float)($row['transfer_amount'] ?? 0), 2);
            $ts = (int)($row['transaction_time'] ?? 0);
            if ($ts <= 0) {
                $ts = $now;
            }
            $fetchId = (int)($row['fetch_id'] ?? 0);
            $data = [
                'user_id'        => $uid,
                'player_id'      => (string)($row['player_id'] ?? $playerId),
                'direction'      => $direction,
                'transaction_id' => $txid,
                'amount'         => $amount,
                'status'         => 'success',
                'rs_code'        => 'S-100',
                'rs_message'     => 'sync fetch_id=' . $fetchId,
                'updatetime'     => $now,
            ];
            try {
                $exist = Db::name('fans_og_transfer')->where('transaction_id', $txid)->find();
                if ($exist) {
                    // 已有本地单：只补状态，不改金额方向（以本地记账为准）
                    $upd = [
                        'status'     => ((string)($exist['status'] ?? '') === 'success')
                            ? 'success'
                            : 'success',
                        'updatetime' => $now,
                    ];
                    if (trim((string)($exist['rs_message'] ?? '')) === '') {
                        $upd['rs_message'] = $data['rs_message'];
                    }
                    Db::name('fans_og_transfer')->where('id', (int)$exist['id'])->update($upd);
                } else {
                    $data['createtime'] = $ts;
                    Db::name('fans_og_transfer')->insert($data);
                }
                $n++;
            } catch (\Throwable $e) {
                // 表未装或唯一冲突时跳过
            }
        }
        return $n;
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
