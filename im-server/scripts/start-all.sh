#!/usr/bin/env bash
# FansHub IM — start WS + HTTP + Cron（daemon）
# - 强制只起一套；若已有 master 则跳过
# - root 执行时自动降权为 www，避免 root/www 双开抢端口
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

PHP_BIN="${PHP_BIN:-php}"
if [[ -n "${1:-}" && -x "$1" ]]; then
  PHP_BIN="$1"
fi

# root 下默认用 www 跑（可用 IM_USER=root 强制不降权）
RUN_USER="${IM_USER:-}"
if [[ -z "$RUN_USER" ]]; then
  if [[ "$(id -u)" -eq 0 ]] && id www >/dev/null 2>&1; then
    RUN_USER="www"
  fi
fi

run_php() {
  if [[ -n "$RUN_USER" && "$(id -u)" -eq 0 ]]; then
    sudo -u "$RUN_USER" "$PHP_BIN" "$@"
  else
    "$PHP_BIN" "$@"
  fi
}

master_count() {
  local file="$1"
  ps aux 2>/dev/null | grep -F "start_file=${ROOT}/${file}" | grep -v grep | grep -c 'master process' || true
}

start_one() {
  local file="$1"
  local name="$2"
  local n
  n="$(master_count "$file")"
  if [[ "${n:-0}" -gt 0 ]]; then
    echo "[SKIP] $name already has ${n} master(s)"
    if [[ "${n}" -gt 1 ]]; then
      echo "[ERR] duplicate masters for $file — run scripts/stop-all.sh first"
      return 1
    fi
    return 0
  fi
  run_php "$file" start -d
  echo "[START] $name -> $file (user=${RUN_USER:-$(id -un)})"
  sleep 0.5
  n="$(master_count "$file")"
  if [[ "${n:-0}" -eq 0 ]]; then
    echo "[ERR] $name failed to start"
    return 1
  fi
  if [[ "${n}" -gt 1 ]]; then
    echo "[ERR] $name started but duplicate masters detected (${n})"
    echo "      多半是宝塔/supervisor 又拉起了一份。请关掉那边的 start.php 守护，只保留本脚本。"
    return 1
  fi
}

# 启动前若 17272 已被占且无本目录 master，先警告
if ss -lntp 2>/dev/null | grep -q ':17272\b'; then
  if [[ "$(master_count start.php)" -eq 0 ]]; then
    echo "[ERR] 17272 in use by unknown process; refuse to start. Fix with stop-all.sh"
    ss -lntp | grep ':17272\b' || true
    exit 1
  fi
fi

start_one start.php FansHubIM-WS
start_one start_admin.php FansHubIM-HTTP
start_one start_cron.php FansHubIM-Cron

sleep 1
# 最终校验：WS 只能有 1 个 master
ws_n="$(master_count start.php)"
if [[ "${ws_n:-0}" -gt 1 ]]; then
  echo "[ERR] FansHubIM-WS has ${ws_n} masters — kill extras:"
  ps aux | grep -F "start_file=${ROOT}/start.php" | grep -v grep || true
  exit 1
fi

bash "$(dirname "$0")/status.sh"
