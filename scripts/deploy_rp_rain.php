<?php
/**
 * 线上/本机一键补齐「红宝雨机器人」：表 + 菜单 + 清侧栏缓存
 * 使用前请先 git pull。
 * php scripts/deploy_rp_rain.php
 *
 * 完成后务必重启 IM：bash im-server/scripts/restart-all.sh
 * （Windows: powershell -File im-server/scripts/restart-all.ps1）
 */
$root = dirname(__DIR__);
passthru('php ' . escapeshellarg($root . '/scripts/install_rp_rain.php'), $code1);
passthru('php ' . escapeshellarg($root . '/scripts/migrate_rp_rain_sequential.php'), $codeSeq);
passthru('php ' . escapeshellarg($root . '/scripts/migrate_rp_rain_schedule_mode.php'), $codeMode);
passthru('php ' . escapeshellarg($root . '/scripts/migrate_rp_rain_bot_grab_pct.php'), $codePct);
passthru('php ' . escapeshellarg($root . '/scripts/clear_admin_menu_cache.php'), $code2);

$need = [
    'im-server/App/Service/RpRainBotService.php',
    'im-server/start_cron.php',
    'application/admin/controller/fanshub/Redpacketrain.php',
    'public/assets/js/backend/fanshub/redpacketrain.js',
];
echo "---- file check ----\n";
$miss = 0;
foreach ($need as $rel) {
    $ok = is_file($root . '/' . $rel);
    echo ($ok ? 'OK  ' : 'MISS') . ' ' . $rel . "\n";
    if (!$ok) {
        $miss++;
    }
}
if ($miss > 0) {
    fwrite(STDERR, "缺少文件：请先在该服务器 git pull origin main\n");
    exit(1);
}

echo "---- next ----\n";
echo "1) 强制刷新后台或重新登录，玩法大全下应见「红宝雨机器人」\n";
echo "2) 重启聊天服务以加载 RpRainBotService\n";
echo "   Linux: bash im-server/scripts/restart-all.sh\n";
echo "   Win:   powershell -File im-server/scripts/restart-all.ps1\n";
exit(($code1 === 0 && $codeSeq === 0 && $codeMode === 0 && $codePct === 0 && $code2 === 0) ? 0 : 1);
