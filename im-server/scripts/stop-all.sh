#!/usr/bin/env bash
# FansHub IM — stop all
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

PHP_BIN="${PHP_BIN:-php}"

for file in start.php start_admin.php start_cron.php; do
  if [[ -f "$file" ]]; then
    "$PHP_BIN" "$file" stop 2>/dev/null || true
  fi
done

# Fallback: kill leftover masters by command line
pkill -f "$ROOT/start.php" 2>/dev/null || true
pkill -f "$ROOT/start_admin.php" 2>/dev/null || true
pkill -f "$ROOT/start_cron.php" 2>/dev/null || true

echo "[OK] stop requested"
sleep 1
bash "$(dirname "$0")/status.sh" || true
