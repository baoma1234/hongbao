<?php
/**
 * 安装「游戏账号核销」SugarCRM 自动审核（每分钟）
 *
 * Windows：创建计划任务 FansHubUidSugar
 * Linux：  追加 crontab * * * * * php think fanshub:uid-sugar
 *
 * 用法：php scripts/install_uid_sugar_cron.php
 */
$root = str_replace('/', DIRECTORY_SEPARATOR, dirname(__DIR__));
$php = PHP_BINARY ?: 'php';
$think = $root . DIRECTORY_SEPARATOR . 'think';
$logDir = $root . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'log';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$log = $logDir . DIRECTORY_SEPARATOR . 'uid_sugar_cron.log';

echo "php={$php}\n";
echo "cmd={$php} {$think} fanshub:uid-sugar\n";

if (stripos(PHP_OS, 'WIN') === 0) {
    $task = 'FansHubUidSugar';
    $vbs = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'win_php_think_hidden.vbs';
    // 用 wscript 无窗口启动，避免每分钟弹出 php.exe 黑框
    $tr = 'wscript.exe //B //Nologo "' . $vbs . '" "' . $php . '" "' . $think . '" fanshub:uid-sugar';
    exec('schtasks /Delete /TN "' . $task . '" /F 2>NUL');
    $cmd = 'schtasks /Create /TN "' . $task . '" /TR "' . $tr . '" /SC MINUTE /MO 1 /RL LIMITED /F';
    echo "run: {$cmd}\n";
    exec($cmd, $out, $code);
    echo implode("\n", $out) . "\n";
    if ($code !== 0) {
        fwrite(STDERR, "FAILED code={$code}. 请用管理员 PowerShell 再跑本脚本。\n");
        exit(1);
    }
    echo "OK Windows scheduled task [{$task}] every 1 minute (hidden, no console popup).\n";
    echo "Test: schtasks /Run /TN {$task}\n";
    exit(0);
}

$line = '* * * * * cd ' . escapeshellarg($root) . ' && ' . escapeshellarg($php)
    . ' think fanshub:uid-sugar >> ' . escapeshellarg($log) . ' 2>&1';
$marker = 'fanshub:uid-sugar';
$existing = [];
exec('crontab -l 2>/dev/null', $existing);
$keep = [];
foreach ($existing as $row) {
    if (strpos($row, $marker) !== false) {
        continue;
    }
    $keep[] = $row;
}
$keep[] = $line;
$tmp = tempnam(sys_get_temp_dir(), 'cron');
file_put_contents($tmp, implode("\n", $keep) . "\n");
exec('crontab ' . escapeshellarg($tmp), $out, $code);
@unlink($tmp);
echo implode("\n", $out) . "\n";
if ($code !== 0) {
    fwrite(STDERR, "FAILED code={$code}. 请手动加入 crontab:\n{$line}\n");
    exit(1);
}
echo "OK crontab installed:\n{$line}\n";
exit(0);
