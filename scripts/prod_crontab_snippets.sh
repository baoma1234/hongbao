#!/usr/bin/env bash
# 生产日检 crontab 行（以 root 或 www 用户安装）
# crontab -e 后粘贴（路径按站点改）：
#
# 每天 00:05 同步 BS USDT 主商户代收/代付汇率
# 5 0 * * * cd /www/wwwroot/hbsq.bio && /usr/bin/php think fanshub:bs-rates >> runtime/log/bs_rates.log 2>&1
# 每 5 分钟健康+对账（失败 exit 1，可接告警）
# */5 * * * * cd /www/wwwroot/hbsq.bio && /usr/bin/php scripts/im_health_probe.php >/dev/null 2>&1
# 每天 03:40 对账
# 40 3 * * * cd /www/wwwroot/hbsq.bio && /usr/bin/php scripts/reconcile_hongbao_ledger.php >> runtime/log/reconcile.log 2>&1
# 每天 04:10 清理 30 天前管理员日志
# 10 4 * * * cd /www/wwwroot/hbsq.bio && /usr/bin/php scripts/cleanup_admin_log.php --days=30 --execute >> runtime/log/admin_log_cleanup.log 2>&1
# 每月 1 号 03:20 流水冷归档（90 天）
# 20 3 1 * * cd /www/wwwroot/hbsq.bio && /usr/bin/php scripts/archive_fans_ledger.php --days=90 --execute >> runtime/log/ledger_archive.log 2>&1

echo "See comments in $0 for crontab lines."
