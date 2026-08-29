#!/usr/bin/env bash
# FansHub IM — restart all
# 用法: bash scripts/restart-all.sh
# 注意: 宝塔「进程守护」不要再守护 start.php，否则会和本脚本抢成双 master
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$DIR/stop-all.sh" || true
sleep 2
bash "$DIR/start-all.sh" "$@"
# 再扫一眼双 master
ROOT="$(cd "$DIR/.." && pwd)"
n="$(ps aux 2>/dev/null | grep -F "start_file=${ROOT}/start.php" | grep -v grep | grep -c 'master process' || true)"
if [[ "${n:-0}" -gt 1 ]]; then
  echo ""
  echo "[ERR] 仍有 ${n} 个 start.php master。请检查宝塔/supervisor 是否在自动拉起，并关掉重复守护。"
  ps aux | grep -F "start_file=${ROOT}/start.php" | grep -v grep || true
  exit 1
fi
echo "[OK] restart done (start.php masters=${n:-0})"
