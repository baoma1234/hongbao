<?php
$html = file_get_contents(__DIR__ . '/../public/888/index.html');
if (!preg_match('/<script>([\s\S]*?)<\/script>\s*<script src="chat\.js"/s', $html, $m)) {
    fwrite(STDERR, "script not found\n");
    exit(1);
}
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fh_check.js';
file_put_contents($tmp, $m[1]);
passthru('node --check ' . escapeshellarg($tmp), $code);
exit($code);
