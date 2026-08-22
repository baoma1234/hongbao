<?php
/**
 * 鱼虾蟹库表一键补齐（7 个 patch，均可重复跑，已执行会 SKIP）
 *
 * 另一台机器 git pull 后只需：
 *   php scripts/patch_yxx_all.php
 *
 * 顺序：
 *   1. patch_yxx_pool_v1.php         奖池 / 日流水 / 局归档 / 红包雨表
 *   2. patch_yxx_scale_v1.php        rain_grants.paid
 *   3. patch_yxx_tron_v1.php         局归档波场高度/哈希
 *   4. patch_yxx_rain_v2.php         奖池 settings JSON + 索引
 *   5. patch_yxx_admin_v1.php        后台菜单
 *   6. patch_yxx_group_v1.php        群桌爆点池
 *   7. patch_yxx_group_daily_v1.php  群桌日下注权重（解散分池）
 *   8. patch_yxx_admin_v2.php        后台补齐（总开关/群桌/日下注/权限）
 *   9. patch_yxx_group_perm_v1.php   群聊 yxx_enabled 后台开通字段
 *
 * 不改 yxx_tab_visible。IM 解散挂钩还要重启 IM 进程。
 */
$root = dirname(__DIR__);
$php = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
$files = [
    'patch_yxx_pool_v1.php',
    'patch_yxx_scale_v1.php',
    'patch_yxx_tron_v1.php',
    'patch_yxx_rain_v2.php',
    'patch_yxx_admin_v1.php',
    'patch_yxx_group_v1.php',
    'patch_yxx_group_daily_v1.php',
    'patch_yxx_admin_v2.php',
    'patch_yxx_group_perm_v1.php',
];
$fail = 0;
foreach ($files as $i => $name) {
    $path = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . $name;
    echo "\n===== " . ($i + 1) . '/9 ' . $name . " =====\n";
    if (!is_file($path)) {
        fwrite(STDERR, "MISSING {$path}\n");
        $fail++;
        continue;
    }
    $code = 1;
    $out = '';
    if (function_exists('proc_open')) {
        $desc = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open([$php, $path], $desc, $pipes, $root);
        if (is_resource($proc)) {
            $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
        }
    } elseif (function_exists('exec')) {
        $lines = [];
        exec(escapeshellarg($php) . ' ' . escapeshellarg($path) . ' 2>&1', $lines, $code);
        $out = implode("\n", $lines) . "\n";
    } else {
        fwrite(STDERR, "no proc_open/exec; run: php scripts/{$name}\n");
        $fail++;
        continue;
    }
    echo $out;
    if ((int)$code !== 0) {
        fwrite(STDERR, "FAIL {$name} exit={$code}\n");
        $fail++;
    }
}
echo "\n";
if ($fail > 0) {
    fwrite(STDERR, "DONE_WITH_ERRORS fail={$fail}/8\n");
    exit(1);
}
echo "DONE yxx all 8/8\n";
