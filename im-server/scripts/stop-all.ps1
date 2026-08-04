# FansHub IM — stop WS / HTTP / Cron
$ErrorActionPreference = "Continue"

$killed = @{}

Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction SilentlyContinue | ForEach-Object {
    $cl = [string]$_.CommandLine
    if (-not $cl) { return }
    if ($cl -match '(^|[\\/\s])start(_admin|_cron)?\.php(\s|$)') {
        try {
            Stop-Process -Id $_.ProcessId -Force -ErrorAction Stop
            $killed[$_.ProcessId] = $true
            Write-Host "[KILL] $($_.ProcessId) $cl"
        } catch {
            Write-Host "[FAIL] $($_.ProcessId) $($_.Exception.Message)"
        }
    }
}

netstat -ano 2>$null | ForEach-Object {
    if ($_ -match ':727[23]\s+.*LISTENING\s+(\d+)\s*$') {
        $listenPid = [int]$Matches[1]
        if (-not $killed.ContainsKey($listenPid)) {
            try {
                Stop-Process -Id $listenPid -Force -ErrorAction Stop
                $killed[$listenPid] = $true
                Write-Host "[KILL-PORT] $listenPid"
            } catch {}
        }
    }
}

if ($killed.Count -eq 0) {
    Write-Host "[OK] no IM processes found"
} else {
    Write-Host "[OK] stopped $($killed.Count) process(es)"
}
