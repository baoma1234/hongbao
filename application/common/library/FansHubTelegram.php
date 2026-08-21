<?php

namespace app\common\library;

use app\common\library\Auth;
use app\common\model\User;
use think\Db;
use think\Exception;
use think\Log;

/**
 * Telegram Bot + WebApp：绑定手机 / 菜单 / 校验 initData
 */
class FansHubTelegram
{
    public static function enabled()
    {
        return !empty(FansHubService::config('telegram_bot_enabled'))
            && trim((string)FansHubService::config('telegram_bot_token', '')) !== '';
    }

    public static function botToken()
    {
        return trim((string)FansHubService::config('telegram_bot_token', ''));
    }

    public static function webhookSecret()
    {
        return trim((string)FansHubService::config('telegram_webhook_secret', ''));
    }

    public static function webAppUrl($startParam = '')
    {
        $cfg = FansHubService::config();
        $full = trim((string)($cfg['telegram_webapp_url'] ?? ''));
        if ($full !== '' && preg_match('#^https?://#i', $full)) {
            $url = $full;
        } else {
            $base = rtrim((string)($cfg['invite_base_url'] ?: ''), '/');
            if ($base === '') {
                try {
                    $base = rtrim((string)request()->domain(), '/');
                } catch (\Throwable $e) {
                    $base = '';
                }
            }
            $path = trim((string)($cfg['telegram_webapp_path'] ?? '999/#/pages/login/tg-bind'));
            $path = ltrim($path, '/');
            if ($path !== '' && preg_match('#^https?://#i', $path)) {
                $url = $path;
            } else {
                $url = $base !== '' ? ($base . '/' . $path) : ('/' . $path);
            }
        }
        $startParam = trim((string)$startParam);
        if ($startParam !== '') {
            $sep = strpos($url, '?') === false ? '?' : '&';
            if (strpos($url, '#') !== false) {
                $parts = explode('#', $url, 2);
                $url = $parts[0] . $sep . 'tg_start=' . rawurlencode($startParam)
                    . '#' . $parts[1];
            } else {
                $url .= $sep . 'code=' . rawurlencode($startParam);
            }
        }
        return $url;
    }

    public static function csText()
    {
        $t = (string)FansHubService::config('telegram_cs_text', '');
        if ($t === '') {
            $t = "🙋 如有疑问，请联系 24 小时官方客服通道：\n👉 @BIO_kf";
        }
        return $t;
    }

    /**
     * 校验 Telegram WebApp initData，成功返回解析数组（含 user）
     *
     * @param string $initData
     * @return array
     * @throws Exception
     */
    public static function validateInitData($initData)
    {
        $initData = trim((string)$initData);
        if ($initData === '') {
            throw new Exception('缺少 Telegram 登录数据');
        }
        $token = self::botToken();
        if ($token === '') {
            throw new Exception('Telegram 机器人未配置');
        }
        $params = [];
        parse_str($initData, $params);
        if (empty($params['hash'])) {
            throw new Exception('Telegram 数据无效');
        }
        $hash = (string)$params['hash'];
        unset($params['hash']);
        ksort($params);
        $lines = [];
        foreach ($params as $k => $v) {
            $lines[] = $k . '=' . $v;
        }
        $dataCheckString = implode("\n", $lines);
        $secretKey = hash_hmac('sha256', $token, 'WebAppData', true);
        $calc = bin2hex(hash_hmac('sha256', $dataCheckString, $secretKey, true));
        if (!hash_equals($calc, $hash)) {
            throw new Exception('Telegram 校验失败');
        }
        $authDate = (int)($params['auth_date'] ?? 0);
        $maxAge = max(300, (int)FansHubService::config('telegram_init_max_age', 86400));
        if ($authDate <= 0 || abs(time() - $authDate) > $maxAge) {
            throw new Exception('Telegram 登录已过期，请重新打开');
        }
        $user = [];
        if (!empty($params['user'])) {
            $decoded = json_decode($params['user'], true);
            if (is_array($decoded)) {
                $user = $decoded;
            }
        }
        if (empty($user['id'])) {
            throw new Exception('无法识别 Telegram 用户');
        }
        return [
            'tg_user_id'    => (int)$user['id'],
            'tg_username'   => (string)($user['username'] ?? ''),
            'tg_first_name' => (string)($user['first_name'] ?? ''),
            'tg_last_name'  => (string)($user['last_name'] ?? ''),
            'start_param'   => (string)($params['start_param'] ?? ''),
            'auth_date'     => $authDate,
            'raw_user'      => $user,
        ];
    }

    public static function findBindByTg($tgUserId)
    {
        $tgUserId = (int)$tgUserId;
        if ($tgUserId <= 0) {
            return null;
        }
        return Db::name('fans_telegram_bind')->where('tg_user_id', $tgUserId)->find();
    }

    public static function findBindByUserId($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return null;
        }
        return Db::name('fans_telegram_bind')->where('user_id', $userId)->find();
    }

    public static function upsertBind($tgUserId, $userId, array $meta = [])
    {
        $tgUserId = (int)$tgUserId;
        $userId = (int)$userId;
        if ($tgUserId <= 0 || $userId <= 0) {
            throw new Exception('绑定参数错误');
        }
        $now = time();
        $rowTg = self::findBindByTg($tgUserId);
        $rowUser = self::findBindByUserId($userId);
        if ($rowTg && (int)$rowTg['user_id'] !== $userId) {
            throw new Exception('该 Telegram 已绑定其他账号');
        }
        if ($rowUser && (int)$rowUser['tg_user_id'] !== $tgUserId) {
            throw new Exception('该手机号已绑定其他 Telegram');
        }
        $data = [
            'tg_username'   => mb_substr((string)($meta['tg_username'] ?? ''), 0, 64),
            'tg_first_name' => mb_substr((string)($meta['tg_first_name'] ?? ''), 0, 128),
            'tg_last_name'  => mb_substr((string)($meta['tg_last_name'] ?? ''), 0, 128),
            'updatetime'    => $now,
        ];
        if ($rowTg) {
            Db::name('fans_telegram_bind')->where('id', (int)$rowTg['id'])->update($data);
            return (int)$rowTg['id'];
        }
        $data['tg_user_id'] = $tgUserId;
        $data['user_id'] = $userId;
        $data['createtime'] = $now;
        return (int)Db::name('fans_telegram_bind')->insertGetId($data);
    }

    /**
     * 已绑定：直接签发 token
     */
    public static function issueTokenForUser($userId)
    {
        $userId = (int)$userId;
        $user = User::get($userId);
        if (!$user) {
            throw new Exception(FansHubService::h5CopyText('srv_user_not_found'));
        }
        if ((string)$user->status !== 'normal') {
            throw new Exception(FansHubService::h5CopyText('srv_account_frozen'));
        }
        $auth = Auth::instance();
        if (!$auth->direct($userId)) {
            $err = trim((string)$auth->getError());
            throw new Exception($err !== '' ? $err : FansHubService::h5CopyText('srv_login_fail'));
        }
        try {
            FansHubService::seedImAdminConversations($userId);
            FansHubDefaultCs::ensureFriendForUser($userId);
        } catch (\Throwable $e) {
        }
        $profile = FansHubService::profilePayload($userId);
        $profile['invite_rank'] = FansHubService::inviteRankForUser($userId);
        return [
            'token'    => $auth->getToken(),
            'userinfo' => $auth->getUserinfo(),
            'profile'  => $profile,
            'is_new'   => false,
            'bound'    => true,
        ];
    }

    /**
     * WebApp：校验 initData → 已绑定则登录，否则提示绑手机
     */
    public static function authByInitData($initData)
    {
        $tg = self::validateInitData($initData);
        $bind = self::findBindByTg($tg['tg_user_id']);
        if ($bind) {
            $data = self::issueTokenForUser((int)$bind['user_id']);
            $data['tg'] = [
                'tg_user_id'  => $tg['tg_user_id'],
                'username'    => $tg['tg_username'],
                'first_name'  => $tg['tg_first_name'],
                'start_param' => $tg['start_param'],
            ];
            return $data;
        }
        return [
            'bound'    => false,
            'need_bind'=> true,
            'token'    => '',
            'tg'       => [
                'tg_user_id'  => $tg['tg_user_id'],
                'username'    => $tg['tg_username'],
                'first_name'  => $tg['tg_first_name'],
                'start_param' => $tg['start_param'],
            ],
        ];
    }

    /**
     * WebApp：手机号+验证码登录/注册并绑定 TG
     */
    public static function bindByPhone($initData, $mobile, $captcha, $inviteCode = '', $deviceFp = '')
    {
        $tg = self::validateInitData($initData);
        $inviteCode = trim((string)$inviteCode);
        if ($inviteCode === '' && $tg['start_param'] !== '') {
            $inviteCode = $tg['start_param'];
        }
        // 若已绑定直接登录
        $exist = self::findBindByTg($tg['tg_user_id']);
        if ($exist) {
            return self::issueTokenForUser((int)$exist['user_id']);
        }
        $login = FansHubService::loginOrRegister($mobile, $captcha, $inviteCode, $deviceFp);
        $userId = 0;
        if (!empty($login['userinfo']['id'])) {
            $userId = (int)$login['userinfo']['id'];
        } elseif (!empty($login['profile']['user_id'])) {
            $userId = (int)$login['profile']['user_id'];
        }
        if ($userId <= 0) {
            throw new Exception('登录成功但无法识别用户');
        }
        self::upsertBind($tg['tg_user_id'], $userId, $tg);
        $login['bound'] = true;
        $login['need_bind'] = false;
        $login['tg'] = [
            'tg_user_id' => $tg['tg_user_id'],
            'username'   => $tg['tg_username'],
            'first_name' => $tg['tg_first_name'],
        ];
        return $login;
    }

    public static function mainMenuKeyboard()
    {
        $webUrl = self::webAppUrl();
        return [
            'keyboard' => [
                [
                    ['text' => '🎮 进入游戏', 'web_app' => ['url' => $webUrl]],
                ],
                [
                    ['text' => '👤 账号信息'],
                    ['text' => '🎁 邀请好友'],
                ],
                [
                    ['text' => '🙋 官方客服'],
                    ['text' => '🏠 返回主菜单'],
                ],
            ],
            'resize_keyboard'   => true,
            'one_time_keyboard' => false,
        ];
    }

    public static function welcomeText()
    {
        $name = (string)FansHubService::config('telegram_bot_username', '');
        $line = $name !== '' ? ('@' . ltrim($name, '@')) : '红宝 Telegram';
        return "欢迎使用 {$line} 🤖\n\n"
            . "点击下方「🎮 进入游戏」在 Telegram 内打开网页。\n"
            . "首次需绑定手机号（验证码登录），已有账号自动绑定，新号自动注册。\n\n"
            . "菜单说明：\n"
            . "👤 账号信息 — 查看红宝 / 股份\n"
            . "🎁 邀请好友 — 获取邀请链接\n"
            . "🙋 官方客服 — 联系客服\n"
            . "🏠 返回主菜单 — 刷新本菜单";
    }

    public static function handleUpdate(array $update)
    {
        if (!empty($update['callback_query'])) {
            return self::handleCallback($update['callback_query']);
        }
        if (!empty($update['message'])) {
            return self::handleMessage($update['message']);
        }
        return ['ok' => true, 'ignored' => true];
    }

    protected static function handleCallback(array $cq)
    {
        $id = $cq['id'] ?? '';
        $data = (string)($cq['data'] ?? '');
        $msg = $cq['message'] ?? [];
        $chatId = $msg['chat']['id'] ?? ($cq['from']['id'] ?? 0);
        $from = $cq['from'] ?? [];
        if ($id !== '') {
            self::api('answerCallbackQuery', ['callback_query_id' => $id]);
        }
        if ($data === 'menu:home' || $data === 'menu:main') {
            return self::sendMainMenu($chatId);
        }
        if ($data === 'menu:account') {
            return self::replyAccount($chatId, $from);
        }
        if ($data === 'menu:invite') {
            return self::replyInvite($chatId, $from);
        }
        if ($data === 'menu:cs') {
            return self::api('sendMessage', [
                'chat_id' => $chatId,
                'text'    => self::csText(),
            ]);
        }
        return ['ok' => true];
    }

    protected static function handleMessage(array $message)
    {
        if (!empty($message['from']['is_bot'])) {
            return ['ok' => true, 'ignored' => 'bot'];
        }
        $chat = $message['chat'] ?? [];
        if (($chat['type'] ?? '') !== 'private') {
            return ['ok' => true, 'ignored' => 'not_private'];
        }
        $chatId = $chat['id'] ?? 0;
        $from = $message['from'] ?? [];
        $text = trim((string)($message['text'] ?? ''));
        // /start CODE
        if (preg_match('/^\/start(?:@\w+)?(?:\s+(.+))?$/u', $text, $m)) {
            $start = trim((string)($m[1] ?? ''));
            // 若带邀请码，WebApp URL 可带上（键盘仍用默认；start_param 由 deep-link 注入）
            if ($start !== '') {
                // 存短缓存供 WebApp 无 start_param 时兜底（可选）
                try {
                    \think\Cache::set('tg_start_' . (int)($from['id'] ?? 0), $start, 3600);
                } catch (\Throwable $e) {
                }
            }
            return self::sendMainMenu($chatId);
        }
        if ($text === '🏠 返回主菜单' || $text === '返回主菜单' || $text === '/menu') {
            return self::sendMainMenu($chatId);
        }
        if ($text === '👤 账号信息' || $text === '账号信息') {
            return self::replyAccount($chatId, $from);
        }
        if ($text === '🎁 邀请好友' || $text === '邀请好友') {
            return self::replyInvite($chatId, $from);
        }
        if ($text === '🙋 官方客服' || $text === '官方客服') {
            return self::api('sendMessage', [
                'chat_id' => $chatId,
                'text'    => self::csText(),
            ]);
        }
        // 其它文本：提示菜单
        return self::sendMainMenu($chatId, "请使用下方菜单按钮操作～\n\n" . self::welcomeText());
    }

    public static function sendMainMenu($chatId, $text = null)
    {
        return self::api('sendMessage', [
            'chat_id'      => $chatId,
            'text'         => $text !== null ? $text : self::welcomeText(),
            'reply_markup' => json_encode(self::mainMenuKeyboard(), JSON_UNESCAPED_UNICODE),
        ]);
    }

    protected static function needBindHint($chatId)
    {
        $webUrl = self::webAppUrl();
        return self::api('sendMessage', [
            'chat_id'      => $chatId,
            'text'         => "⚠️ 尚未绑定账号。\n请先点击「🎮 进入游戏」完成手机号验证绑定。",
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => '🎮 进入游戏', 'web_app' => ['url' => $webUrl]]],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    protected static function replyAccount($chatId, array $from)
    {
        $tgId = (int)($from['id'] ?? 0);
        $bind = self::findBindByTg($tgId);
        if (!$bind) {
            return self::needBindHint($chatId);
        }
        $profile = FansHubService::profilePayload((int)$bind['user_id']);
        $hongbao = number_format((float)($profile['hongbao'] ?? 0), 2, '.', '');
        $rights = number_format((float)($profile['rights'] ?? 0), 2, '.', '');
        $uid = (int)($profile['user_id'] ?? 0);
        $mask = (string)($profile['mobile_mask'] ?? '');
        $text = "👤 账号信息\n\n"
            . "UID：{$uid}\n"
            . "手机：{$mask}\n"
            . "红宝余额：{$hongbao}\n"
            . "股份：{$rights}";
        return self::api('sendMessage', [
            'chat_id' => $chatId,
            'text'    => $text,
        ]);
    }

    protected static function replyInvite($chatId, array $from)
    {
        $tgId = (int)($from['id'] ?? 0);
        $bind = self::findBindByTg($tgId);
        if (!$bind) {
            return self::needBindHint($chatId);
        }
        $share = FansHubService::buildSharePayload((int)$bind['user_id']);
        $link = (string)($share['share_link'] ?? '');
        $text = (string)($share['share_text'] ?? '');
        if ($link === '') {
            $text = '暂无法生成邀请链接，请稍后重试。';
        } elseif ($text === '') {
            $text = "🎁 你的邀请链接：\n" . $link;
        }
        // Bot deep-link 便于在 TG 内拉新
        $botUser = ltrim((string)FansHubService::config('telegram_bot_username', ''), '@');
        $code = (string)(($share['profile']['invite_code'] ?? '') ?: '');
        if ($botUser !== '' && $code !== '') {
            $text .= "\n\n🤖 Telegram 邀请：\nhttps://t.me/{$botUser}?start=" . rawurlencode($code);
        }
        return self::api('sendMessage', [
            'chat_id' => $chatId,
            'text'    => $text,
        ]);
    }

    public static function api($method, array $data = [])
    {
        $token = self::botToken();
        if ($token === '') {
            return ['ok' => false, 'description' => 'bot token empty'];
        }
        $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false) {
            Log::write('TG bot api error: ' . $err, 'error');
            return ['ok' => false, 'description' => $err];
        }
        $json = json_decode($res, true);
        return is_array($json) ? $json : ['ok' => false, 'raw' => $res];
    }
}
