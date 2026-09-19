<#
    SoftCora POS — health check.

    Run after an install or an update. It answers the only questions that matter
    at a till: is the database there, is any sale still waiting to go up, and is
    the device still registered.
#>
[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'

$Root   = Join-Path $env:LOCALAPPDATA 'SoftCoraPOS'
$AppDir = Join-Path $Root 'app'
$Data   = Join-Path $Root 'data'
$Exe    = Join-Path $AppDir 'SoftCora-POS.exe'

Write-Host ''
Write-Host 'SoftCora POS — health check'
Write-Host '---------------------------'

if (-not (Test-Path $Exe)) { Write-Host "  ! The program is not installed at $AppDir" -ForegroundColor Red; exit 1 }

$db = Join-Path $Data 'softcora-pos.sqlite'
if (Test-Path $db) {
    $size = [Math]::Round((Get-Item $db).Length / 1KB, 1)
    Write-Host "  database      : $db ($size KB)"
} else {
    Write-Host '  database      : not created yet (the till creates it on first start)'
}

$device = Join-Path $Data 'device.json'
if (Test-Path $device) {
    $identity = Get-Content $device -Raw | ConvertFrom-Json
    Write-Host "  device id     : $($identity.device_id)"
    Write-Host "  registered    : $(if ($identity.device_token) { 'yes' } else { 'no — activate it in Settings' })"
} else {
    Write-Host '  device id     : not set yet — open Settings → This till'
}

$env:SOFTCORA_DATA = $Data
$env:SOFTCORA_HOST = '127.0.0.1'

Write-Host ''
Write-Host '  sync state:' -NoNewline
Write-Host ''
& $Exe --cli status

$backups = Join-Path $Data 'backups'
if (Test-Path $backups) {
    $latest = Get-ChildItem $backups -File | Sort-Object LastWriteTime -Descending | Select-Object -First 1
    if ($latest) { Write-Host "  last backup   : $($latest.Name) ($([Math]::Round($latest.Length / 1KB, 1)) KB)" }
}

Write-Host ''
Write-Host '  A "pending" count above is not an error while the shop is offline:'
Write-Host '  those sales are stored locally and will be sent by the next Sync Now.'
Write-Host ''
