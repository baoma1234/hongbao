#!/usr/bin/env bash
# FansHub IM — status
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

echo "=== WorkerMan masters ==="
ps aux 2>/dev/null | grep -E "start_file=${ROOT}/start" | grep -v grep || echo "(none)"

echo ""
echo "=== php cmdline (non-daemon leftover) ==="
ps aux 2>/dev/null | grep -E "[p]hp .*(start\.php|start_admin\.php|start_cron\.php)" || echo "(none)"

echo ""
echo "=== Listen ports ==="
(ss -lntp 2>/dev/null || netstat -lntp 2>/dev/null || true) | grep -E ':1727[23]\b' || echo "(17272/17273 not listening)"

echo ""
echo "=== master count ==="
for f in start.php start_admin.php start_cron.php; do
  n="$(ps aux 2>/dev/null | grep -F "start_file=${ROOT}/${f}" | grep -v grep | grep -c 'master process' || true)"
  echo "  ${f}: ${n:-0}"
done

echo ""
echo "=== Health (17273) ==="
curl -sS --max-time 3 http://127.0.0.1:17273/health || echo "HTTP health failed"
echo

echo ""
echo "=== Deep probe (CLI) ==="
PROBE="$(cd "$ROOT/.." && pwd)/scripts/im_health_probe.php"
if [[ -f "$PROBE" ]]; then
  php "$PROBE" || true
else
  echo "(scripts/im_health_probe.php missing)"
fi
