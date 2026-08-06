<?php
/**
 * 高配机示例（80 核 / 64G）— 复制为 local.php 后按需改
 * cp config/local.highperf.example.php config/local.php
 *
 * 默认 app.php 已按 nproc 自动拉满；本文件用于手动封顶/微调。
 */
return [
    'websocket' => [
        // 与 CPU 核数对齐；若 MySQL max_connections 不够可降到 48～64
        'count' => 80,
        'reuse_port' => true,
    ],
    'http_api' => [
        'count' => 20,
        'reuse_port' => true,
    ],
    'push' => [
        'drain_interval' => 0.01,
        'drain_batch'    => 2000,
    ],
];
