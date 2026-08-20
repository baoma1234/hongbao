#!/usr/bin/env bash
# 清理 IM 端口占用后干净重启（解决 Address already in use）
# 用法：bash scripts/restart_im_clean.sh [/www/wwwroot/hbsq.bio/im-server]

set -euo pipefail
ROOT="${1:-/www/wwwroot/hbsq.bio/im-server}"
cd "$ROOT"

echo "== stop systemd =="
systemctl stop fanshub-im-ws fanshub-im-cron fanshub-im-http 2>/dev/null || true

echo "== workerman stop =="
/usr/bin/php start.php stop 2>/dev/null || true
/usr/bin/php start_cron.php stop 2>/dev/null || true
/usr/bin/php start_admin.php stop 2>/dev/null || true
sleep 1

echo "== kill leftovers on 17272/17273 =="
# 仍占用则强杀
if command -v fuser >/dev/null 2>&1; then
  fuser -k 17272/tcp 2>/dev/null || true
  fuser -k 17273/tcp 2>/dev/null || true
fi
pkill -f '/www/wwwroot/.*/im-server/start(_cron|_admin)?\.php' 2>/dev/null || true
pkill -f "$ROOT/start.php" 2>/dev/null || true
pkill -f "$ROOT/start_cron.php" 2>/dev/null || true
pkill -f "$ROOT/start_admin.php" 2>/dev/null || true
sleep 1

echo "== ports now =="
ss -lntp 2>/dev/null | grep -E ':1727[23]\b' || echo "(free)"

echo "== truncate noisy vendor log =="
: > "$ROOT/vendor/workerman/workerman.log" 2>/dev/null || true

echo "== start systemd =="
systemctl daemon-reload
systemctl start fanshub-im-ws fanshub-im-cron fanshub-im-http
sleep 2
systemctl --no-pager --full status fanshub-im-ws fanshub-im-cron fanshub-im-http || true
ss -lntp 2>/dev/null | grep -E ':1727[23]\b' || echo "WARN: ports not listening"
echo "done."
