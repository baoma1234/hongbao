<?php
$root = dirname(__DIR__);
$path = $root . '/public/888/partials/tab-exchange.php';
$html = file_get_contents($path);
$html = preg_replace('/(\s+data-copy-aria="[^"]+")(\s+data-copy-aria="[^"]+")+/', '$1', $html);
file_put_contents($path, $html);
echo "deduped exchange\n";

$path = $root . '/public/888/partials/tab-messages.php';
$html = file_get_contents($path);
$pairs = [
    ['<span>图片</span>', '<span data-copy="chat_attach_image">图片</span>'],
    ['<span>视频</span>', '<span data-copy="chat_attach_video">视频</span>'],
    ['<span>文件</span>', '<span data-copy="chat_attach_file">文件</span>'],
    ['<span>红包</span>', '<span data-copy="chat_attach_rp">红包</span>'],
    ['id="chatAttachBtn" title="更多" aria-label="更多"', 'id="chatAttachBtn" title="更多" aria-label="更多" data-copy-title="aria_more" data-copy-aria="aria_more"'],
];
foreach ($pairs as $p) {
    if (strpos($html, $p[0]) === false) {
        echo "MISS " . $p[0] . "\n";
    } else {
        $html = str_replace($p[0], $p[1], $html);
        echo "OK " . substr($p[0], 0, 30) . "\n";
    }
}
file_put_contents($path, $html);
