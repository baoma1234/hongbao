# Rebuild /888 chat CSS+JS bundles from source parts.
# Usage: powershell -File tools/build-888-chat.ps1
$ErrorActionPreference = 'Stop'
$root = Resolve-Path (Join-Path $PSScriptRoot '..')
$chatDir = Join-Path $root 'public\888\js\chat'
$cssDir = Join-Path $root 'public\888\css'
$jsParts = @('01-core.js','02-room.js','03-rp.js','04-net.js','05-community.js','06-notice.js')
$cssParts = @('chat-core.css','chat-luxury.css','chat-rp.css','chat-create-group.css','chat-community.css','chat-notice-feed.css','chat-glass-theme.css')

$sb = New-Object System.Text.StringBuilder
[void]$sb.AppendLine('(function (global) {')
[void]$sb.AppendLine('"use strict";')
foreach ($f in $jsParts) {
  $path = Join-Path $chatDir $f
  if (-not (Test-Path $path)) { throw "Missing $path" }
  [void]$sb.AppendLine("/* === $f === */")
  [void]$sb.AppendLine((Get-Content $path -Raw -Encoding UTF8))
}
[void]$sb.AppendLine('})(window);')
$jsOut = Join-Path $chatDir 'chat.bundle.js'
[System.IO.File]::WriteAllText($jsOut, $sb.ToString(), [System.Text.UTF8Encoding]::new($false))

$csb = New-Object System.Text.StringBuilder
foreach ($f in $cssParts) {
  $path = Join-Path $cssDir $f
  if (-not (Test-Path $path)) { throw "Missing $path" }
  [void]$csb.AppendLine("/* === $f === */")
  [void]$csb.AppendLine((Get-Content $path -Raw -Encoding UTF8))
}
$cssOut = Join-Path $cssDir 'chat.bundle.css'
[System.IO.File]::WriteAllText($cssOut, $csb.ToString(), [System.Text.UTF8Encoding]::new($false))

Write-Host ("OK  {0:N1} KB  chat.bundle.js" -f ((Get-Item $jsOut).Length / 1KB))
Write-Host ("OK  {0:N1} KB  chat.bundle.css" -f ((Get-Item $cssOut).Length / 1KB))
