#!/usr/bin/env php
<?php
/**
 * P2 自动化：确认 push_wake 已去掉（无空 PUBLISH）
 *
 * 用法:
 *   php im-server/scripts/test_p2_push_wake.php
 *
 * 退出码 0=通过，1=失败
 */

$root = dirname(__DIR__);
$files = [
    $root . '/App/Support/PushBus.php',
    $root . '/App/Handler/MessageRouter.php',
    $root . '/start.php',
];

$fail = 0;
$ok = 0;

foreach ($files as $path) {
    if (!is_file($path)) {
        echo "[FAIL] missing {$path}\n";
        $fail++;
        continue;
    }
    $src = file_get_contents($path);
    if ($src === false) {
        echo "[FAIL] read {$path}\n";
        $fail++;
        continue;
    }
    if (preg_match('/push_wake/', $src)) {
        echo "[FAIL] push_wake still referenced in {$path}\n";
        $fail++;
        continue;
    }
    echo "[OK] no push_wake in " . basename($path) . "\n";
    $ok++;
}

// PushBus 必须仍有 Timer 消费路径（drainOwnQueue）
$pushBus = file_get_contents($root . '/App/Support/PushBus.php');
if ($pushBus === false || strpos($pushBus, 'function drainOwnQueue') === false) {
    echo "[FAIL] PushBus::drainOwnQueue missing — group push would stall without push_wake\n";
    $fail++;
} else {
    echo "[OK] PushBus::drainOwnQueue present (Timer drain path)\n";
    $ok++;
}

$start = file_get_contents($root . '/start.php');
if ($start === false || strpos($start, 'drainOwnQueue') === false) {
    echo "[FAIL] start.php does not schedule drainOwnQueue\n";
    $fail++;
} else {
    echo "[OK] start.php schedules drainOwnQueue\n";
    $ok++;
}

echo "\nSummary: ok={$ok} fail={$fail}\n";
exit($fail > 0 ? 1 : 0);
