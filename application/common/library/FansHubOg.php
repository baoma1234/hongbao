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
     * 从 OG player_id 反查本站 user_id
     * 优先 fans_og_player；否则解析约定格式 u + 补零 UID
     */
    public static function userIdFromPlayerId($playerId)
    {
        $pid = trim((string)$playerId);
        if ($pid === '') {
            return 0;
        }
        static $cache = [];
        $key = strtolower($pid);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $uid = 0;
        try {
            $row = Db::name('fans_og_player')->where('player_id', $pid)->find();
            if (!$row) {
                $row = Db::name('fans_og_player')
                    ->whereRaw('LOWER(player_id) = ?', [$key])
                    ->find();
            }
            if ($row) {
                $uid = (int)($row['user_id'] ?? 0);
            }
        } catch (\Throwable $e) {
            $uid = 0;
        }

        if ($uid <= 0 && preg_match('/^u0*([1-9]\d*)$/i', $pid, $m)) {
            $cand = (int)$m[1];
            if ($cand > 0 && strcasecmp(self::playerIdForUser($cand), $pid) === 0) {
                try {
                    $exists = (int)Db::name('user')->where('id', $cand)->value('id');
                    if ($exists > 0) {
                        $uid = $cand;
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }

        // 宽松：u + 数字（无严格补零），且本站确有该用户
        if ($uid <= 0 && preg_match('/^u(\d+)$/i', $pid, $m)) {
            $cand = (int)$m[1];
            if ($cand > 0) {
                try {
                    $exists = (int)Db::name('user')->where('id', $cand)->value('id');
                    if ($exists > 0) {
                        $uid = $cand;
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }

        $cache[$key] = $uid;
        return $uid;
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
     * 顺序：本地已注册 → 远程 get-balance 探测 → 再调 register
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

        // 首次 / 本地无记录：先探测 OG 是否已有该玩家，避免无谓 register
        if (!$force) {
            $probed = self::probeRemoteRegistered($snap['player_id'], $snap['og_nickname'], $userId);
            if ($probed !== null) {
                return $probed;
            }
        }

        $ret = FansHubOgGateway::registerPlayer($snap['player_id'], $snap['og_nickname']);
        self::upsertPlayer($userId, $snap['player_id'], $snap['og_nickname'], $ret);

        $snap = self::playerSnapshot($userId);
        $snap['just_registered'] = !empty($ret['ok']) && (string)($ret['rs_code'] ?? '') === 'S-100';
        $snap['rs_code'] = (string)($ret['rs_code'] ?? '');
        $snap['rs_message'] = (string)($ret['rs_message'] ?? '');
        if (empty($ret['ok'])) {
            $code = $snap['rs_code'];
            $msg = $snap['rs_message'];
            if ($code === 'E-104') {
                throw new \RuntimeException(
                    'OG注册失败：E-104 invalid parameter（请检查运营商名称 operator-name 与密钥；名称勿含非法字符）'
                );
            }
            throw new \RuntimeException(
                'OG注册失败：' . ($code !== '' ? $code . ' ' : '') . $msg
            );
        }
        return $snap;
    }

    /**
     * 用 get-balance 探测玩家是否已在 OG 侧存在
     *
     * @return array<string,mixed>|null 已存在则返回快照；需注册返回 null；配置类错误直接抛
     */
    protected static function probeRemoteRegistered($playerId, $ogNickname, $userId)
    {
        $bal = FansHubOgGateway::getBalance($playerId);
        $code = (string)($bal['rs_code'] ?? '');
        $msg = (string)($bal['rs_message'] ?? '');

        // 已有钱包：记本地 registered，不再调 register
        if (!empty($bal['ok']) && $code === 'S-100') {
            self::upsertPlayer($userId, $playerId, $ogNickname, [
                'ok'         => true,
                'rs_code'    => 'S-121',
                'rs_message' => 'detected via get-balance',
            ]);
            $snap = self::playerSnapshot($userId);
            $snap['just_registered'] = false;
            $snap['rs_code'] = 'S-121';
            $snap['rs_message'] = 'already registered on OG';
            $snap['og_balance'] = (string)($bal['current_balance'] ?? '');
            return $snap;
        }

        // 玩家不存在：走后续 register
        if ($code === 'S-104' || !empty($bal['player_missing'])) {
            return null;
        }

        // 配置 / 运营商问题：提前失败，避免再打一次同样的 E-104 register
        if ($code === 'E-104') {
            throw new \RuntimeException(
                'OG探测失败：E-104 invalid parameter（请检查运营商名称 operator-name，当前配置含非法字符时会失败）'
            );
        }
        if ($code === 'S-109') {
            throw new \RuntimeException('OG运营商不可用（S-109），请核对运营商名称与公匙/私钥');
        }
        // 其它错误：仍尝试 register（兼容网关差异）
        if ($code !== '' && $code !== 'S-115') {
            // 网络/未知：不阻断，继续 register
            if ($msg !== '' && stripos($msg, 'not available') !== false && $code[0] === 'S') {
                return null;
            }
        }
        return null;
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

    /**
     * 定时 / 手工拉取 OG 投注记录并落库
     *
     * @param array{fetch_id?:int,limit?:int,game_type_id?:int,player_id?:string,transaction_id?:string,game_id?:string,round_id?:int,max_pages?:int,advance_cursor?:bool} $opts
     * @return array<string,mixed>
     */
    public static function syncBetHistory(array $opts = [])
    {
        if (!FansHubOgGateway::isEnabled()) {
            throw new \RuntimeException('OG视讯未开启');
        }
        if (!FansHubOgGateway::credentialsReady()) {
            throw new \RuntimeException('OG商户配置不完整');
        }

        $cfg = FansHubOgGateway::config();
        $fans = FansHubService::config();
        if (!is_array($fans)) {
            $fans = [];
        }
        $limit = (int)($opts['limit'] ?? ($fans['og_bet_limit'] ?? 5000));
        if ($limit < 1) {
            $limit = 5000;
        }
        if ($limit > 8000) {
            $limit = 8000;
        }
        $gameTypeId = (int)($opts['game_type_id'] ?? ($fans['og_bet_game_type_id'] ?? 1));
        if ($gameTypeId < 1) {
            $gameTypeId = 1;
        }
        $maxPages = (int)($opts['max_pages'] ?? 5);
        if ($maxPages < 1) {
            $maxPages = 1;
        }
        if ($maxPages > 20) {
            $maxPages = 20;
        }
        $advance = !isset($opts['advance_cursor']) || !empty($opts['advance_cursor']);
        $fixedFetch = array_key_exists('fetch_id', $opts) ? max(1, (int)$opts['fetch_id']) : 0;
        $playerId = trim((string)($opts['player_id'] ?? ''));
        $txid = trim((string)($opts['transaction_id'] ?? ''));
        $gameId = trim((string)($opts['game_id'] ?? ''));
        $roundId = isset($opts['round_id']) ? (int)$opts['round_id'] : 0;

        $pages = 0;
        $fetched = 0;
        $upserted = 0;
        $lastCode = '';
        $lastMsg = '';
        $cursorBefore = self::getSyncValue('bet_fetch_id', '1');
        $cursor = $fixedFetch > 0 ? $fixedFetch : max(1, (int)$cursorBefore);
        $finalCursor = $cursor;

        while ($pages < $maxPages) {
            $pages++;
            $query = [
                'fetch_id'     => $cursor,
                'limit'        => $limit,
                'game_type_id' => $gameTypeId,
            ];
            if ($playerId !== '') {
                $query['player_id'] = $playerId;
            }
            if ($txid !== '') {
                $query['transaction_id'] = $txid;
            }
            if ($gameId !== '') {
                $query['game_id'] = $gameId;
            }
            if ($roundId > 0) {
                $query['round_id'] = $roundId;
            }

            $ret = FansHubOgGateway::betHistory($query);
            $lastCode = (string)($ret['rs_code'] ?? '');
            $lastMsg = (string)($ret['rs_message'] ?? '');
            if (empty($ret['ok'])) {
                throw new \RuntimeException(
                    'OG投注记录失败：' . trim(($lastCode !== '' ? $lastCode . ' ' : '') . ($lastMsg ?: FansHubOgGateway::getLastError()))
                );
            }

            $records = is_array($ret['records'] ?? null) ? $ret['records'] : [];
            $cnt = count($records);
            $fetched += $cnt;
            if ($cnt > 0) {
                $upserted += self::upsertBetRecords($records, $gameTypeId);
            }

            $apiLast = (int)($ret['last_fetch_id'] ?? 0);
            $maxInBatch = 0;
            if ($records) {
                $ids = array_column($records, 'fetch_id');
                $maxInBatch = $ids ? (int)max($ids) : 0;
            }
            $next = max($cursor, $apiLast, $maxInBatch);

            // 无新数据或游标不前进：结束
            if ($cnt === 0 || $lastCode === 'S-115' || $next <= $cursor) {
                if ($next > $finalCursor) {
                    $finalCursor = $next;
                }
                break;
            }
            $finalCursor = $next;
            $cursor = $next;
            // 指定条件查询（单笔/玩家）不翻页刷全站
            if ($txid !== '' || $playerId !== '' || $gameId !== '' || $roundId > 0 || $fixedFetch > 0) {
                break;
            }
            if ($cnt < $limit) {
                break;
            }
        }

        if ($advance && $finalCursor > 0 && $playerId === '' && $txid === '' && $fixedFetch <= 0) {
            self::setSyncValue('bet_fetch_id', (string)$finalCursor);
        }

        $relinked = self::relinkUnmappedBets(5000);
        $ledgered = self::backfillBetsToLedger(2000);

        return [
            'ok'            => true,
            'rs_code'       => $lastCode,
            'rs_message'    => $lastMsg,
            'pages'         => $pages,
            'fetched'       => $fetched,
            'upserted'      => $upserted,
            'relinked'      => $relinked,
            'ledgered'      => $ledgered,
            'fetch_id'      => (int)$cursorBefore,
            'last_fetch_id' => $finalCursor,
            'game_type_id'  => $gameTypeId,
            'limit'         => $limit,
            'currency'      => (string)($cfg['currency'] ?? ''),
        ];
    }

    /**
     * crontab 入口：OG 开启时增量抓投注
     *
     * @return array<string,mixed>
     */
    public static function tickBetHistoryCron()
    {
        if (!FansHubOgGateway::isEnabled()) {
            return ['ok' => true, 'skipped' => true, 'msg' => 'og disabled'];
        }
        if (!FansHubOgGateway::credentialsReady()) {
            return ['ok' => false, 'skipped' => true, 'msg' => 'og credentials incomplete'];
        }
        $fans = FansHubService::config();
        if (is_array($fans) && array_key_exists('og_bet_sync_enabled', $fans) && empty($fans['og_bet_sync_enabled'])) {
            return ['ok' => true, 'skipped' => true, 'msg' => 'bet sync disabled'];
        }
        try {
            $ret = self::syncBetHistory(['max_pages' => 5]);
            $ret['skipped'] = false;
            return $ret;
        } catch (\Throwable $e) {
            return ['ok' => false, 'skipped' => false, 'msg' => $e->getMessage()];
        }
    }

    /**
     * @param array<int,array<string,mixed>> $records
     */
    protected static function upsertBetRecords(array $records, $gameTypeId = 1)
    {
        $n = 0;
        $now = time();
        $gameTypeId = max(1, (int)$gameTypeId);
        $playerIds = [];
        foreach ($records as $row) {
            if (!is_array($row)) {
                continue;
            }
            $pid = trim((string)($row['player_id'] ?? ''));
            if ($pid !== '') {
                $playerIds[$pid] = $pid;
            }
        }
        $uidMap = [];
        foreach ($playerIds as $pid) {
            $uid = self::userIdFromPlayerId($pid);
            if ($uid > 0) {
                $uidMap[strtolower($pid)] = $uid;
                // 补映射，便于后台关联与后续精确匹配
                self::ensurePlayerMap($uid, $pid);
            }
        }

        foreach ($records as $row) {
            if (!is_array($row)) {
                continue;
            }
            $txid = trim((string)($row['transaction_id'] ?? ''));
            if ($txid === '') {
                continue;
            }
            $pid = trim((string)($row['player_id'] ?? ''));
            $uid = (int)($uidMap[strtolower($pid)] ?? 0);
            if ($uid <= 0 && $pid !== '') {
                $uid = self::userIdFromPlayerId($pid);
            }
            $secondary = $row['secondary_info'] ?? [];
            $other = $row['other_info'] ?? [];
            $remark = $row['remark'] ?? '';
            $debitAt = (int)($row['debit_at'] ?? 0);
            $data = [
                'fetch_id'         => (int)($row['fetch_id'] ?? 0),
                'user_id'          => $uid,
                'player_id'        => $pid,
                'transaction_id'   => $txid,
                'game_id'          => (string)($row['game_id'] ?? ''),
                'round_id'         => (int)($row['round_id'] ?? 0),
                'game_type_id'     => $gameTypeId,
                'game_name'        => (string)($row['game_name'] ?? ''),
                'bet_place'        => (string)($row['bet_place'] ?? ''),
                'result_url'       => (string)($row['result_url'] ?? ''),
                'debit_amount'     => round((float)($row['debit_amount'] ?? 0), 2),
                'credit_amount'    => round((float)($row['credit_amount'] ?? 0), 2),
                'winlose_amount'   => round((float)($row['winlose_amount'] ?? 0), 2),
                'effective_amount' => round((float)($row['effective_amount'] ?? 0), 2),
                'currency'         => (string)($row['currency'] ?? ''),
                'transaction_type' => (string)($row['transaction_type'] ?? ''),
                'secondary_info'   => is_array($secondary)
                    ? json_encode($secondary, JSON_UNESCAPED_UNICODE)
                    : (string)$secondary,
                'other_info'       => is_array($other)
                    ? json_encode($other, JSON_UNESCAPED_UNICODE)
                    : (string)$other,
                'remark'           => is_array($remark)
                    ? json_encode($remark, JSON_UNESCAPED_UNICODE)
                    : (string)$remark,
                'debit_at'         => $debitAt,
                'credit_at'        => (int)($row['credit_at'] ?? 0),
                'rollback_at'      => (int)($row['rollback_at'] ?? 0),
                'cancel_at'        => (int)($row['cancel_at'] ?? 0),
                'resettled_at'     => (int)($row['resettled_at'] ?? 0),
                'updatetime'       => $now,
            ];
            try {
                $exist = Db::name('fans_og_bet')->where('transaction_id', $txid)->find();
                $betId = 0;
                if ($exist) {
                    // 已有单：若原先未挂上账号则补 user_id
                    if ((int)($exist['user_id'] ?? 0) > 0 && $uid <= 0) {
                        unset($data['user_id']);
                        $uid = (int)$exist['user_id'];
                    }
                    Db::name('fans_og_bet')->where('id', (int)$exist['id'])->update($data);
                    $betId = (int)$exist['id'];
                } else {
                    // 仅落库本站已对齐用户的注单，避免商户共享号其它玩家污染
                    if ($uid <= 0) {
                        continue;
                    }
                    $data['createtime'] = $debitAt > 0 ? $debitAt : $now;
                    $betId = (int)Db::name('fans_og_bet')->insertGetId($data);
                }
                $n++;
                if ($uid > 0 && $betId > 0) {
                    $rowForLedger = array_merge($data, [
                        'id'      => $betId,
                        'user_id' => $uid,
                    ]);
                    self::syncBetToWalletLedger($rowForLedger);
                }
            } catch (\Throwable $e) {
                // 表未装或唯一冲突时跳过
            }
        }
        return $n;
    }

    /**
     * 已对齐本站用户的 OG 注单 → 写入资金流水（类型 og_live / 文案「真人视讯」）
     * 仅记账展示，不改红宝余额（筹码变动已在 OG 侧完成）。
     */
    public static function syncBetToWalletLedger(array $bet)
    {
        $uid = (int)($bet['user_id'] ?? 0);
        $betId = (int)($bet['id'] ?? 0);
        $txid = trim((string)($bet['transaction_id'] ?? ''));
        if ($uid <= 0 || ($betId <= 0 && $txid === '')) {
            return false;
        }
        $winlose = round((float)($bet['winlose_amount'] ?? 0), 2);
        $debit = round((float)($bet['debit_amount'] ?? 0), 2);
        $credit = round((float)($bet['credit_amount'] ?? 0), 2);
        $gameName = trim((string)($bet['game_name'] ?? ''));
        $betPlace = trim((string)($bet['bet_place'] ?? ''));
        $roundId = (int)($bet['round_id'] ?? 0);
        $debitAt = (int)($bet['debit_at'] ?? 0);
        $parts = ['真人视讯'];
        if ($gameName !== '') {
            $parts[] = $gameName;
        }
        if ($betPlace !== '') {
            $parts[] = $betPlace;
        }
        if ($roundId > 0) {
            $parts[] = '局#' . $roundId;
        }
        if ($debit > 0.00001 || $credit > 0.00001) {
            $parts[] = sprintf('投%.2f/派%.2f', $debit, $credit);
        }
        $remark = mb_substr(implode(' · ', $parts), 0, 250);
        $bizNo = $txid !== '' ? mb_substr($txid, 0, 40) : ('ogbet' . $betId);
        $createtime = $debitAt > 0 ? $debitAt : (int)($bet['createtime'] ?? time());

        try {
            $acc = Db::name('fans_account')->where('user_id', $uid)->field('hongbao,rights,balance')->find();
            $hongbaoAfter = round((float)($acc['hongbao'] ?? 0), 2);
            $rightsAfter = round((float)($acc['rights'] ?? 0), 2);
            $balanceAfter = round((float)($acc['balance'] ?? 0), 2);

            $exist = null;
            if ($betId > 0) {
                $exist = Db::name('fans_ledger')
                    ->where('user_id', $uid)
                    ->where('type', 'og_live')
                    ->where('ref_type', 'og_bet')
                    ->where('ref_id', $betId)
                    ->find();
            }
            if (!$exist && $bizNo !== '') {
                $exist = Db::name('fans_ledger')
                    ->where('user_id', $uid)
                    ->where('type', 'og_live')
                    ->where('biz_no', $bizNo)
                    ->find();
            }

            $payload = [
                'user_id'         => $uid,
                'type'            => 'og_live',
                'rights_change'   => 0,
                'balance_change'  => 0,
                'hongbao_change'  => $winlose,
                'rights_after'    => $rightsAfter,
                'balance_after'   => $balanceAfter,
                'hongbao_after'   => $hongbaoAfter,
                'remark'          => $remark,
                'channel'         => 'og_live',
                'biz_no'          => $bizNo,
                'ref_type'        => 'og_bet',
                'ref_id'          => $betId,
                'admin_id'        => 0,
                'createtime'      => $createtime,
            ];

            if ($exist) {
                unset($payload['createtime']);
                Db::name('fans_ledger')->where('id', (int)$exist['id'])->update($payload);
            } else {
                Db::name('fans_ledger')->insert($payload);
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 回填：已映射 user_id 的 OG 注单写入会员资金流水
     */
    public static function backfillBetsToLedger($limit = 2000)
    {
        $limit = max(1, min(20000, (int)$limit));
        $n = 0;
        try {
            $rows = Db::name('fans_og_bet')
                ->where('user_id', '>', 0)
                ->order('id', 'asc')
                ->limit($limit)
                ->select();
        } catch (\Throwable $e) {
            return 0;
        }
        foreach ((array)$rows as $row) {
            if (!is_array($row)) {
                $row = (array)$row;
            }
            if (self::syncBetToWalletLedger($row)) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * 把 user_id=0 的历史注单按 player_id 挂回本站账号
     */
    public static function relinkUnmappedBets($limit = 5000)
    {
        $limit = max(1, min(20000, (int)$limit));
        $n = 0;
        try {
            $rows = Db::name('fans_og_bet')
                ->where('user_id', 0)
                ->where('player_id', '<>', '')
                ->order('id', 'asc')
                ->limit($limit)
                ->select();
        } catch (\Throwable $e) {
            return 0;
        }
        $now = time();
        foreach ((array)$rows as $row) {
            $id = (int)($row['id'] ?? 0);
            $pid = trim((string)($row['player_id'] ?? ''));
            if ($id <= 0 || $pid === '') {
                continue;
            }
            $uid = self::userIdFromPlayerId($pid);
            if ($uid <= 0) {
                continue;
            }
            try {
                Db::name('fans_og_bet')->where('id', $id)->update([
                    'user_id'    => $uid,
                    'updatetime' => $now,
                ]);
                self::ensurePlayerMap($uid, $pid);
                $fresh = Db::name('fans_og_bet')->where('id', $id)->find();
                if ($fresh) {
                    self::syncBetToWalletLedger(is_array($fresh) ? $fresh : (array)$fresh);
                }
                $n++;
            } catch (\Throwable $e) {
                // ignore
            }
        }
        return $n;
    }

    /**
     * 确保 fans_og_player 有映射（投注回填时可补）
     */
    protected static function ensurePlayerMap($userId, $playerId)
    {
        $uid = (int)$userId;
        $pid = trim((string)$playerId);
        if ($uid <= 0 || $pid === '') {
            return;
        }
        $now = time();
        try {
            $byUser = Db::name('fans_og_player')->where('user_id', $uid)->find();
            if ($byUser) {
                $upd = ['updatetime' => $now];
                if (trim((string)($byUser['player_id'] ?? '')) === '') {
                    $upd['player_id'] = $pid;
                }
                if ((string)($byUser['status'] ?? '') === '') {
                    $upd['status'] = 'registered';
                }
                Db::name('fans_og_player')->where('user_id', $uid)->update($upd);
                return;
            }
            $byPid = Db::name('fans_og_player')->where('player_id', $pid)->find();
            if ($byPid) {
                if ((int)($byPid['user_id'] ?? 0) <= 0) {
                    Db::name('fans_og_player')->where('id', (int)$byPid['id'])->update([
                        'user_id'    => $uid,
                        'updatetime' => $now,
                    ]);
                }
                return;
            }
            Db::name('fans_og_player')->insert([
                'user_id'        => $uid,
                'player_id'      => $pid,
                'og_nickname'    => self::nicknameForUser($uid),
                'status'         => 'registered',
                'last_rs_code'   => 'S-100',
                'last_rs_message'=> 'bet-sync map',
                'registered_at'  => $now,
                'createtime'     => $now,
                'updatetime'     => $now,
            ]);
        } catch (\Throwable $e) {
            // ignore unique conflicts
        }
    }

    protected static function getSyncValue($name, $default = '')
    {
        try {
            $v = Db::name('fans_og_sync')->where('name', (string)$name)->value('value');
            if ($v === null || $v === false) {
                return (string)$default;
            }
            return (string)$v;
        } catch (\Throwable $e) {
            return (string)$default;
        }
    }

    protected static function setSyncValue($name, $value)
    {
        $now = time();
        $name = (string)$name;
        $value = (string)$value;
        try {
            $exist = Db::name('fans_og_sync')->where('name', $name)->find();
            if ($exist) {
                Db::name('fans_og_sync')->where('name', $name)->update([
                    'value'      => $value,
                    'updatetime' => $now,
                ]);
            } else {
                Db::name('fans_og_sync')->insert([
                    'name'       => $name,
                    'value'      => $value,
                    'updatetime' => $now,
                ]);
            }
        } catch (\Throwable $e) {
            // ignore
        }
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
