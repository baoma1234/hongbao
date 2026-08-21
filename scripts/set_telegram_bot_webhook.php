<?php
/**
 * Register Telegram bot webhook.
 *
 * Usage:
 *   php scripts/set_telegram_bot_webhook.php
 */
$root = dirname(__DIR__);
define('APP_PATH', $root . '/application/');
define('ROOT_PATH', $root . DIRECTORY_SEPARATOR);

$cfg = include $root . '/application/extra/fanshub.php';
$token = trim((string)($cfg['telegram_bot_token'] ?? ''));
$secret = trim((string)($cfg['telegram_webhook_secret'] ?? ''));
$base = rtrim((string)($cfg['invite_base_url'] ?? 'https://hbsq.bio'), '/');

if ($token === '') {
    fwrite(STDERR, "Set telegram_bot_token in application/extra/fanshub.php first.\n");
    exit(1);
}

$url = $base . '/api/telegrambot/webhook?key=' . rawurlencode($secret);
$api = 'https://api.telegram.org/bot' . $token . '/setWebhook';
$post = [
    'url'                  => $url,
    'secret_token'         => $secret,
    'allowed_updates'      => json_encode(['message', 'callback_query']),
    'drop_pending_updates' => 'true',
];

$ch = curl_init($api);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);
$res = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

echo "webhook_url={$url}\n";
if ($res === false) {
    fwrite(STDERR, "curl error: {$err}\n");
    exit(1);
}
echo $res . "\n";

$infoCh = curl_init('https://api.telegram.org/bot' . $token . '/getWebhookInfo');
curl_setopt($infoCh, CURLOPT_RETURNTRANSFER, true);
curl_setopt($infoCh, CURLOPT_SSL_VERIFYPEER, false);
echo curl_exec($infoCh) . "\n";
curl_close($infoCh);

// 同步「进入游戏」菜单按钮（无 hash，避免 #tgWebAppData 冲突）
require_once $root . '/thinkphp/base.php';
\think\App::initCommon();
$webUrl = \app\common\library\FansHubTelegram::webAppUrl();
$menuApi = 'https://api.telegram.org/bot' . $token . '/setChatMenuButton';
$menuPost = [
    'menu_button' => json_encode([
        'type' => 'web_app',
        'text' => '进入游戏',
        'web_app' => ['url' => $webUrl],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
];
$mch = curl_init($menuApi);
curl_setopt($mch, CURLOPT_POST, true);
curl_setopt($mch, CURLOPT_POSTFIELDS, $menuPost);
curl_setopt($mch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($mch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($mch, CURLOPT_TIMEOUT, 20);
$menuRes = curl_exec($mch);
curl_close($mch);
echo "web_app_url={$webUrl}\n";
echo "setChatMenuButton={$menuRes}\n";
