#!/usr/bin/env bash
# FansHub IM — start WS + HTTP + Cron (Linux daemon mode)
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

PHP_BIN="${PHP_BIN:-php}"
if [[ -n "${1:-}" && -x "$1" ]]; then
  PHP_BIN="$1"
fi

start_one() {
  local file="$1"
  local name="$2"
  if pgrep -f "$ROOT/$file" >/dev/null 2>&1 || pgrep -f "php .*${file}" >/dev/null 2>&1; then
    echo "[SKIP] $name already running"
    return
  fi
  "$PHP_BIN" "$file" start -d
  echo "[START] $name -> $file"
}

start_one start.php FansHubIM-WS
start_one start_admin.php FansHubIM-HTTP
start_one start_cron.php FansHubIM-Cron

sleep 1
bash "$(dirname "$0")/status.sh"
