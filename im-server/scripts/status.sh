#!/usr/bin/env bash
# FansHub IM — status
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

echo "=== Processes ==="
ps aux 2>/dev/null | grep -E '[p]hp .*(start\.php|start_admin\.php|start_cron\.php)' || echo "(none)"

echo ""
echo "=== Listen ports ==="
(ss -lntp 2>/dev/null || netstat -lntp 2>/dev/null || true) | grep -E ':1727[23]\b' || echo "(17272/17273 not listening)"

echo ""
echo "=== Health (17273) ==="
curl -sS --max-time 3 http://127.0.0.1:17273/health || echo "HTTP health failed"
echo
