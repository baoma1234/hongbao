<?php

namespace app\common\library;

use app\common\library\Auth;
use app\common\model\User;
use think\Db;
use think\Exception;
use think\Log;

/**
 * Telegram Bot + WebApp：绑定手机 / 菜单 / 多语言 / 校验 initData
 */
class FansHubTelegram
{
    /** @var array action => copy key */
    protected static $kbActions = [
        'enter'   => 'tg_kb_enter',
        'account' => 'tg_kb_account',
        'invite'  => 'tg_kb_invite',
        'cs'      => 'tg_kb_cs',
        'home'    => 'tg_kb_home',
        'lang'    => 'tg_kb_lang',
    ];

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

    /**
     * WebApp 入口；带 locale 与红宝 H5 对齐
     */
    public static function webAppUrl($startParam = '', $locale = '')
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
            $path = trim((string)($cfg['telegram_webapp_path'] ?? '999/?tg_bind=1'));
            $path = ltrim($path, '/');
            if ($path !== '' && preg_match('#^https?://#i', $path)) {
                $url = $path;
            } else {
                $url = $base !== '' ? ($base . '/' . $path) : ('/' . $path);
            }
        }
        // 键盘 web_app：禁止 hash 路由（#/pages/...），否则 Telegram 把 initData 放 query，官方 SDK 读不到
        if (strpos($url, '#') !== false) {
            $url = strstr($url, '#', true);
        }
        $url = rtrim($url, '?&');
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            $url = 'https://hbsq.bio/999/?tg_bind=1';
        }
        if (stripos($url, 'tg_bind=') === false) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . 'tg_bind=1';
        }
        $locale = self::normalizeLocale($locale);
        if ($locale !== '' && stripos($url, 'locale=') === false) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . 'locale=' . rawurlencode($locale);
        }
        $startParam = trim((string)$startParam);
        if ($startParam !== '') {
            $sep = strpos($url, '?') === false ? '?' : '&';
            $url .= $sep . 'code=' . rawurlencode($startParam);
        }
        // 避免 Telegram 客户端长期缓存旧 web_app 地址（曾带 #/ 路由的版本）
        if (stripos($url, 'tg_v=') === false) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . 'tg_v=3';
        }
        return $url;
    }

    public static function normalizeLocale($locale)
    {
        $locale = trim((string)$locale);
        $codes = FansHubService::i18nLocaleCodes();
        if ($locale !== '' && isset($codes[$locale])) {
            return $locale;
        }
        if ($locale !== '') {
            $short = strtolower(substr($locale, 0, 2));
            $map = [
                'zh' => 'zh-CN',
                'en' => 'en-PH',
                'id' => 'id-ID',
                'vi' => 'vi-VN',
                'ms' => 'ms-MY',
                'km' => 'km-KH',
                'ar' => 'ar-AE',
                'tr' => 'tr-TR',
                'ru' => 'ru-RU',
                'ja' => 'ja-JP',
                'ko' => 'ko-KR',
            ];
            if (isset($map[$short]) && isset($codes[$map[$short]])) {
                return $map[$short];
            }
        }
        $def = (string)FansHubService::config('default_locale', 'zh-CN');
        return isset($codes[$def]) ? $def : 'zh-CN';
    }

    public static function getLocale($tgUserId, array $from = [])
    {
        $tgUserId = (int)$tgUserId;
        if ($tgUserId > 0) {
            try {
                $row = Db::name('fans_telegram_pref')->where('tg_user_id', $tgUserId)->find();
                if ($row && !empty($row['locale'])) {
                    return self::normalizeLocale($row['locale']);
                }
            } catch (\Throwable $e) {
            }
        }
        if (!empty($from['language_code'])) {
            return self::normalizeLocale($from['language_code']);
        }
        return self::normalizeLocale('');
    }

    public static function setLocale($tgUserId, $locale)
    {
        $tgUserId = (int)$tgUserId;
        $locale = self::normalizeLocale($locale);
        if ($tgUserId <= 0) {
            return $locale;
        }
        $now = time();
        try {
            $exist = Db::name('fans_telegram_pref')->where('tg_user_id', $tgUserId)->find();
            if ($exist) {
                Db::name('fans_telegram_pref')->where('tg_user_id', $tgUserId)->update([
                    'locale'     => $locale,
                    'updatetime' => $now,
                ]);
            } else {
                Db::name('fans_telegram_pref')->insert([
                    'tg_user_id' => $tgUserId,
                    'locale'     => $locale,
                    'updatetime' => $now,
                ]);
            }
        } catch (\Throwable $e) {
            Log::write('TG setLocale fail: ' . $e->getMessage(), 'error');
        }
        return $locale;
    }

    /** 用户在私聊机器人发过消息（/start 等），WebApp 绑手机时可作兜底 */
    public static function touchBotUser($tgUserId)
    {
        $tgUserId = (int)$tgUserId;
        if ($tgUserId <= 0) {
            return;
        }
        try {
            \think\Cache::set('tg_touch_' . $tgUserId, time(), 3600);
        } catch (\Throwable $e) {
        }
    }

    public static function recentBotTouch($tgUserId, $maxAge = 3600)
    {
        $tgUserId = (int)$tgUserId;
        if ($tgUserId <= 0) {
            return false;
        }
        try {
            $ts = (int)\think\Cache::get('tg_touch_' . $tgUserId);
            return $ts > 0 && (time() - $ts) <= max(60, (int)$maxAge);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function t($key, $locale, array $vars = [])
    {
        $locale = self::normalizeLocale($locale);
        $copy = FansHubService::getH5CopyForLocale($locale);
        $tpl = (string)($copy[$key] ?? '');
        if ($tpl === '' && $locale !== 'zh-CN') {
            $zh = FansHubService::getH5CopyForLocale('zh-CN');
            $tpl = (string)($zh[$key] ?? '');
        }
        foreach ($vars as $name => $value) {
            $tpl = str_replace('{' . $name . '}', (string)$value, $tpl);
        }
        return FansHubService::utf8Safe($tpl);
    }

    public static function kbLabel($action, $locale)
    {
        $key = self::$kbActions[$action] ?? '';
        return $key !== '' ? self::t($key, $locale) : '';
    }

    /**
     * 匹配任意语言键盘文案 → action（切换语言后旧文案仍可点）
     */
    public static function matchKbAction($text)
    {
        $text = trim((string)$text);
        if ($text === '' || $text === '/menu') {
            return $text === '/menu' ? 'home' : '';
        }
        static $map = null;
        if ($map === null) {
            $map = [];
            $codes = array_keys(FansHubService::i18nLocaleCodes());
            foreach ($codes as $code) {
                foreach (self::$kbActions as $action => $key) {
                    $label = trim(self::t($key, $code));
                    if ($label !== '') {
                        $map[$label] = $action;
                    }
                }
            }
            // 兼容旧硬编码中文
            $legacy = [
                '🎮 进入游戏' => 'enter',
                '👤 账号信息' => 'account',
                '账号信息' => 'account',
                '🎁 邀请好友' => 'invite',
                '邀请好友' => 'invite',
                '🙋 官方客服' => 'cs',
                '官方客服' => 'cs',
                '🏠 返回主菜单' => 'home',
                '返回主菜单' => 'home',
            ];
            foreach ($legacy as $k => $a) {
                $map[$k] = $a;
            }
        }
        return $map[$text] ?? '';
    }

    public static function csText($locale = 'zh-CN')
    {
        $locale = self::normalizeLocale($locale);
        // 中文可被后台 Telegram 配置覆盖；其它语言走多语言包
        if ($locale === 'zh-CN') {
            $cfg = trim((string)FansHubService::config('telegram_cs_text', ''));
            if ($cfg !== '') {
                return $cfg;
            }
        }
        $t = self::t('tg_cs_text', $locale);
        return $t !== '' ? $t : "🙋 Support: @BIO_kf";
    }

    /**
     * 归一化 WebApp initData：部分客户端 / 前端会把整段再 URL 编码一次，
     * 形如 user%3D...%26hash%3D...，parse_str 会拿不到 hash →「数据无效」。
     */
    public static function normalizeInitData($initData)
    {
        $initData = trim((string)$initData);
        if ($initData === '') {
            return '';
        }
        // Api 控制器默认 htmlspecialchars 会把 & 变成 &amp;，导致 parse_str 丢 hash
        if (strpos($initData, '&amp;') !== false) {
            $initData = htmlspecialchars_decode($initData, ENT_QUOTES | ENT_HTML5);
        }
        // 已是标准 query（含字面量 hash=）
        if (preg_match('/(?:^|&)hash=/', $initData)) {
            return $initData;
        }
        // 仍是整段 percent-encoding 时反复解码，直到出现 hash= 或无法再解
        for ($i = 0; $i < 3; $i++) {
            if (strpos($initData, '%') === false) {
                break;
            }
            $decoded = rawurldecode($initData);
            if ($decoded === $initData) {
                break;
            }
            $initData = $decoded;
            if (strpos($initData, '&amp;') !== false) {
                $initData = htmlspecialchars_decode($initData, ENT_QUOTES | ENT_HTML5);
            }
            if (preg_match('/(?:^|&)hash=/', $initData)) {
                break;
            }
        }
        return $initData;
    }

    /**
     * 从请求读取 initData（跳过 htmlspecialchars，避免 & → &amp;）
     *
     * @param \think\Request|null $request
     * @return string
     */
    public static function readInitDataFromRequest($request = null)
    {
        try {
            $request = $request ?: request();
        } catch (\Throwable $e) {
            return '';
        }
        $initData = '';
        // 优先 base64，避免 init_data 字符串被过滤器/表单拆坏
        $b64 = (string)$request->post('init_data_b64', '', null);
        if ($b64 === '') {
            $b64 = (string)$request->post('initDataB64', '', null);
        }
        if ($b64 !== '') {
            $decoded = base64_decode(strtr($b64, '-_', '+/'), true);
            if (is_string($decoded) && $decoded !== '') {
                $initData = self::normalizeInitData($decoded);
                if ($initData !== '' && preg_match('/(?:^|&)hash=/', $initData)) {
                    return $initData;
                }
            }
        }
        // 第三参 null = 不走 Api 全局 trim/strip_tags/htmlspecialchars
        $initData = (string)$request->post('init_data', '', null);
        if ($initData === '') {
            $initData = (string)$request->post('initData', '', null);
        }
        $initData = self::normalizeInitData($initData);
        if ($initData !== '' && preg_match('/(?:^|&)hash=/', $initData)) {
            return $initData;
        }
        // 兜底：直接读原始 JSON body（避免 $_POST 表单拆分 / 过滤器）
        $raw = (string)$request->getInput();
        if ($raw !== '' && ($raw[0] === '{' || $raw[0] === '%')) {
            $j = json_decode($raw, true);
            if (!is_array($j) && strpos($raw, '%') !== false) {
                $j = json_decode(rawurldecode($raw), true);
            }
            if (is_array($j)) {
                if (!empty($j['init_data_b64']) && is_string($j['init_data_b64'])) {
                    $d = base64_decode(strtr($j['init_data_b64'], '-_', '+/'), true);
                    if (is_string($d) && $d !== '') {
                        $initData = self::normalizeInitData($d);
                    }
                } elseif (!empty($j['init_data']) && is_string($j['init_data'])) {
                    $initData = self::normalizeInitData($j['init_data']);
                } elseif (!empty($j['initData']) && is_string($j['initData'])) {
                    $initData = self::normalizeInitData($j['initData']);
                }
            }
        }
        return $initData;
    }

    /**
     * 从 initDataUnsafe 拆开的字段校验（hash / auth_date / user）
     *
     * @param \think\Request|null $request
     * @return array
     * @throws Exception
     */
    public static function validateFromUnsafeParts($request = null)
    {
        try {
            $request = $request ?: request();
        } catch (\Throwable $e) {
            throw new Exception('Telegram 数据无效');
        }
        $hash = trim((string)$request->post('tg_hash', '', null));
        $authDate = (int)$request->post('tg_auth_date', 0, null);
        $userJson = trim((string)$request->post('tg_user_json', '', null));
        $tgUserId = (int)$request->post('tg_user_id', 0, null);
        if ($authDate <= 0 || $tgUserId <= 0) {
            throw new Exception('Telegram 数据无效');
        }
        if ($userJson === '') {
            $userJson = json_encode(['id' => $tgUserId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $decodedUser = json_decode($userJson, true);
        if (!is_array($decodedUser) || empty($decodedUser['id'])) {
            $decodedUser = ['id' => $tgUserId];
            $userJson = json_encode($decodedUser, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($hash === '') {
            if (!self::recentBotTouch($tgUserId)) {
                throw new Exception('Telegram 数据无效');
            }
            $maxAge = max(300, (int)FansHubService::config('telegram_init_max_age', 86400));
            if (abs(time() - $authDate) > $maxAge) {
                throw new Exception('Telegram 登录已过期，请重新打开');
            }
            Log::write('[tg] bind fallback bot_touch(no hash) tg=' . $tgUserId, 'info');
            return [
                'tg_user_id'    => $tgUserId,
                'tg_username'   => (string)($decodedUser['username'] ?? ''),
                'tg_first_name' => (string)($decodedUser['first_name'] ?? ''),
                'tg_last_name'  => (string)($decodedUser['last_name'] ?? ''),
                'start_param'   => trim((string)$request->post('tg_start_param', '', null)),
                'auth_date'     => $authDate,
                'raw_user'      => $decodedUser,
            ];
        }
        $params = [
            'auth_date' => (string)$authDate,
            'user'      => $userJson,
            'hash'      => $hash,
        ];
        foreach (['query_id', 'start_param', 'chat_instance', 'chat_type'] as $k) {
            $v = trim((string)$request->post('tg_' . $k, '', null));
            if ($v !== '') {
                $params[$k] = $v;
            }
        }
        $userCandidates = [$userJson];
        if (is_array($decodedUser)) {
            $userCandidates[] = json_encode($decodedUser, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $userCandidates = array_values(array_unique(array_filter($userCandidates)));
        $lastErr = null;
        foreach ($userCandidates as $uj) {
            $try = $params;
            $try['user'] = $uj;
            try {
                self::verifyInitDataParams($try);
                return self::paramsToTgResult($try);
            } catch (\Exception $e) {
                $lastErr = $e;
            }
        }
        // 兜底：用户刚从机器人点「进入游戏」，hash 串被网关弄坏时，用 tg_user_id + 近期 bot 会话
        if ($tgUserId > 0 && self::recentBotTouch($tgUserId)) {
            $maxAge = max(300, (int)FansHubService::config('telegram_init_max_age', 86400));
            if ($authDate > 0 && abs(time() - $authDate) <= $maxAge) {
                $user = is_array($decodedUser) ? $decodedUser : ['id' => $tgUserId];
                if ((int)($user['id'] ?? 0) !== $tgUserId) {
                    $user['id'] = $tgUserId;
                }
                Log::write('[tg] bind fallback bot_touch tg=' . $tgUserId, 'info');
                return [
                    'tg_user_id'    => $tgUserId,
                    'tg_username'   => (string)($user['username'] ?? ''),
                    'tg_first_name' => (string)($user['first_name'] ?? ''),
                    'tg_last_name'  => (string)($user['last_name'] ?? ''),
                    'start_param'   => (string)($params['start_param'] ?? ''),
                    'auth_date'     => $authDate,
                    'raw_user'      => $user,
                ];
            }
        }
        throw $lastErr ?: new Exception('Telegram 数据无效');
    }

    /**
     * 统一解析 WebApp 用户（initData 字符串 → 失败则用 initDataUnsafe 字段）
     *
     * @param \think\Request|null $request
     * @return array
     * @throws Exception
     */
    public static function resolveTgFromRequest($request = null)
    {
        $initData = self::readInitDataFromRequest($request);
        if ($initData !== '') {
            try {
                return self::validateInitData($initData);
            } catch (\Exception $e) {
                Log::write('[tg] initData validate fail: ' . $e->getMessage(), 'info');
            }
        }
        return self::validateFromUnsafeParts($request);
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
        $initData = self::normalizeInitData($initData);
        if ($initData === '') {
            throw new Exception('缺少 Telegram 登录数据');
        }
        $token = self::botToken();
        if ($token === '') {
            throw new Exception('Telegram 机器人未配置');
        }
        $params = [];
        parse_str($initData, $params);
        if (empty($params['hash']) && !empty($params['amp;hash'])) {
            $params['hash'] = $params['amp;hash'];
            unset($params['amp;hash']);
        }
        if (empty($params['hash'])) {
            throw new Exception('Telegram 数据无效');
        }
        self::verifyInitDataParams($params);
        return self::paramsToTgResult($params);
    }

    /**
     * @param array $params 含 hash 的 initData 键值
     * @throws Exception
     */
    protected static function verifyInitDataParams(array $params)
    {
        $token = self::botToken();
        if ($token === '') {
            throw new Exception('Telegram 机器人未配置');
        }
        if (empty($params['hash'])) {
            throw new Exception('Telegram 数据无效');
        }
        $hash = (string)$params['hash'];
        unset($params['hash'], $params['signature'], $params['amp;hash']);
        ksort($params);
        $lines = [];
        foreach ($params as $k => $v) {
            if (is_array($v)) {
                continue;
            }
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
    }

    /**
     * @param array $params
     * @return array
     * @throws Exception
     */
    protected static function paramsToTgResult(array $params)
    {
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
            'auth_date'     => (int)($params['auth_date'] ?? 0),
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
        return self::authByTg($tg);
    }

    /**
     * WebApp：从请求解析 TG 用户并登录/提示绑手机
     *
     * @param \think\Request|null $request
     * @return array
     * @throws Exception
     */
    public static function authFromRequest($request = null)
    {
        $tg = self::resolveTgFromRequest($request);
        return self::authByTg($tg);
    }

    /**
     * @param array $tg validateInitData / resolveTgFromRequest 结果
     * @return array
     * @throws Exception
     */
    protected static function authByTg(array $tg)
    {
        $bind = self::findBindByTg($tg['tg_user_id']);
        if ($bind) {
            $data = self::issueTokenForUser((int)$bind['user_id']);
            $data['tg'] = [
                'tg_user_id'  => $tg['tg_user_id'],
                'username'    => $tg['tg_username'],
                'first_name'  => $tg['tg_first_name'],
                'start_param' => $tg['start_param'],
                'locale'      => self::getLocale($tg['tg_user_id'], $tg['raw_user'] ?? []),
            ];
            return $data;
        }
        return [
            'bound'     => false,
            'need_bind' => true,
            'token'     => '',
            'tg'        => [
                'tg_user_id'  => $tg['tg_user_id'],
                'username'    => $tg['tg_username'],
                'first_name'  => $tg['tg_first_name'],
                'start_param' => $tg['start_param'],
                'locale'      => self::getLocale($tg['tg_user_id'], $tg['raw_user'] ?? []),
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
            'locale'     => self::getLocale($tg['tg_user_id'], $tg['raw_user'] ?? []),
        ];
        return $login;
    }

    /**
     * WebApp：从请求解析 TG 用户并绑定手机
     *
     * @param \think\Request|null $request
     * @return array
     * @throws Exception
     */
    public static function bindFromRequest($request, $mobile, $captcha, $inviteCode = '', $deviceFp = '')
    {
        $tg = self::resolveTgFromRequest($request);
        $inviteCode = trim((string)$inviteCode);
        if ($inviteCode === '' && $tg['start_param'] !== '') {
            $inviteCode = $tg['start_param'];
        }
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
            'locale'     => self::getLocale($tg['tg_user_id'], $tg['raw_user'] ?? []),
        ];
        return $login;
    }

    public static function mainMenuKeyboard($locale = 'zh-CN')
    {
        $locale = self::normalizeLocale($locale);
        $webUrl = self::webAppUrl('', $locale);
        return [
            'keyboard' => [
                [
                    ['text' => self::kbLabel('enter', $locale), 'web_app' => ['url' => $webUrl]],
                ],
                [
                    ['text' => self::kbLabel('account', $locale)],
                    ['text' => self::kbLabel('invite', $locale)],
                ],
                [
                    ['text' => self::kbLabel('cs', $locale)],
                    ['text' => self::kbLabel('home', $locale)],
                ],
                [
                    ['text' => self::kbLabel('lang', $locale)],
                ],
            ],
            'resize_keyboard'   => true,
            'one_time_keyboard' => false,
        ];
    }

    public static function languageInlineKeyboard($locale = 'zh-CN')
    {
        $locale = self::normalizeLocale($locale);
        $codes = FansHubService::i18nLocaleCodes();
        $rows = [];
        $row = [];
        foreach ($codes as $code => $label) {
            $mark = ($code === $locale) ? '✓ ' : '';
            $row[] = [
                'text'          => $mark . $label,
                'callback_data' => 'tg:set:' . $code,
            ];
            if (count($row) >= 2) {
                $rows[] = $row;
                $row = [];
            }
        }
        if ($row) {
            $rows[] = $row;
        }
        $rows[] = [[
            'text'          => self::t('tg_lang_back', $locale),
            'callback_data' => 'tg:home',
        ]];
        return ['inline_keyboard' => $rows];
    }

    public static function welcomeText($locale = 'zh-CN')
    {
        $locale = self::normalizeLocale($locale);
        $name = (string)FansHubService::config('telegram_bot_username', '');
        $bot = $name !== '' ? ('@' . ltrim($name, '@')) : self::t('tg_brand_fallback', $locale);
        return self::t('tg_welcome', $locale, [
            'bot'     => $bot,
            'enter'   => self::kbLabel('enter', $locale),
            'account' => self::kbLabel('account', $locale),
            'invite'  => self::kbLabel('invite', $locale),
            'cs'      => self::kbLabel('cs', $locale),
            'home'    => self::kbLabel('home', $locale),
            'lang'    => self::kbLabel('lang', $locale),
        ]);
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
        $tgId = (int)($from['id'] ?? 0);
        $locale = self::getLocale($tgId, $from);

        if ($id !== '') {
            self::api('answerCallbackQuery', ['callback_query_id' => $id]);
        }
        if ($data === 'menu:home' || $data === 'menu:main' || $data === 'tg:home') {
            return self::sendMainMenu($chatId, null, $from);
        }
        if ($data === 'menu:account' || $data === 'tg:account') {
            return self::replyAccount($chatId, $from);
        }
        if ($data === 'menu:invite' || $data === 'tg:invite') {
            return self::replyInvite($chatId, $from);
        }
        if ($data === 'menu:cs' || $data === 'tg:cs') {
            return self::api('sendMessage', [
                'chat_id' => $chatId,
                'text'    => self::csText($locale),
            ]);
        }
        if ($data === 'tg:lang' || $data === 'menu:lang') {
            return self::sendLanguagePicker($chatId, $from);
        }
        if (preg_match('/^tg:set:([A-Za-z]{2}-[A-Za-z]{2})$/', $data, $m)) {
            $next = self::setLocale($tgId, $m[1]);
            $codes = FansHubService::i18nLocaleCodes();
            $label = $codes[$next] ?? $next;
            $done = self::t('tg_lang_done', $next, ['label' => $label]);
            return self::sendMainMenu($chatId, $done . "\n\n" . self::welcomeText($next), $from, true);
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
        $tgId = (int)($from['id'] ?? 0);
        self::touchBotUser($tgId);
        $locale = self::getLocale($tgId, $from);
        $text = trim((string)($message['text'] ?? ''));

        // /start CODE
        if (preg_match('/^\/start(?:@\w+)?(?:\s+(.+))?$/u', $text, $m)) {
            $start = trim((string)($m[1] ?? ''));
            if ($start !== '') {
                try {
                    \think\Cache::set('tg_start_' . $tgId, $start, 3600);
                } catch (\Throwable $e) {
                }
            }
            return self::sendMainMenu($chatId, null, $from);
        }

        $action = self::matchKbAction($text);
        if ($action === 'home') {
            return self::sendMainMenu($chatId, null, $from);
        }
        if ($action === 'account') {
            return self::replyAccount($chatId, $from);
        }
        if ($action === 'invite') {
            return self::replyInvite($chatId, $from);
        }
        if ($action === 'cs') {
            return self::api('sendMessage', [
                'chat_id' => $chatId,
                'text'    => self::csText($locale),
            ]);
        }
        if ($action === 'lang') {
            return self::sendLanguagePicker($chatId, $from);
        }
        // enter 是 web_app，一般不会以纯文本到达；其它文本提示菜单
        $hint = self::t('tg_hint_menu', $locale) . "\n\n" . self::welcomeText($locale);
        return self::sendMainMenu($chatId, $hint, $from);
    }

    public static function sendLanguagePicker($chatId, array $from = [])
    {
        $tgId = (int)($from['id'] ?? 0);
        $locale = self::getLocale($tgId, $from);
        return self::api('sendMessage', [
            'chat_id'      => $chatId,
            'text'         => self::t('tg_lang_title', $locale),
            'reply_markup' => json_encode(self::languageInlineKeyboard($locale), JSON_UNESCAPED_UNICODE),
        ]);
    }

    public static function sendMainMenu($chatId, $text = null, array $from = [], $forceRefreshKeyboard = false)
    {
        $tgId = (int)($from['id'] ?? 0);
        if ($tgId <= 0) {
            $tgId = (int)$chatId;
        }
        // 下发过菜单 = 用户已与机器人建立会话，WebApp 绑手机可兜底
        self::touchBotUser($tgId);
        $locale = self::getLocale($tgId, $from);
        // 仅切换语言等场景才先卸旧键盘；/start 首次不要再发「正在更新菜单」
        if ($forceRefreshKeyboard) {
            try {
                self::api('sendMessage', [
                    'chat_id'      => $chatId,
                    'text'         => self::t('tg_menu_updating', $locale),
                    'reply_markup' => json_encode(['remove_keyboard' => true], JSON_UNESCAPED_UNICODE),
                ]);
            } catch (\Throwable $e) {
            }
        }
        return self::api('sendMessage', [
            'chat_id'      => $chatId,
            'text'         => $text !== null ? $text : self::welcomeText($locale),
            'reply_markup' => json_encode(self::mainMenuKeyboard($locale), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    protected static function needBindHint($chatId, array $from = [])
    {
        $tgId = (int)($from['id'] ?? 0);
        $locale = self::getLocale($tgId, $from);
        $enter = self::kbLabel('enter', $locale);
        $webUrl = self::webAppUrl('', $locale);
        return self::api('sendMessage', [
            'chat_id'      => $chatId,
            'text'         => self::t('tg_need_bind', $locale, ['enter' => $enter]),
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => $enter, 'web_app' => ['url' => $webUrl]]],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    protected static function replyAccount($chatId, array $from)
    {
        $tgId = (int)($from['id'] ?? 0);
        $locale = self::getLocale($tgId, $from);
        $bind = self::findBindByTg($tgId);
        if (!$bind) {
            return self::needBindHint($chatId, $from);
        }
        $profile = FansHubService::profilePayload((int)$bind['user_id']);
        $hongbao = number_format((float)($profile['hongbao'] ?? 0), 2, '.', '');
        $rights = number_format((float)($profile['rights'] ?? 0), 2, '.', '');
        $uid = (int)($profile['user_id'] ?? 0);
        $mask = (string)($profile['mobile_mask'] ?? '');
        $text = self::t('tg_account_body', $locale, [
            'uid'     => $uid,
            'mobile'  => $mask,
            'hongbao' => $hongbao,
            'rights'  => $rights,
        ]);
        return self::api('sendMessage', [
            'chat_id' => $chatId,
            'text'    => $text,
        ]);
    }

    protected static function replyInvite($chatId, array $from)
    {
        $tgId = (int)($from['id'] ?? 0);
        $locale = self::getLocale($tgId, $from);
        $bind = self::findBindByTg($tgId);
        if (!$bind) {
            return self::needBindHint($chatId, $from);
        }
        $share = FansHubService::buildSharePayload((int)$bind['user_id']);
        $link = (string)($share['share_link'] ?? '');
        if ($link === '') {
            $text = self::t('tg_invite_fail', $locale);
        } else {
            $text = self::t('tg_invite_link', $locale, ['link' => $link]);
        }
        $botUser = ltrim((string)FansHubService::config('telegram_bot_username', ''), '@');
        $code = (string)(($share['profile']['invite_code'] ?? '') ?: '');
        if ($botUser !== '' && $code !== '') {
            $tgLink = 'https://t.me/' . $botUser . '?start=' . rawurlencode($code);
            $text .= "\n\n" . self::t('tg_invite_tg', $locale, ['link' => $tgLink]);
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
        // 出站审计：成功发出的文案也会落日志，便于区分「本机发」vs「Token 被盗在外部发」
        if (in_array((string)$method, ['sendMessage', 'sendPhoto', 'copyMessage', 'forwardMessage'], true)) {
            $preview = '';
            if (isset($data['text'])) {
                $preview = mb_substr(preg_replace('/\s+/u', ' ', (string)$data['text']), 0, 120);
            } elseif (isset($data['caption'])) {
                $preview = mb_substr(preg_replace('/\s+/u', ' ', (string)$data['caption']), 0, 120);
            }
            Log::write(sprintf(
                '[tg-out] method=%s chat_id=%s preview=%s',
                $method,
                (string)($data['chat_id'] ?? ''),
                $preview
            ), 'info');
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
