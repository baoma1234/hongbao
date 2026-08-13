# FansHub IM — status
$ErrorActionPreference = "Continue"
$Root = Split-Path -Parent $PSScriptRoot

Write-Host "=== Processes ==="
$found = $false
Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction SilentlyContinue | ForEach-Object {
    $cl = [string]$_.CommandLine
    if ($cl -match "start\.php|start_admin\.php|start_cron\.php") {
        $found = $true
        Write-Host ("{0,6}  {1}" -f $_.ProcessId, $cl)
    }
}
if (-not $found) { Write-Host "(none)" }

Write-Host ""
Write-Host "=== Listen ports ==="
$ports = netstat -ano 2>$null | Select-String -Pattern ":1727[23]\s+.*LISTENING"
if ($ports) { $ports | ForEach-Object { $_.Line } } else { Write-Host "(17272/17273 not listening)" }

Write-Host ""
Write-Host "=== Health (17273) ==="
try {
    $r = Invoke-WebRequest -Uri "http://127.0.0.1:17273/health" -UseBasicParsing -TimeoutSec 3
    Write-Host $r.Content
} catch {
    Write-Host "HTTP health failed: $($_.Exception.Message)"
}

Write-Host ""
Write-Host "=== Deep probe (CLI) ==="
$probe = Join-Path (Split-Path -Parent $Root) "scripts\im_health_probe.php"
if (Test-Path $probe) {
    & php $probe
} else {
    Write-Host "(scripts/im_health_probe.php missing)"
}
