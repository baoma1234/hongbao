#!/usr/bin/env bash
# CentOS cron 包装：清理 N 天前 IM 聊天 / 已结束红宝
# 用法：
#   bash scripts/cleanup_chat_cron.sh
#   DAYS=7 BATCH=2000 bash scripts/cleanup_chat_cron.sh
#   DRY_RUN=1 bash scripts/cleanup_chat_cron.sh   # 只统计不删除
#   PROTECT_GROUPS=70,71,72,77 bash scripts/cleanup_chat_cron.sh
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$DIR/.." && pwd)"
cd "$ROOT"

PHP_BIN="${PHP_BIN:-php}"
DAYS="${DAYS:-7}"
BATCH="${BATCH:-1000}"
PROTECT_GROUPS="${PROTECT_GROUPS:-70,71,72,77}"
LOG="${LOG:-/var/log/fanshub_chat_cleanup.log}"

ARGS=(scripts/cleanup_chat_older_than_days.php "--days=${DAYS}" "--batch=${BATCH}" "--protect-groups=${PROTECT_GROUPS}")
if [[ "${DRY_RUN:-0}" != "1" ]]; then
  ARGS+=(--execute)
fi

{
  echo "==== $(date '+%F %T') start ===="
  "$PHP_BIN" "${ARGS[@]}"
  echo "==== $(date '+%F %T') end ===="
} >>"$LOG" 2>&1
