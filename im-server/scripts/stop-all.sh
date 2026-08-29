#!/usr/bin/env bash
# FansHub IM — stop all (WS + HTTP + Cron)
# 会清掉 root/www 两套残留，避免「双 master 抢端口」
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

PHP_BIN="${PHP_BIN:-php}"

echo "[STOP] graceful php stop ..."
for file in start.php start_admin.php start_cron.php; do
  if [[ -f "$file" ]]; then
    "$PHP_BIN" "$file" stop 2>/dev/null || true
    # 若当前是 root，再尝试以 www 停一次（pid 可能属于 www）
    if [[ "$(id -u)" -eq 0 ]] && id www >/dev/null 2>&1; then
      sudo -u www "$PHP_BIN" "$file" stop 2>/dev/null || true
    fi
  fi
done
sleep 1

kill_pat() {
  local pat="$1"
  pkill -f "$pat" 2>/dev/null || true
}

echo "[STOP] kill leftover masters/workers ..."
# Workerman 守护后命令行变成: start_file=/path/start.php
kill_pat "start_file=${ROOT}/start.php"
kill_pat "start_file=${ROOT}/start_admin.php"
kill_pat "start_file=${ROOT}/start_cron.php"
# 兼容仍带 php 命令行的进程
kill_pat "${ROOT}/start.php"
kill_pat "${ROOT}/start_admin.php"
kill_pat "${ROOT}/start_cron.php"
sleep 1

# 仍占端口则对监听进程发 TERM/KILL
free_port() {
  local port="$1"
  local pids
  pids="$(ss -lntp 2>/dev/null | awk -v p=":${port}" '$4 ~ p {print}' | grep -oE 'pid=[0-9]+' | cut -d= -f2 | sort -u || true)"
  if [[ -z "${pids}" ]]; then
    return 0
  fi
  echo "[STOP] port ${port} still held by: ${pids}"
  for pid in $pids; do
    kill "$pid" 2>/dev/null || true
  done
  sleep 1
  pids="$(ss -lntp 2>/dev/null | awk -v p=":${port}" '$4 ~ p {print}' | grep -oE 'pid=[0-9]+' | cut -d= -f2 | sort -u || true)"
  for pid in $pids; do
    kill -9 "$pid" 2>/dev/null || true
  done
}

free_port 17272
free_port 17273

# 顽固 master 再强杀一次
pkill -9 -f "start_file=${ROOT}/start.php" 2>/dev/null || true
pkill -9 -f "start_file=${ROOT}/start_admin.php" 2>/dev/null || true
pkill -9 -f "start_file=${ROOT}/start_cron.php" 2>/dev/null || true

rm -f "${ROOT}"/*.pid 2>/dev/null || true
sleep 1

left="$(ps aux 2>/dev/null | grep -E "start_file=${ROOT}/start" | grep -v grep || true)"
if [[ -n "$left" ]]; then
  echo "[WARN] still running:"
  echo "$left"
else
  echo "[OK] no IM masters left"
fi

ports="$(ss -lntp 2>/dev/null | grep -E ':1727[23]\b' || true)"
if [[ -n "$ports" ]]; then
  echo "[WARN] ports still listening:"
  echo "$ports"
else
  echo "[OK] 17272/17273 free"
fi
