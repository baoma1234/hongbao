# FansHub IM — start WebSocket + HTTP API + Cron
# Usage: .\scripts\start-all.ps1 [-PhpPath "C:\BtSoft\php\81\php.exe"]

param(
    [string]$PhpPath = ""
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root

function Resolve-Php {
    param([string]$Hint)
    if ($Hint -and (Test-Path $Hint)) { return (Resolve-Path $Hint).Path }
    $candidates = @(
        "C:\BtSoft\php\81\php.exe",
        "C:\BtSoft\php\80\php.exe",
        "C:\php\php.exe",
        "php"
    )
    foreach ($c in $candidates) {
        if ($c -eq "php") {
            $cmd = Get-Command php -ErrorAction SilentlyContinue
            if ($cmd) { return $cmd.Source }
            continue
        }
        if (Test-Path $c) { return $c }
    }
    throw "PHP not found. Pass -PhpPath or install PHP 8.1+."
}

$php = Resolve-Php $PhpPath
Write-Host "PHP: $php"
Write-Host "DIR: $Root"

$jobs = @(
    @{ Name = "WS";   File = "start.php";       Port = 7272 },
    @{ Name = "HTTP"; File = "start_admin.php"; Port = 7273 },
    @{ Name = "CRON"; File = "start_cron.php";  Port = 0 }
)

foreach ($j in $jobs) {
    $running = Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -and ($_.CommandLine -match [regex]::Escape($j.File)) }
    if ($running) {
        Write-Host "[SKIP] $($j.Name) already running (pid=$(($running | Select-Object -First 1).ProcessId))"
        continue
    }
    Start-Process -FilePath $php -ArgumentList $j.File, "start" -WorkingDirectory $Root -WindowStyle Hidden
    Write-Host "[START] $($j.Name) -> $($j.File)"
}

Start-Sleep -Seconds 2
& "$PSScriptRoot\status.ps1"
