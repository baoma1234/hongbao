<?php
$path = dirname(__DIR__) . '/public/888/index.html';
$lines = file($path);
$fixed = 0;
foreach ($lines as $i => $line) {
    // // comment then code on same line (not http://)
    if (preg_match('/^(.*?\/\/)(?!\/)(.*)$/', $line, $m)) {
        $before = $m[1]; // includes //
        $after = $m[2];
        // strip the // part's content - actually before is everything through //
        // Better: find // that's not in string
        if (!preg_match('/^(\s*\/\/.*?)(\b(?:if|const|let|var|return|for|while|switch|try|function|document|await|async)\b.*)$/u', $line, $mm)) {
            // also: comment ending with ? then immediately code without space issues
            if (preg_match('/^(\s*\/\/[^\n]*?)(\s{2,}(?:if|const|let|var|return|for|while|document|await)\b.*)$/u', $line, $mm)) {
                // ok
            } else {
                continue;
            }
        }
        $comment = rtrim($mm[1]);
        $code = ltrim($mm[2]);
        if ($code === '') continue;
        $indent = '';
        if (preg_match('/^(\s*)/', $line, $ind)) $indent = $ind[1];
        $lines[$i] = $comment . "\n" . $indent . $code . (str_ends_with($code, "\n") ? '' : "\n");
        // if original had \n already in code via weirdness
        $lines[$i] = $comment . "\n" . $indent . rtrim($code) . "\n";
        $fixed++;
        echo "SPLIT L" . ($i + 1) . " code=" . substr($code, 0, 60) . "\n";
    }
}
file_put_contents($path, implode('', $lines));
echo "fixed=$fixed\n";

$h = file_get_contents($path);
preg_match_all('#<script(?![^>]+src=)[^>]*>(.*?)</script>#si', $h, $m);
$s = '';
foreach ($m[1] as $b) {
    if (strlen($b) > strlen($s)) $s = $b;
}
$t = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fh_check.js';
file_put_contents($t, $s);
$out = [];
$code = 0;
exec('node --check ' . escapeshellarg($t) . ' 2>&1', $out, $code);
echo implode("\n", array_slice($out, 0, 6)) . "\nexit=$code\n";
