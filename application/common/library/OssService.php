<?php

namespace app\common\library;

use think\Config;
use think\Log;

/**
 * 阿里云 OSS 轻量上传（本地双写，不强制 SDK）
 */
class OssService
{
    public static function config()
    {
        $cfg = Config::get('oss');
        if (!is_array($cfg) || !$cfg) {
            $file = APP_PATH . 'extra' . DS . 'oss.php';
            $cfg = is_file($file) ? include $file : [];
        }
        return is_array($cfg) ? $cfg : [];
    }

    public static function enabled()
    {
        $c = self::config();
        if (empty($c['enabled'])) {
            return false;
        }
        return $c['access_key_id'] !== ''
            && $c['access_key_secret'] !== ''
            && $c['bucket'] !== '';
    }

    public static function dualWrite()
    {
        $c = self::config();
        return self::enabled() && !empty($c['dual_write']);
    }

    /**
     * 对外访问基址，如 https://bucket.oss-accelerate.aliyuncs.com
     */
    public static function publicBase()
    {
        $c = self::config();
        $cdn = trim((string)($c['cdn_domain'] ?? ''));
        $bucket = trim((string)($c['bucket'] ?? ''));
        $endpoint = trim((string)($c['endpoint'] ?? 'oss-cn-hongkong.aliyuncs.com'));
        $endpoint = preg_replace('#^https?://#i', '', $endpoint);
        $endpoint = rtrim($endpoint, '/');

        if ($cdn !== '') {
            if (preg_match('#^https?://#i', $cdn)) {
                return rtrim($cdn, '/');
            }
            $cdnHost = rtrim($cdn, '/');
            // oss-accelerate.aliyuncs.com → https://{bucket}.oss-accelerate.aliyuncs.com
            if ($bucket !== '' && (stripos($cdnHost, 'aliyuncs.com') !== false || stripos($cdnHost, 'oss-') === 0)) {
                if (stripos($cdnHost, $bucket . '.') !== 0) {
                    return 'https://' . $bucket . '.' . $cdnHost;
                }
            }
            return 'https://' . $cdnHost;
        }
        if ($bucket === '' || $endpoint === '') {
            return '';
        }
        return 'https://' . $bucket . '.' . $endpoint;
    }

    public static function objectKeyFromUrl($url)
    {
        $url = '/' . ltrim(str_replace('\\', '/', (string)$url), '/');
        return ltrim($url, '/');
    }

    public static function publicUrl($url)
    {
        $base = self::publicBase();
        if ($base === '') {
            return '';
        }
        $key = self::objectKeyFromUrl($url);
        return $base . '/' . $key;
    }

    /**
     * 上传本地文件到 OSS（同路径 key）
     * @return bool
     */
    public static function putLocalFile($localPath, $objectKey)
    {
        if (!self::enabled()) {
            return false;
        }
        $localPath = (string)$localPath;
        if ($localPath === '' || !is_file($localPath)) {
            return false;
        }
        $c = self::config();
        $bucket = trim((string)$c['bucket']);
        $endpoint = preg_replace('#^https?://#i', '', trim((string)($c['endpoint'] ?? '')));
        $endpoint = rtrim($endpoint, '/');
        $ak = (string)$c['access_key_id'];
        $sk = (string)$c['access_key_secret'];

        $objectKey = ltrim(str_replace('\\', '/', (string)$objectKey), '/');
        if ($objectKey === '') {
            return false;
        }

        $body = @file_get_contents($localPath);
        if ($body === false) {
            return false;
        }
        $contentType = self::guessMime($localPath, $objectKey);
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $resource = '/' . $bucket . '/' . $objectKey;
        $stringToSign = "PUT\n\n{$contentType}\n{$date}\n{$resource}";
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $sk, true));
        $auth = 'OSS ' . $ak . ':' . $signature;

        // 上传走地域 endpoint；加速域名主要用于读
        $host = $bucket . '.' . $endpoint;
        $url = 'https://' . $host . '/' . self::rawurlencodePath($objectKey);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => [
                'Date: ' . $date,
                'Content-Type: ' . $contentType,
                'Authorization: ' . $auth,
                'Content-Length: ' . strlen($body),
                'Host: ' . $host,
            ],
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($code >= 200 && $code < 300) {
            return true;
        }
        try {
            $snippet = substr((string)$resp, 0, 500);
            $hint = '';
            if (stripos($snippet, 'PutObject') !== false || stripos($snippet, 'AccessDenied') !== false) {
                $hint = ' (RAM 子账号需 oss:PutObject；当前为 ImplicitDeny/bucket acl)';
            }
            Log::error('[oss] put fail http=' . $code . ' key=' . $objectKey . ' err=' . $err . $hint . ' body=' . $snippet);
        } catch (\Throwable $e) {
        }
        return false;
    }

    /**
     * 本地上传成功后双写；成功则标记 storage
     */
    public static function syncAfterLocalUpload($attachment)
    {
        if (!self::dualWrite() || !$attachment) {
            return false;
        }
        $url = is_object($attachment) ? (string)$attachment->url : (string)($attachment['url'] ?? '');
        if ($url === '') {
            return false;
        }
        $local = ROOT_PATH . 'public' . str_replace('/', DS, $url);
        if (!is_file($local)) {
            try {
                Log::error('[oss] dual-write local missing path=' . $local);
            } catch (\Throwable $e) {
            }
            return false;
        }
        $ok = self::putLocalFile($local, self::objectKeyFromUrl($url));
        if ($ok && is_object($attachment)) {
            try {
                $attachment->storage = 'local,oss';
                $attachment->save();
            } catch (\Throwable $e) {
            }
        }
        return $ok;
    }

    /** 启用 OSS 时，把 upload.cdnurl 指到公网基址，cdnurl() 返回 OSS 地址 */
    public static function applyUploadCdn()
    {
        if (!self::enabled()) {
            return;
        }
        $base = self::publicBase();
        if ($base === '') {
            return;
        }
        Config::set('upload.cdnurl', $base);
        try {
            $upload = Config::get('upload');
            if (is_array($upload)) {
                $upload['cdnurl'] = $base;
                Config::set('upload', $upload);
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * 附件公网地址：OSS 启用时优先加速域名（不依赖 storage 标记，避免双写未落库仍回本地）
     */
    public static function fullUrl($url, $storage = '')
    {
        $url = (string)$url;
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url) || stripos($url, 'data:') === 0) {
            // 已是绝对地址：若误指向本站 /uploads，改写到 OSS
            if (self::enabled() && preg_match('#/uploads/#i', $url)) {
                try {
                    $path = parse_url($url, PHP_URL_PATH);
                    if (is_string($path) && strpos($path, '/uploads/') === 0) {
                        $oss = self::publicUrl($path);
                        if ($oss !== '') {
                            return $oss;
                        }
                    }
                } catch (\Throwable $e) {
                }
            }
            return $url;
        }
        if (self::enabled()) {
            $oss = self::publicUrl($url);
            if ($oss !== '') {
                return $oss;
            }
        }
        if (function_exists('cdnurl')) {
            return cdnurl($url, true);
        }
        return $url;
    }

    protected static function rawurlencodePath($path)
    {
        $parts = explode('/', $path);
        foreach ($parts as &$p) {
            if ($p === '') {
                continue;
            }
            $p = rawurlencode($p);
        }
        return implode('/', $parts);
    }

    protected static function guessMime($localPath, $objectKey)
    {
        $ext = strtolower(pathinfo($objectKey ?: $localPath, PATHINFO_EXTENSION));
        $map = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
            'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
            'mp3' => 'audio/mpeg', 'wav' => 'audio/wav',
            'pdf' => 'application/pdf', 'zip' => 'application/zip', 'json' => 'application/json',
        ];
        if (isset($map[$ext])) {
            return $map[$ext];
        }
        if (function_exists('mime_content_type')) {
            $m = @mime_content_type($localPath);
            if ($m) {
                return $m;
            }
        }
        return 'application/octet-stream';
    }
}
