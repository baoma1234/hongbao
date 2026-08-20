#!/usr/bin/env bash
# 紧急释放 Workerman 默认巨石日志占用的磁盘（可在服务运行时执行）
# 用法：bash scripts/truncate_workerman_log.sh [/path/to/im-server]

set -euo pipefail
ROOT="${1:-$(cd "$(dirname "$0")/.." && pwd)/im-server}"
FILE="$ROOT/vendor/workerman/workerman.log"

if [[ ! -e "$FILE" ]]; then
  echo "not found: $FILE"
  exit 0
fi

SIZE=$(du -sh "$FILE" 2>/dev/null | awk '{print $1}')
echo "before: $FILE ($SIZE)"
# truncate 同 inode，正在 append 的进程不会丢句柄、也不会马上再占满已释放空间之外的块
: > "$FILE"
sync
echo "after:  $(du -sh "$FILE" 2>/dev/null | awk '{print $1}')"
echo "done. 建议接着: systemctl restart fanshub-im-ws fanshub-im-cron fanshub-im-http"
