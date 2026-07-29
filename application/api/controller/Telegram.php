<?php
namespace app\api\controller;

use app\common\controller\Api;
use think\Log;

/**
 * Telegram 群组 ID 查询机器人
 * 群内发送「群组信息」时回复群 ID
 */
class Telegram extends Api
{
    protected $noNeedLogin = ['*'];
    protected $botToken = '8867639246:AAEIawpMD6BkyFRo3mEEAcMsOw7S-__E2oQ';

    public function webhook()
    {
        $raw = file_get_contents('php://input');
        $update = json_decode($raw, true);

        Log::write('TG webhook raw: ' . $raw, 'info');

        if (!$update || !isset($update['message'])) {
            Log::write('TG webhook ignored: no message', 'info');
            return json(['status' => 'ignored']);
        }

        $message = $update['message'];
        $chat = $message['chat'] ?? null;
        $text = $this->normalizeText($message['text'] ?? '');

        Log::write(sprintf(
            'TG message chat_type=%s chat_id=%s text=%s from_bot=%s',
            $chat['type'] ?? '',
            $chat['id'] ?? '',
            $text,
            !empty($message['from']['is_bot']) ? '1' : '0'
        ), 'info');

        if (!$chat
            || !in_array($chat['type'], ['group', 'supergroup'], true)
            || !empty($message['from']['is_bot'])
            || $text !== '群组信息') {
            Log::write('TG webhook ignored: condition not match', 'info');
            return json(['status' => 'ignored', 'text' => $text]);
        }

        $result = $this->replyGroupInfo($chat);
        Log::write('TG sendMessage result: ' . json_encode($result, JSON_UNESCAPED_UNICODE), 'info');

        return json(['status' => 'ok', 'telegram' => $result]);
    }

    /**
     * 调试接口：浏览器访问 /api/telegram/debug
     */
    public function debug()
    {
        $me = $this->requestTelegram('getMe', []);
        $webhook = $this->requestTelegram('getWebhookInfo', []);

        return json([
            'bot'     => $me,
            'webhook' => $webhook,
            'tips'    => [
                '1. webhook.url 必须是 https 且公网可访问',
                '2. 群里发普通文字需关闭隐私模式：@BotFather -> /setprivacy -> Disable',
                '3. 或在群里 @机器人 发送：群组信息',
                '4. 日志路径：runtime/log/',
            ],
        ]);
    }

    private function normalizeText($text)
    {
        $text = trim($text);
        // 去掉 @机器人名 后缀，如：群组信息@my_bot
        $text = preg_replace('/@\w+$/u', '', $text);
        return trim($text);
    }

    private function replyGroupInfo(array $chat)
    {
        $chatId = $chat['id'];
        $title = $chat['title'] ?? '未知群组';
        $type = $chat['type'] ?? '';
        $groupUsername = $chat['username'] ?? '';

        $lines = [
            '📋 群组信息',
            '',
            '群名称：' . $title,
            '群类型：' . $type,
        ];
        if ($groupUsername !== '') {
            $lines[] = '群用户名：@' . $groupUsername;
        }
        $lines[] = '';
        $lines[] = '🆔 Chat ID（群组ID）';
        $lines[] = (string)$chatId;
        $lines[] = '';
        $lines[] = '请将此 ID 填入后台【商户通道 → Telegram群组ID】';

        return $this->requestTelegram('sendMessage', [
            'chat_id' => $chatId,
            'text'    => implode("\n", $lines),
        ]);
    }

    private function requestTelegram($method, $data)
    {
        $url = "https://api.telegram.org/bot{$this->botToken}/{$method}";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $res = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            Log::write("TG curl error [{$method}]: {$error}", 'error');
            return ['ok' => false, 'curl_error' => $error];
        }

        return json_decode($res, true) ?: ['ok' => false, 'raw' => $res];
    }
}
