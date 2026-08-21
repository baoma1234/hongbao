<?php
/**
 * 安装 BS 主商户汇率每日同步（凌晨 00:05）
 *
 * Windows：计划任务 FansHubBsRates
 * Linux：  crontab 5 0 * * * php think fanshub:bs-rates
 *
 * 用法：php scripts/install_bs_rates_cron.php
 */
$root = str_replace('/', DIRECTORY_SEPARATOR, dirname(__DIR__));
$php = PHP_BINARY ?: 'php';
$think = $root . DIRECTORY_SEPARATOR . 'think';
$logDir = $root . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'log';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$log = $logDir . DIRECTORY_SEPARATOR . 'bs_rates.log';

echo "php={$php}\n";
echo "cmd={$php} {$think} fanshub:bs-rates\n";

if (stripos(PHP_OS, 'WIN') === 0) {
    $task = 'FansHubBsRates';
    $tr = $php . ' ' . $think . ' fanshub:bs-rates';
    exec('schtasks /Delete /TN "' . $task . '" /F 2>NUL');
    // 每天 00:05
    $cmd = 'schtasks /Create /TN "' . $task . '" /TR "' . $tr . '" /SC DAILY /ST 00:05 /RL LIMITED /F';
    echo "run: {$cmd}\n";
    exec($cmd, $out, $code);
    echo implode("\n", $out) . "\n";
    if ($code !== 0) {
        fwrite(STDERR, "FAILED code={$code}. 请用管理员 PowerShell 再跑本脚本。\n");
        exit(1);
    }
    echo "OK Windows scheduled task [{$task}] daily at 00:05.\n";
    echo "Test: schtasks /Run /TN {$task}\n";
    exit(0);
}

$line = '5 0 * * * cd ' . escapeshellarg($root) . ' && ' . escapeshellarg($php)
    . ' think fanshub:bs-rates >> ' . escapeshellarg($log) . ' 2>&1';
$marker = 'fanshub:bs-rates';
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
