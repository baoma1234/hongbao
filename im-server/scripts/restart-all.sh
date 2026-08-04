#!/usr/bin/env bash
# FansHub IM — restart all
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
bash "$DIR/stop-all.sh" || true
sleep 2
bash "$DIR/start-all.sh" "$@"
