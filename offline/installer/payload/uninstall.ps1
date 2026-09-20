<#
    SoftCora POS - per-user uninstall.

    Removes the program, the shortcuts and the registry entries.

    It does NOT remove the data folder unless it is asked to with -RemoveData:
    the till's database holds sales that may not have reached the server yet, and
    an uninstall is not a reason to lose them. When -RemoveData is given and
    anything is still unsynced, the script says how many records would be lost
    and asks for the word DELETE before touching them.

    Exit codes:
      0  removed
      1  failed (see logs\install.log, which is kept if the log folder survives)
      2  cancelled at the prompt - nothing was removed

    Parameters:
      -RemoveData   also delete the data folder (database, identity, backups)
      -Force        do not ask, just do it (used by scripted uninstalls)
      -Silent       no console output; implies -Force

    THIS FILE MUST STAY PURE ASCII - see the note at the top of install.ps1:
    Windows PowerShell 5.1 reads a .ps1 without a byte-order mark using the
    machine's ANSI code page, where a typographic dash or arrow decodes into a
    character PowerShell treats as a quote, and the script no longer parses.
#>
#Requires -Version 5.1
[CmdletBinding()]
param(
    [switch]$RemoveData,
    [switch]$Force,
    [switch]$Silent
)

$ErrorActionPreference = 'Stop'

if ($Silent) { $Force = $true }

$Product     = 'SoftCora POS'
$ExeName     = 'SoftCora-POS.exe'
$ProcessName = 'SoftCora-POS'

$Root         = Join-Path $env:LOCALAPPDATA 'SoftCoraPOS'
$AppDir       = Join-Path $Root 'app'
$DataDir      = Join-Path $Root 'data'
$LogDir       = Join-Path $Root 'logs'
$LogFile      = Join-Path $LogDir 'install.log'
$SettingsKey  = 'HKCU:\Software\SoftCora\POS'
$UninstallKey = 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Uninstall\SoftCoraPOS'

# Set once the log folder has been removed: writing another line after that
# would recreate a folder this script has just deleted, and leave something
# behind that an uninstall is supposed to have cleaned up.
$script:LogFileGone = $false

function Say {
    param([Parameter(Mandatory = $true)][AllowEmptyString()][string]$Message, [string]$Level = 'INFO')

    $line = '{0} [{1}] uninstall: {2}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Level, $Message
    if (-not $script:LogFileGone) {
        try {
            if (-not (Test-Path -LiteralPath $LogDir)) { New-Item -ItemType Directory -Path $LogDir -Force | Out-Null }
            Add-Content -LiteralPath $LogFile -Value $line -Encoding Ascii
        }
        catch { }
    }

    if (-not $Silent) {
        switch ($Level) {
            'ERROR' { Write-Host ('  ' + $Message) -ForegroundColor Red }
            'WARN'  { Write-Host ('  ' + $Message) -ForegroundColor Yellow }
            'OK'    { Write-Host ('  ' + $Message) -ForegroundColor Green }
            default { Write-Host ('  ' + $Message) }
        }
    }
}

# How many records have not reached the server yet. Read before anything is
# deleted, because afterwards there is no program left to ask.
function Get-PendingCount {
    $exe = Join-Path $AppDir $ExeName
    $database = Join-Path $DataDir 'softcora-pos.sqlite'
    if (-not (Test-Path -LiteralPath $exe) -or -not (Test-Path -LiteralPath $database)) { return $null }

    $outFile = Join-Path $env:TEMP ('softcora-out-' + [guid]::NewGuid().ToString('n') + '.txt')
    $errFile = Join-Path $env:TEMP ('softcora-err-' + [guid]::NewGuid().ToString('n') + '.txt')
    $previousData = [Environment]::GetEnvironmentVariable('SOFTCORA_DATA')
    $count = $null

    try {
        [Environment]::SetEnvironmentVariable('SOFTCORA_DATA', $DataDir)
        $process = Start-Process -FilePath $exe -ArgumentList @('--cli', 'verify') `
            -WorkingDirectory $AppDir -NoNewWindow -PassThru `
            -RedirectStandardOutput $outFile -RedirectStandardError $errFile
        if ($process.WaitForExit(60000)) {
            $text = [string](Get-Content -LiteralPath $outFile -Raw -ErrorAction SilentlyContinue)
            try {
                $verdict = $text | ConvertFrom-Json
                if ($null -ne $verdict.pending) { $count = [int]$verdict.pending }
            }
            catch { }
        }
        else {
            try { $process.Kill() } catch { }
        }
    }
    catch { }
    finally {
        [Environment]::SetEnvironmentVariable('SOFTCORA_DATA', $previousData)
        Remove-Item -LiteralPath $outFile, $errFile -Force -ErrorAction SilentlyContinue
    }

    return $count
}

Say '---- uninstall started ----'

# -- ask first ----------------------------------------------------------------
if (-not $Force) {
    $pending = Get-PendingCount

    Write-Host ''
    Write-Host ('  Remove {0} from this PC?' -f $Product)
    Write-Host ('    program : {0}' -f $AppDir)
    if ($RemoveData) {
        Write-Host ('    data    : {0}   WILL BE DELETED' -f $DataDir) -ForegroundColor Yellow
    }
    else {
        Write-Host ('    data    : {0}   kept' -f $DataDir)
    }
    if ($null -ne $pending -and $pending -gt 0) {
        Write-Host ('    {0} record(s) have not reached the server yet.' -f $pending) -ForegroundColor Yellow
    }
    Write-Host ''

    if ($RemoveData -and $null -ne $pending -and $pending -gt 0) {
        Write-Host ('  Deleting the data folder loses those {0} record(s) for good.' -f $pending) -ForegroundColor Red
        $answer = Read-Host '  Type DELETE to continue, anything else to cancel'
        if ($answer -cne 'DELETE') {
            Say 'cancelled: unsynced records would have been deleted' 'WARN'
            Write-Host '  Nothing was removed.'
            exit 2
        }
    }
    else {
        $answer = Read-Host '  Remove it? [y/N]'
        if ($answer -notmatch '^(y|yes)$') {
            Say 'cancelled at the prompt' 'WARN'
            Write-Host '  Nothing was removed.'
            exit 2
        }
    }
    Write-Host ''
}

# -- stop the till ------------------------------------------------------------
$running = @(Get-Process -Name $ProcessName -ErrorAction SilentlyContinue)
if ($running.Count -gt 0) {
    Say 'stopping the till'
    $running | Stop-Process -Force -ErrorAction SilentlyContinue
    for ($waited = 0; $waited -lt 40; $waited++) {
        Start-Sleep -Milliseconds 250
        if (@(Get-Process -Name $ProcessName -ErrorAction SilentlyContinue).Count -eq 0) { break }
    }
    if (@(Get-Process -Name $ProcessName -ErrorAction SilentlyContinue).Count -gt 0) {
        Say 'the till could not be stopped; some files may remain' 'WARN'
    }
}

# -- shortcuts ----------------------------------------------------------------
Say 'removing shortcuts'
$startMenu = Join-Path ([Environment]::GetFolderPath('Programs')) $Product
$shortcuts = @(
    $startMenu,
    (Join-Path ([Environment]::GetFolderPath('Desktop')) ($Product + '.lnk')),
    (Join-Path ([Environment]::GetFolderPath('Startup')) ($Product + '.lnk')),
    (Join-Path ([Environment]::GetFolderPath('CommonDesktopDirectory')) ($Product + '.lnk'))
)
foreach ($target in $shortcuts) {
    if (Test-Path -LiteralPath $target) {
        Remove-Item -LiteralPath $target -Recurse -Force -ErrorAction SilentlyContinue
    }
}

# -- registration -------------------------------------------------------------
Say 'removing the app registration'
foreach ($key in @($UninstallKey, $SettingsKey)) {
    if (Test-Path -LiteralPath $key) { Remove-Item -LiteralPath $key -Recurse -Force -ErrorAction SilentlyContinue }
}

# The parent key goes too when this install was the only thing in it.
$vendorKey = Split-Path -Parent $SettingsKey
if ((Test-Path -LiteralPath $vendorKey) -and (@(Get-ChildItem -LiteralPath $vendorKey -ErrorAction SilentlyContinue).Count -eq 0)) {
    Remove-Item -LiteralPath $vendorKey -Force -ErrorAction SilentlyContinue
}

# -- data, only when it was asked for ----------------------------------------
$removedData = $false
if ($RemoveData) {
    if (Test-Path -LiteralPath $DataDir) {
        Say ('deleting the data folder at {0}, as requested' -f $DataDir) 'WARN'
        Remove-Item -LiteralPath $DataDir -Recurse -Force -ErrorAction SilentlyContinue
        $removedData = -not (Test-Path -LiteralPath $DataDir)
        if (-not $removedData) { Say 'part of the data folder could not be deleted (a file may be open)' 'WARN' }
    }
}
else {
    Say ('keeping the data folder at {0}' -f $DataDir)
}

# -- program files ------------------------------------------------------------
Say 'removing program files'
foreach ($folder in @($AppDir, $LogDir)) {
    if (Test-Path -LiteralPath $folder) {
        Remove-Item -LiteralPath $folder -Recurse -Force -ErrorAction SilentlyContinue
    }
}
if (-not (Test-Path -LiteralPath $LogDir)) { $script:LogFileGone = $true }

# The root goes too when nothing is left in it - which, with the data kept, it
# will not be.
if ((Test-Path -LiteralPath $Root) -and (@(Get-ChildItem -LiteralPath $Root -Force -ErrorAction SilentlyContinue).Count -eq 0)) {
    Remove-Item -LiteralPath $Root -Recurse -Force -ErrorAction SilentlyContinue
}

Say '---- uninstall finished ----' 'OK'

if (-not $Silent) {
    Write-Host ''
    if ($removedData) {
        Write-Host ('  {0} has been removed, including its data.' -f $Product)
    }
    elseif (Test-Path -LiteralPath $DataDir) {
        Write-Host ('  {0} has been removed.' -f $Product)
        Write-Host ('  Your data was kept here: {0}' -f $DataDir)
        Write-Host '  Delete that folder by hand once everything is synced.'
    }
    else {
        Write-Host ('  {0} has been removed.' -f $Product)
    }
    Write-Host ''
}

exit 0
