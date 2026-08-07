# P2 一键测试（Windows / 宝塔 PHP 常禁 passthru）
#   powershell -File im-server/scripts/test_p2_all.ps1

$ErrorActionPreference = 'Continue'
$root = Split-Path -Parent $PSScriptRoot
$fail = 0

Write-Host "=== 1) push_wake ==="
& php (Join-Path $PSScriptRoot 'test_p2_push_wake.php')
if ($LASTEXITCODE -ne 0) { $fail++ }

Write-Host ""
Write-Host "=== 2) reconnect backoff ==="
& node (Join-Path $PSScriptRoot 'test_p2_reconnect_backoff.mjs')
if ($LASTEXITCODE -ne 0) { $fail++ }

Write-Host ""
Write-Host "=== P2 total fail=$fail ==="
if ($fail -eq 0) {
    Write-Host "OK. Storm spread: node im-server/scripts/test_p2_reconnect_backoff.mjs --storm=5000"
}
exit $fail
