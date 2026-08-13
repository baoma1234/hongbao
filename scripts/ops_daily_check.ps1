# 日检：IM 健康 + 红宝流水对账
$ErrorActionPreference = "Continue"
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root
Write-Host "=== $(Get-Date -Format o) ops daily check ==="
& php scripts\im_health_probe.php
$h = $LASTEXITCODE
& php scripts\reconcile_hongbao_ledger.php
$r = $LASTEXITCODE
if ($h -ne 0 -or $r -ne 0) { exit 1 }
exit 0
