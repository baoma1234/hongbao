<?php

namespace Im\Support;

/**
 * 当 Redis notify_queue 不可用时，经本机 WS 内部通道投递推送
 */
class LocalWsPush
{
    public static function notify($type, array $message, $adminOnly = false, array $cfg = null)
    {
        if ($cfg === null) {
            $cfg = require dirname(__DIR__, 2) . '/config/app.php';
        }
        $host = '127.0.0.1';
        $port = 7272;
        if (!empty($cfg['websocket']['listen'])) {
            if (preg_match('#:(\d+)$#', (string)$cfg['websocket']['listen'], $m)) {
                $port = (int)$m[1];
            }
        }
        $adminKey = (string)($cfg['admin_bridge']['key'] ?? 'change-me-im-admin');
        $payload = json_encode([
            'type' => 'internal.notify',
            'data' => [
                'admin_key'  => $adminKey,
                'type'       => (string)$type,
                'message'    => $message,
                'admin_only' => $adminOnly ? 1 : 0,
            ],
        ], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return false;
        }

        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 2.0);
        if (!$fp) {
            return false;
        }
        stream_set_timeout($fp, 2);

        $secKey = base64_encode(random_bytes(16));
        $req = "GET / HTTP/1.1\r\n"
            . 'Host: ' . $host . ':' . $port . "\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . 'Sec-WebSocket-Key: ' . $secKey . "\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n";
        fwrite($fp, $req);
        $headers = '';
        while (!feof($fp)) {
            $line = fgets($fp, 1024);
            if ($line === false) {
                break;
            }
            $headers .= $line;
            if ($line === "\r\n" || $line === "\n") {
                break;
            }
        }
        if (stripos($headers, '101') === false) {
            fclose($fp);
            return false;
        }

        fwrite($fp, self::encodeFrame($payload));
        usleep(80000);
        fclose($fp);
        return true;
    }

    protected static function encodeFrame($payload)
    {
        $len = strlen($payload);
        $frame = chr(0x81);
        $maskKey = random_bytes(4);
        if ($len <= 125) {
            $frame .= chr(0x80 | $len);
        } elseif ($len <= 65535) {
            $frame .= chr(0x80 | 126) . pack('n', $len);
        } else {
            $frame .= chr(0x80 | 127) . pack('J', $len);
        }
        $frame .= $maskKey;
        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= $payload[$i] ^ $maskKey[$i % 4];
        }
        return $frame . $masked;
    }
}
