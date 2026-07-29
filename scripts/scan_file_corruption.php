<?php
$root = dirname(__DIR__);
$files = array_merge(
    glob($root . '/public/888/js/*.js') ?: [],
    glob($root . '/public/888/js/chat/*.js') ?: [],
    glob($root . '/public/888/partials/*.php') ?: []
);
foreach ($files as $f) {
    $raw = file_get_contents($f);
    $repl = substr_count($raw, "\xEF\xBF\xBD");
    // truncated tag/string patterns common after bad writes
    $trunc = 0;
    $trunc += preg_match_all('/[\x{4e00}-\x{9fff}]\?(?:<\/|[\'"`])/u', $raw);
    $trunc += preg_match_all('/结\?|明\?|账\?|成\?|红\?|一\?|多\?|加\?|中\?/u', $raw);
    $moji = preg_match_all('/鍏|褰撳|鑲′|鏈|鐢熸/u', $raw);
    if ($repl || $trunc || $moji) {
        $rel = str_replace('\\', '/', substr($f, strlen($root) + 1));
        echo "$rel repl=$repl trunc~$trunc moji=$moji\n";
    }
}
