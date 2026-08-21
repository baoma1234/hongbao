#!/bin/bash
# Daily BS USDT main-merchant exchange rate sync (collection + payment)
# Crontab example (server local time 00:05):
#   5 0 * * * /path/to/project/scripts/cron_bs_rates.sh >> /path/to/project/runtime/log/bs_rates.log 2>&1
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT" || exit 1
php think fanshub:bs-rates
