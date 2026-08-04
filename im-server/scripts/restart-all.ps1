# FansHub IM — restart all
$ErrorActionPreference = "Continue"
$ScriptDir = $PSScriptRoot
& "$ScriptDir\stop-all.ps1"
Start-Sleep -Seconds 2
& "$ScriptDir\start-all.ps1" @args
