<?php
/**
 * 安装 OG 投注记录定时抓取
 *
 * Windows：计划任务 FansHubOgBets 每分钟
 * Linux：  crontab * * * * * php think fanshub:og-bets
 *
 * 用法：php scripts/install_og_bets_cron.php
 */
$root = str_replace('/', DIRECTORY_SEPARATOR, dirname(__DIR__));
$php = PHP_BINARY ?: 'php';
$think = $root . DIRECTORY_SEPARATOR . 'think';
$logDir = $root . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'log';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$log = $logDir . DIRECTORY_SEPARATOR . 'og_bets_cron.log';

echo "php={$php}\n";
echo "cmd={$php} {$think} fanshub:og-bets\n";

if (stripos(PHP_OS, 'WIN') === 0) {
    $task = 'FansHubOgBets';
    $vbs = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'win_php_think_hidden.vbs';
    $tr = 'wscript.exe //B //Nologo "' . $vbs . '" "' . $php . '" "' . $think . '" fanshub:og-bets';
    exec('schtasks /Delete /TN "' . $task . '" /F 2>NUL');
    $cmd = 'schtasks /Create /TN "' . $task . '" /TR "' . $tr . '" /SC MINUTE /MO 1 /RL LIMITED /F';
    echo "run: {$cmd}\n";
    exec($cmd, $out, $code);
    echo implode("\n", $out) . "\n";
    if ($code !== 0) {
        fwrite(STDERR, "FAILED code={$code}. 请用管理员 PowerShell 再跑本脚本。\n");
        exit(1);
    }
    echo "OK Windows scheduled task [{$task}] every 1 minute (hidden).\n";
    echo "Test: schtasks /Run /TN {$task}\n";
    exit(0);
}

$line = '* * * * * cd ' . escapeshellarg($root) . ' && ' . escapeshellarg($php)
    . ' think fanshub:og-bets >> ' . escapeshellarg($log) . ' 2>&1';
$marker = 'fanshub:og-bets';
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
