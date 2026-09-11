<?php
/**
 * 本地回源：/uploads/**/*.js 伪装图按真实图片 MIME 输出
 * Nginx 可：location ~* ^/uploads/.*\.js$ { rewrite ^ /serve_upload_js.php?u=$uri last; }
 * 或：try_files $uri /serve_upload_js.php?u=$uri;
 */
$uri = isset($_GET['u']) ? (string)$_GET['u'] : (string)($_SERVER['REQUEST_URI'] ?? '');
$uri = parse_url($uri, PHP_URL_PATH) ?: $uri;
$uri = '/' . ltrim(str_replace('\\', '/', $uri), '/');
if (!preg_match('#^/uploads/.+\.js$#i', $uri) || strpos($uri, '..') !== false) {
    http_response_code(404);
    exit;
}
$file = __DIR__ . str_replace('/', DIRECTORY_SEPARATOR, $uri);
if (!is_file($file)) {
    http_response_code(404);
    exit;
}
$mime = 'image/jpeg';
if (function_exists('mime_content_type')) {
    $m = @mime_content_type($file);
    if (is_string($m) && strpos($m, 'image/') === 0) {
        $mime = $m;
    }
} else {
    $info = @getimagesize($file);
    if (is_array($info) && !empty($info['mime'])) {
        $mime = (string)$info['mime'];
    }
}
header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=31536000');
header('X-Content-Type-Options: nosniff');
readfile($file);
