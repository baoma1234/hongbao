<?php
/**
 * Workerman / PHP 日志落盘控制（防止 vendor/workerman/workerman.log 撑爆磁盘）
 *
 * - 默认 Workerman::$logFile 在 vendor/workerman/workerman.log，且永不轮转
 * - 若 PHP error_log 指到同一文件，或 -d 后 stdoutFile 指到该文件，红包热路径 error_log 会滚到数百 GB
 *
 * 在 vendor/autoload.php 之后、Worker::runAll() 之前 require 本文件。
 */

use Workerman\Worker;

$imRuntimeDir = __DIR__ . '/runtime';
$imRuntimeLogDir = $imRuntimeDir . '/log';
if (!is_dir($imRuntimeLogDir)) {
    @mkdir($imRuntimeLogDir, 0775, true);
}

$script = basename((string)($_SERVER['SCRIPT_FILENAME'] ?? 'im'));
$tag = preg_replace('/[^a-zA-Z0-9_-]+/', '_', pathinfo($script, PATHINFO_FILENAME)) ?: 'im';

$workermanLog = $imRuntimeLogDir . '/workerman-' . $tag . '.log';
$phpErrorLog = $imRuntimeLogDir . '/php-error-' . $tag . '.log';
// 固定 pid，避免默认写到 vendor/ 且 stop/restart 找不到旧 master
Worker::$pidFile = $imRuntimeDir . '/workerman-' . $tag . '.pid';
Worker::$statusFile = $imRuntimeDir . '/workerman-' . $tag . '.status';

/**
 * 超过上限则截断（启动时一次；避免无 logrotate 时再次涨死盘）。
 */
$capLogFile = static function (string $path, int $maxBytes = 33554432) {
    if ($path === '' || !is_file($path)) {
        return;
    }
    $size = @filesize($path);
    if ($size !== false && $size > $maxBytes) {
        @file_put_contents($path, '');
    }
};

$capLogFile($workermanLog);
$capLogFile($phpErrorLog);

// 顺带清掉误写在 vendor 下的巨型默认日志（不删除 inode，避免进程仍占着句柄时出问题：先 truncate）
$legacyVendorLog = __DIR__ . '/vendor/workerman/workerman.log';
if (is_file($legacyVendorLog)) {
    $legacySize = @filesize($legacyVendorLog);
    // > 64MB 即截断；500GB 场景下启动即释放磁盘
    if ($legacySize !== false && $legacySize > 67108864) {
        $fh = @fopen($legacyVendorLog, 'c');
        if ($fh) {
            @ftruncate($fh, 0);
            @fclose($fh);
        } else {
            @file_put_contents($legacyVendorLog, '');
        }
    }
}

Worker::$logFile = $workermanLog;
// 守护模式下 PHP error_log / echo 走 stderr → 必须进 /dev/null，切勿指到 logFile
if (DIRECTORY_SEPARATOR === '/') {
    Worker::$stdoutFile = '/dev/null';
}

@ini_set('log_errors', '1');
@ini_set('error_log', $phpErrorLog);

unset($imRuntimeDir, $imRuntimeLogDir, $script, $tag, $workermanLog, $phpErrorLog, $capLogFile, $legacyVendorLog, $legacySize, $fh);
