#!/usr/bin/env bash
# 80C/64G CentOS 生产：写入/合并 IM local.php 进程数（不覆盖已有 DB/Redis 配置）
# Usage:
#   cd /www/wwwroot/hbsq.bio
#   bash scripts/prod_merge_im_workers.sh          # dry-run
#   bash scripts/prod_merge_im_workers.sh --apply  # 写入后需重启 IM
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LOCAL="$ROOT/im-server/config/local.php"
APPLY=0
[[ "${1:-}" == "--apply" ]] && APPLY=1

WS=32
HTTP=12

echo "ROOT=$ROOT"
echo "target websocket.count=$WS http_api.count=$HTTP"

if [[ ! -f "$LOCAL" ]]; then
  echo "local.php 不存在 → 将从 highperf 示例复制（仅进程数；DB 仍走 .env）"
  if [[ "$APPLY" -eq 1 ]]; then
    cp "$ROOT/im-server/config/local.highperf.example.php" "$LOCAL"
    echo "OK wrote $LOCAL"
  else
    echo "DRY-RUN: cp im-server/config/local.highperf.example.php → config/local.php"
  fi
  echo "然后: cd im-server/scripts && ./restart-all.sh"
  exit 0
fi

echo "local.php 已存在。请手工保证含："
cat <<EOF
return [
  // ...保留你的 db/redis/admin_key...
  'websocket' => ['count' => $WS, 'reuse_port' => true],
  'http_api'  => ['count' => $HTTP, 'reuse_port' => true],
];
EOF
echo
echo "当前 local.php 片段："
grep -n "count\|websocket\|http_api" "$LOCAL" || true
echo
echo "改完后: cd $ROOT/im-server/scripts && ./restart-all.sh && ./status.sh"
echo "探针: php $ROOT/scripts/im_health_probe.php"
