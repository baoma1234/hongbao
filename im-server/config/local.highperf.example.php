<?php
/**
 * 高配机示例（80 核 / 64G）— 复制为 local.php 后按需改
 * cp config/local.highperf.example.php config/local.php
 *
 * 注意：app.php 默认会按 nproc 把 WS 拉到 ≈80。
 * 80 核机上「WS=80」容易打满 MySQL 连接、放大上下文切换。
 * 建议从下方冲高档起步，压测后再升降。
 */
return [
    'websocket' => [
        // 稳妥 16～24；冲高 32～40；压测上限 48～64。勿一上来 80。
        'count' => 32,
        'reuse_port' => true,
    ],
    'http_api' => [
        // 约 WS 的 1/3～1/2，且保持 ≤32（避免连接过多）
        'count' => 12,
        'reuse_port' => true,
    ],
    'push' => [
        'drain_interval' => 0.03,
        'drain_batch'    => 2000,
    ],
    'cron' => [
        // 尖峰可略降 Tron 频率：3～5
        'tron_poll_interval' => 1,
        'settle_interval'    => 2,
        'settle_limit'       => 30,
    ],
];
