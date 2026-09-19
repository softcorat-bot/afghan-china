<#
    SoftCora POS — per-user uninstall.

    Removes the program, the shortcuts and the registry entry.

    It does NOT remove the data folder unless you ask for it with -RemoveData:
    the till's database holds sales that may not have reached the server yet, and
    an uninstall is not a reason to lose them. The folder is printed so it can be
    copied away or backed up first.
#>
[CmdletBinding()]
param(
    [switch]$RemoveData,
    [switch]$Silent
)

$ErrorActionPreference = 'Stop'

$Root    = Join-Path $env:LOCALAPPDATA 'SoftCoraPOS'
$AppDir  = Join-Path $Root 'app'
$DataDir = Join-Path $Root 'data'
$Product = 'SoftCora POS'

function Say($text) { if (-not $Silent) { Write-Host $text } }

$aRunning = Get-Process -Name 'SoftCora-POS' -ErrorAction SilentlyContinue
if ($aRunning) {
    Say 'Stopping the till…'
    $aRunning | Stop-Process -Force
    Start-Sleep -Seconds 1
}

Say 'Removing shortcuts…'
$shell = New-Object -ComObject WScript.Shell
$targets = @(
    (Join-Path ([Environment]::GetFolderPath('Programs')) 'SoftCora POS'),
    (Join-Path ([Environment]::GetFolderPath('Desktop')) 'SoftCora POS.lnk'),
    (Join-Path ([Environment]::GetFolderPath('Startup')) 'SoftCora POS.lnk')
)

foreach ($target in $targets) {
    if (Test-Path $target) { Remove-Item $target -Recurse -Force -ErrorAction SilentlyContinue }
}

Say 'Removing the app registration…'
$uninstallKey = 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Uninstall\SoftCoraPOS'
if (Test-Path $uninstallKey) { Remove-Item $uninstallKey -Recurse -Force }

# Never delete a sale: the data folder survives unless explicitly asked.
if ($RemoveData) {
    Say "Deleting the data folder at $DataDir (as requested)…"
    if (Test-Path $DataDir) { Remove-Item $DataDir -Recurse -Force }
}

Say 'Removing program files…'
if (Test-Path $AppDir) { Remove-Item $AppDir -Recurse -Force }
if ((Test-Path $Root) -and -not (Test-Path $DataDir)) {
    $remaining = Get-ChildItem $Root -Force -ErrorAction SilentlyContinue
    if (-not $remaining) { Remove-Item $Root -Force }
}

Say ''
if (Test-Path $DataDir) {
    Say "$Product has been removed."
    Say "Your data was kept here: $DataDir"
} else {
    Say "$Product has been removed, including its data."
}
Say ''
