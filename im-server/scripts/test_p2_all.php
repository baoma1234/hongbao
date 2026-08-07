#!/usr/bin/env php
<?php
/**
 * P2 一键跑：push_wake 静态检查 + 退避单测
 *
 *   php im-server/scripts/test_p2_all.php
 *   # Windows 若 disable_functions 禁了 passthru/exec，用:
 *   powershell -File im-server/scripts/test_p2_all.ps1
 */

$root = dirname(__DIR__);
$fail = 0;

function p2_run($cmd, &$code)
{
    $code = 1;
    if (function_exists('passthru')) {
        passthru($cmd, $code);
        return;
    }
    if (function_exists('system')) {
        system($cmd, $code);
        return;
    }
    if (function_exists('exec')) {
        $out = [];
        exec($cmd . ' 2>&1', $out, $code);
        echo implode(PHP_EOL, $out) . PHP_EOL;
        return;
    }
    if (function_exists('shell_exec')) {
        $out = shell_exec($cmd . ' 2>&1');
        echo (string)$out;
        // shell_exec 无退出码：用输出里的 fail= 粗判
        $code = (strpos((string)$out, '[FAIL]') !== false) ? 1 : 0;
        return;
    }
    echo "[FAIL] no passthru/system/exec/shell_exec — run test_p2_all.ps1 instead\n";
    $code = 1;
}

echo "=== 1) push_wake ===\n";
p2_run('php ' . escapeshellarg($root . '/scripts/test_p2_push_wake.php'), $code1);
if ($code1 !== 0) {
    $fail++;
}

echo "\n=== 2) reconnect backoff ===\n";
p2_run('node ' . escapeshellarg($root . '/scripts/test_p2_reconnect_backoff.mjs'), $code2);
if ($code2 !== 0) {
    $fail++;
}

echo "\n=== P2 total fail={$fail} ===\n";
if ($fail === 0) {
    echo "CI / 日常:\n";
    echo "  php im-server/scripts/test_p2_all.php\n";
    echo "  powershell -File im-server/scripts/test_p2_all.ps1\n";
    echo "  node im-server/scripts/test_p2_reconnect_backoff.mjs --storm=5000\n";
}
exit($fail > 0 ? 1 : 0);
