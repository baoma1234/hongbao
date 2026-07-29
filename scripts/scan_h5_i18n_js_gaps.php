<?php
$root = dirname(__DIR__);
$js = array_merge(
    glob($root . '/public/888/js/*.js') ?: [],
    glob($root . '/public/888/js/chat/*.js') ?: []
);
$report = [];
foreach ($js as $f) {
    $c = file_get_contents($f);
    preg_match_all('/["\']([^"\']*[\x{4e00}-\x{9fff}][^"\']*)["\']/u', $c, $m);
    $u = array_values(array_unique($m[1]));
    $u = array_values(array_filter($u, function ($t) {
        $t = trim($t);
        $len = function_exists('mb_strlen') ? mb_strlen($t, 'UTF-8') : strlen($t);
        return $len >= 1 && $len <= 100;
    }));
    if (!$u) continue;
    $rel = str_replace('\\', '/', substr($f, strlen($root) + 1));
    $report[$rel] = $u;
}
file_put_contents($root . '/scripts/_i18n_js_gaps.json', json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$lines = [];
foreach ($report as $rel => $items) {
    $lines[] = "## $rel (" . count($items) . ")";
    foreach (array_slice($items, 0, 80) as $t) {
        $lines[] = '  ' . $t;
    }
}
file_put_contents($root . '/scripts/_i18n_js_gaps.txt', implode(PHP_EOL, $lines));
echo 'js_files=' . count($report) . PHP_EOL;
echo 'js_strings=' . array_sum(array_map('count', $report)) . PHP_EOL;
