<?php
/**
 * 安装 BS 主商户汇率每日同步（凌晨 00:05）
 *
 * Windows：计划任务 FansHubBsRates
 * Linux：  crontab 5 0 * * * php think fanshub:bs-rates
 *
 * 用法：
 *   php scripts/install_bs_rates_cron.php
 *   php scripts/install_bs_rates_cron.php --no-run   # 只装定时，不立刻跑
 */
$root = str_replace('/', DIRECTORY_SEPARATOR, dirname(__DIR__));
$php = PHP_BINARY ?: 'php';
$think = $root . DIRECTORY_SEPARATOR . 'think';
$logDir = $root . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'log';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$log = $logDir . DIRECTORY_SEPARATOR . 'bs_rates.log';
$doRun = !in_array('--no-run', $argv, true);

echo "php={$php}\n";
echo "cmd={$php} {$think} fanshub:bs-rates\n";
echo "log={$log}\n";

if (!is_file($log)) {
    file_put_contents($log, '# BS rates log created ' . date('Y-m-d H:i:s') . "\n");
    echo "created empty log file\n";
}

function runOnce($php, $think, $log, $root)
{
    echo "Running once now...\n";
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($think) . ' fanshub:bs-rates';
    $cwd = getcwd();
    chdir($root);
    $lines = [];
    exec($cmd . ' 2>&1', $lines, $code);
    chdir($cwd);
    $block = '[' . date('Y-m-d H:i:s') . "] install-run exit={$code}\n"
        . implode("\n", $lines) . "\n";
    file_put_contents($log, $block, FILE_APPEND);
    echo implode("\n", $lines) . "\n";
    echo "wrote to log, exit={$code}\n";
    return $code;
}

if (stripos(PHP_OS, 'WIN') === 0) {
    $task = 'FansHubBsRates';
    $tr = $php . ' ' . $think . ' fanshub:bs-rates';
    exec('schtasks /Delete /TN "' . $task . '" /F 2>NUL');
    $cmd = 'schtasks /Create /TN "' . $task . '" /TR "' . $tr . '" /SC DAILY /ST 00:05 /RL LIMITED /F';
    echo "run: {$cmd}\n";
    exec($cmd, $out, $code);
    echo implode("\n", $out) . "\n";
    if ($code !== 0) {
        fwrite(STDERR, "FAILED code={$code}. 请用管理员 PowerShell 再跑本脚本。\n");
        exit(1);
    }
    echo "OK Windows scheduled task [{$task}] daily at 00:05.\n";
    if ($doRun) {
        runOnce($php, $think, $log, $root);
    }
    echo "Tip: cron/task only runs at 00:05; use --no-run to skip immediate sync.\n";
    echo "Test: schtasks /Run /TN {$task}\n";
    echo "Tail: type \"{$log}\"\n";
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
echo "NOTE: 定时是每天 00:05，安装当时不会自动写日志。\n";
if ($doRun) {
    $rc = runOnce($php, $think, $log, $root);
    echo "查看: tail -n 30 " . escapeshellarg($log) . "\n";
    exit($rc === 0 ? 0 : 1);
}
echo "跳过立即执行（--no-run）。手动测: cd {$root} && {$php} think fanshub:bs-rates\n";
exit(0);
