<?php

namespace app\api\controller;

use app\common\controller\Api;
use app\common\library\FansHubService;
use app\common\library\FansHubTelegram;
use think\Log;

/**
 * 用户侧 Telegram 机器人 Webhook（进入游戏 WebApp / 菜单）
 *
 * Webhook: POST /api/telegrambot/webhook?key={telegram_webhook_secret}
 */
class Telegrambot extends Api
{
    protected $noNeedLogin = ['*'];
    protected $noNeedRight = '*';

    public function webhook()
    {
        $secret = FansHubTelegram::webhookSecret();
        $key = trim((string)$this->request->get('key', $this->request->header('X-Telegram-Bot-Api-Secret-Token', '')));
        if ($secret !== '' && !hash_equals($secret, $key)) {
            return json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        if (!FansHubTelegram::enabled()) {
            return json(['ok' => false, 'error' => 'bot_disabled']);
        }
        $raw = file_get_contents('php://input');
        $update = json_decode($raw, true);
        if (!is_array($update)) {
            return json(['ok' => true, 'ignored' => 'bad_json']);
        }
        try {
            $result = FansHubTelegram::handleUpdate($update);
            return json(['ok' => true, 'result' => $result]);
        } catch (\Throwable $e) {
            Log::write('Telegrambot webhook: ' . $e->getMessage(), 'error');
            return json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * 调试：浏览器 GET /api/telegrambot/debug?key=...
     */
    public function debug()
    {
        $this->assertSecret();
        $me = FansHubTelegram::api('getMe', []);
        $hook = FansHubTelegram::api('getWebhookInfo', []);
        $cfg = FansHubService::config();
        return json([
            'enabled'   => FansHubTelegram::enabled(),
            'bot'       => $me,
            'webhook'   => $hook,
            'webapp'    => FansHubTelegram::webAppUrl(),
            'username'  => (string)($cfg['telegram_bot_username'] ?? ''),
            'tips'      => [
                '1. 填 fanshub.php 的 telegram_bot_token / telegram_bot_username',
                '2. php scripts/patch_fans_telegram_bind.php',
                '3. php scripts/set_telegram_bot_webhook.php',
                '4. BotFather 开启 Domain 供 WebApp（或 /setdomain）',
            ],
        ]);
    }

    /**
     * 设置 webhook：GET /api/telegrambot/setwebhook?key=...
     */
    public function setwebhook()
    {
        $this->assertSecret();
        if (!FansHubTelegram::enabled()) {
            return json(['ok' => false, 'error' => 'bot_disabled_or_no_token']);
        }
        $domain = rtrim((string)FansHubService::config('invite_base_url', ''), '/');
        if ($domain === '') {
            $domain = rtrim((string)$this->request->domain(), '/');
        }
        $secret = FansHubTelegram::webhookSecret();
        $url = $domain . '/api/telegrambot/webhook?key=' . rawurlencode($secret);
        $res = FansHubTelegram::api('setWebhook', [
            'url'                  => $url,
            'secret_token'         => $secret,
            'allowed_updates'      => json_encode(['message', 'callback_query']),
            'drop_pending_updates' => 'true',
        ]);
        return json(['ok' => true, 'webhook_url' => $url, 'telegram' => $res]);
    }

    protected function assertSecret()
    {
        $secret = FansHubTelegram::webhookSecret();
        $key = trim((string)$this->request->get('key', ''));
        if ($secret === '' || !hash_equals($secret, $key)) {
            abort(403, 'forbidden');
        }
    }
}
