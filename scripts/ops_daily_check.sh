#!/usr/bin/env bash
# 日检：IM 健康 + 红宝流水对账
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
echo "=== $(date -Is) ops daily check ==="
php scripts/im_health_probe.php
h=$?
php scripts/reconcile_hongbao_ledger.php
r=$?
exit $(( h != 0 || r != 0 ? 1 : 0 ))
