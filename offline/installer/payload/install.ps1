<#
    SoftCora POS — per-user installer.

    Installs the till into %LOCALAPPDATA%\SoftCoraPOS, creates the shortcuts and
    registers it in "Apps & features".

    Three rules this script exists to keep:
      * the data folder (database, device identity, outbox, backups) is never
        overwritten or deleted by an install — only created if missing;
      * updating means replacing program files, nothing else;
      * no administrator rights, no firewall rule, no reboot.
#>
[CmdletBinding()]
param(
    [switch]$SkipAutostart,
    [int]$Port = 7817
)

$ErrorActionPreference = 'Stop'

$Root      = Join-Path $env:LOCALAPPDATA 'SoftCoraPOS'
$AppDir    = Join-Path $Root 'app'
$DataDir   = Join-Path $Root 'data'
$LogDir    = Join-Path $Root 'logs'
$ExeName   = 'SoftCora-POS.exe'
$Product   = 'SoftCora POS'

function Write-Step($text) { Write-Host ("  {0}" -f $text) }
function Fail($text) { Write-Host ("  ! {0}" -f $text) -ForegroundColor Red; exit 1 }

Write-Step "Installing to $Root"

# ── stop a running till so files can be replaced ────────────────────────────
# Stopping is safe: every sale is already committed to SQLite in its own
# transaction, and the outbox lives in the database, not in memory.
$running = Get-Process -Name ([IO.Path]::GetFileNameWithoutExtension($ExeName)) -ErrorAction SilentlyContinue
if ($running) {
    Write-Step "Stopping the running till (it will restart after the update)…"
    $running | Stop-Process -Force
    Start-Sleep -Seconds 1
}

# ── folders: data is created only if absent, never touched again ────────────
foreach ($dir in @($Root, $AppDir, $DataDir, $LogDir)) {
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
}

# ── program files ───────────────────────────────────────────────────────────
$source = Join-Path $PSScriptRoot 'app'
if (-not (Test-Path (Join-Path $source $ExeName))) { Fail "The installer payload is incomplete (no $ExeName)." }

Write-Step "Copying program files…"
Copy-Item -Path (Join-Path $source '*') -Destination $AppDir -Recurse -Force

# The launchers live next to the payload; keep a copy in the app folder too so a
# repair install can always restore them.
foreach ($file in @('start-till.cmd', 'SoftCora POS.cmd', 'verify.ps1', 'uninstall.ps1')) {
    $from = Join-Path $PSScriptRoot $file
    if (Test-Path $from) { Copy-Item -Path $from -Destination $AppDir -Force }
}

$version = '1.0.0'
$versionFile = Join-Path $AppDir 'VERSION.txt'
if (Test-Path $versionFile) {
    $first = (Get-Content $versionFile -TotalCount 1).Trim()
    if ($first) { $version = ($first -split '\s+')[-1] }
}

# ── shortcuts ───────────────────────────────────────────────────────────────
Write-Step "Creating shortcuts…"
$shell = New-Object -ComObject WScript.Shell

function New-Shortcut($path, $target, $arguments, $workingDirectory, $icon) {
    $shortcut = $shell.CreateShortcut($path)
    $shortcut.TargetPath = $target
    if ($arguments) { $shortcut.Arguments = $arguments }
    if ($workingDirectory) { $shortcut.WorkingDirectory = $workingDirectory }
    if ($icon) { $shortcut.IconLocation = $icon }
    $shortcut.Description = $Product
    $shortcut.Save()
}

$launcher = Join-Path $AppDir 'SoftCora POS.cmd'
$icon = Join-Path $AppDir $ExeName

$startMenu = Join-Path ([Environment]::GetFolderPath('Programs')) 'SoftCora POS'
if (-not (Test-Path $startMenu)) { New-Item -ItemType Directory -Path $startMenu -Force | Out-Null }
New-Shortcut (Join-Path $startMenu 'SoftCora POS.lnk') $launcher '' $AppDir $icon
New-Shortcut (Join-Path $startMenu 'SoftCora POS (console).lnk') (Join-Path $AppDir 'start-till.cmd') '' $AppDir $icon
New-Shortcut (Join-Path $startMenu 'Uninstall SoftCora POS.lnk') 'powershell.exe' ('-NoProfile -ExecutionPolicy Bypass -File "{0}"' -f (Join-Path $AppDir 'uninstall.ps1')) $AppDir ''

New-Shortcut (Join-Path ([Environment]::GetFolderPath('Desktop')) 'SoftCora POS.lnk') $launcher '' $AppDir $icon

if (-not $SkipAutostart) {
    Write-Step "Making the till start with Windows…"
    $startup = [Environment]::GetFolderPath('Startup')
    New-Shortcut (Join-Path $startup 'SoftCora POS.lnk') $launcher '' $AppDir $icon
}

# ── Apps & features (per user — no admin needed) ────────────────────────────
Write-Step "Registering the app…"
$uninstallKey = 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Uninstall\SoftCoraPOS'
if (-not (Test-Path $uninstallKey)) { New-Item -Path $uninstallKey -Force | Out-Null }

Set-ItemProperty -Path $uninstallKey -Name 'DisplayName'     -Value $Product
Set-ItemProperty -Path $uninstallKey -Name 'DisplayVersion'  -Value $version
Set-ItemProperty -Path $uninstallKey -Name 'Publisher'       -Value 'SoftCora'
Set-ItemProperty -Path $uninstallKey -Name 'InstallLocation' -Value $Root
Set-ItemProperty -Path $uninstallKey -Name 'DisplayIcon'     -Value $icon
Set-ItemProperty -Path $uninstallKey -Name 'NoModify'        -Value 1 -Type DWord
Set-ItemProperty -Path $uninstallKey -Name 'NoRepair'        -Value 1 -Type DWord
Set-ItemProperty -Path $uninstallKey -Name 'UninstallString' -Value ('powershell.exe -NoProfile -ExecutionPolicy Bypass -File "{0}"' -f (Join-Path $AppDir 'uninstall.ps1'))

$size = (Get-ChildItem $Root -Recurse -File -ErrorAction SilentlyContinue | Measure-Object -Property Length -Sum).Sum
Set-ItemProperty -Path $uninstallKey -Name 'EstimatedSize' -Value ([int]($size / 1KB)) -Type DWord

# ── a launcher that already knows the port ──────────────────────────────────
@"
@echo off
rem SoftCora POS — starts the till (if it is not already running) and opens the screen.
setlocal
set "SOFTCORA_DATA=$DataDir"
set "SOFTCORA_HOST=127.0.0.1"
set "SOFTCORA_PORT=$Port"
cd /d "$AppDir"

curl -s -o NUL --max-time 2 http://127.0.0.1:$Port/api/device >NUL 2>&1
if errorlevel 1 (
  start "" /min "$AppDir\start-till.cmd"
  timeout /t 3 /nobreak >NUL
)

start "" "http://127.0.0.1:$Port/"
exit /b 0
"@ | Set-Content -Path (Join-Path $AppDir 'SoftCora POS.cmd') -Encoding ASCII

@"
@echo off
rem SoftCora POS — the till itself, with a log. Closing this window stops the till.
setlocal
set "SOFTCORA_DATA=$DataDir"
set "SOFTCORA_HOST=127.0.0.1"
set "SOFTCORA_PORT=$Port"
cd /d "$AppDir"
echo SoftCora POS starting… data: %SOFTCORA_DATA%
"$AppDir\$ExeName" >> "$LogDir\till.log" 2>&1
"@ | Set-Content -Path (Join-Path $AppDir 'start-till.cmd') -Encoding ASCII

Write-Step "Done — data folder kept at $DataDir"
Write-Host ''
Write-Host '  Next:' -ForegroundColor Green
Write-Host '    1. The till opens in your browser (http://127.0.0.1:' -NoNewline; Write-Host "$Port)."
Write-Host '    2. In Settings → This till, enter the server address and the activation'
Write-Host '       code your administrator created under Settings → Devices.'
Write-Host '    3. Add a staff sign-in so the shop can log in with no internet.'
Write-Host ''
Write-Host '  Uninstall any time from Apps & features (your data is kept).'
Write-Host ''
exit 0
