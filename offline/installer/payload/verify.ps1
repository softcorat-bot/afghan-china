<#
    SoftCora POS - health check.

    Run after an install or an update, or from the Start menu shortcut of the
    same name. It answers the only questions that matter at a till: is the
    program really installed, is the database there, is the till answering, how
    much work is still waiting to go up, and is the device registered.

    Nothing here changes anything: a missing database is reported as missing
    rather than created, which is why the sync status is only read once the till
    has been started at least once.

    Parameters:
      -Json     print one machine-readable object instead of the report
      -Port n   check a port other than the one this install recorded

    Exit codes:
      0  the till is installed and answers its own checks
      1  the program is not installed
      2  the program is installed but did not run

    THIS FILE MUST STAY PURE ASCII - see the note at the top of install.ps1.
#>
#Requires -Version 5.1
[CmdletBinding()]
param(
    [switch]$Json,
    [int]$Port = 0
)

$ErrorActionPreference = 'Stop'

$Product   = 'SoftCora POS'
$ExeName   = 'SoftCora-POS.exe'
$SettingsKey = 'HKCU:\Software\SoftCora\POS'

$Root   = Join-Path $env:LOCALAPPDATA 'SoftCoraPOS'
$AppDir = Join-Path $Root 'app'
$Data   = Join-Path $Root 'data'
$Logs   = Join-Path $Root 'logs'
$Exe    = Join-Path $AppDir $ExeName
$Db     = Join-Path $Data 'softcora-pos.sqlite'

function Read-Stored {
    param([Parameter(Mandatory = $true)][string]$Name, $Default = $null)

    try {
        if (Test-Path -LiteralPath $SettingsKey) {
            $item = Get-ItemProperty -LiteralPath $SettingsKey -Name $Name -ErrorAction SilentlyContinue
            if ($null -ne $item -and $null -ne $item.$Name) { return $item.$Name }
        }
    }
    catch { }
    return $Default
}

function Format-Size {
    param([long]$Bytes)
    if ($Bytes -ge 1MB) { return ('{0:n1} MB' -f ($Bytes / 1MB)) }
    if ($Bytes -ge 1KB) { return ('{0:n0} KB' -f ($Bytes / 1KB)) }
    return ('{0} B' -f $Bytes)
}

# -- what is installed --------------------------------------------------------
$report = [ordered]@{
    product       = $Product
    installed     = (Test-Path -LiteralPath $Exe)
    version       = $null
    app_dir       = $AppDir
    data_dir      = $Data
    log_dir       = $Logs
    database      = $null
    device_id     = $null
    registered    = $null
    port          = $null
    till_answers  = $null
    sync          = $null
    last_backup   = $null
    logs          = @()
    problems      = @()
}

$versionFile = Join-Path $AppDir 'VERSION.txt'
if (Test-Path -LiteralPath $versionFile) {
    $first = Get-Content -LiteralPath $versionFile -TotalCount 1 -ErrorAction SilentlyContinue
    if ($first) { $report.version = ($first.Trim() -split '\s+')[-1] }
}

if (-not $report.installed) {
    $report.problems += ('the program is not installed at {0}' -f $AppDir)
}

# -- the port this install chose ---------------------------------------------
if ($Port -gt 0) { $report.port = $Port }
else {
    $stored = Read-Stored -Name 'Port' -Default 7817
    $report.port = [int]$stored
}

# -- database and identity ----------------------------------------------------
if (Test-Path -LiteralPath $Db) {
    $report.database = [ordered]@{
        file      = $Db
        size      = (Get-Item -LiteralPath $Db).Length
        modified  = (Get-Item -LiteralPath $Db).LastWriteTime.ToString('yyyy-MM-dd HH:mm:ss')
    }
}

$deviceFile = Join-Path $Data 'device.json'
if (Test-Path -LiteralPath $deviceFile) {
    try {
        $identity = Get-Content -LiteralPath $deviceFile -Raw | ConvertFrom-Json
        $report.device_id = $identity.device_id
        $report.registered = [bool]$identity.device_token
    }
    catch {
        $report.problems += ('device.json could not be read: ' + $_.Exception.Message)
    }
}

# -- is the till answering? ---------------------------------------------------
$url = 'http://127.0.0.1:{0}/api/device' -f $report.port
try {
    $answer = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 3 -ErrorAction Stop
    $report.till_answers = ($answer.StatusCode -eq 200)
}
catch {
    $report.till_answers = $false
}

# -- sync state, read from the till's own database ----------------------------
if ($report.installed -and (Test-Path -LiteralPath $Db)) {
    $previousData = [Environment]::GetEnvironmentVariable('SOFTCORA_DATA')

    # System.Diagnostics.Process, never Start-Process -PassThru: in Windows
    # PowerShell 5.1 the process object it returns reports no ExitCode, and a
    # health check that reads that as "the till failed" turns a healthy install
    # into a problem report. The .NET object reports the code it was given.
    $text = ''
    $errors = ''

    try {
        [Environment]::SetEnvironmentVariable('SOFTCORA_DATA', $Data)

        $info = New-Object System.Diagnostics.ProcessStartInfo
        $info.FileName = $Exe
        $info.Arguments = '"--cli" "status"'
        $info.WorkingDirectory = $AppDir
        $info.UseShellExecute = $false
        $info.CreateNoWindow = $true
        $info.RedirectStandardOutput = $true
        $info.RedirectStandardError = $true
        $info.StandardOutputEncoding = [System.Text.Encoding]::UTF8
        $info.StandardErrorEncoding = [System.Text.Encoding]::UTF8

        $process = New-Object System.Diagnostics.Process
        $process.StartInfo = $info
        [void]$process.Start()

        $outTask = $process.StandardOutput.ReadToEndAsync()
        $errTask = $process.StandardError.ReadToEndAsync()

        if ($process.WaitForExit(60000)) {
            $process.WaitForExit()
            if ($outTask.Wait(5000)) { $text = [string]$outTask.Result }
            if ($errTask.Wait(5000)) { $errors = [string]$errTask.Result }

            try {
                $status = $text | ConvertFrom-Json
                $report.sync = [ordered]@{
                    state          = $status.state
                    connectivity   = $status.connectivity
                    pending        = $status.pending
                    failed         = $status.failed
                    conflicts      = $status.conflicts
                    synced         = $status.synced
                    last_sync_at   = $status.last_sync_at
                    last_result    = $status.last_sync_result
                    auto_sync      = $status.auto_sync
                }
                if ($status.device_id -and -not $report.device_id) { $report.device_id = $status.device_id }
            }
            catch {
                $report.problems += ('the till printed no readable status: ' + $text.Trim())
            }

            # Only a code that was actually read and is not zero is a problem:
            # an unreadable code is not evidence that anything went wrong.
            if ($null -ne $process.ExitCode -and $process.ExitCode -ne 0) {
                $report.problems += ('the till exited with {0}: {1}' -f $process.ExitCode, $errors.Trim())
            }
        }
        else {
            try { $process.Kill() } catch { }
            try { $process.WaitForExit(5000) | Out-Null } catch { }
            $report.problems += 'the till did not report its status within 60 seconds'
        }
    }
    catch {
        $report.problems += ('the installed program could not be started: ' + $_.Exception.Message)
    }
    finally {
        [Environment]::SetEnvironmentVariable('SOFTCORA_DATA', $previousData)
    }
}

# -- backups and logs ---------------------------------------------------------
$backups = Join-Path $Data 'backups'
if (Test-Path -LiteralPath $backups) {
    $latest = Get-ChildItem -LiteralPath $backups -File -ErrorAction SilentlyContinue |
        Sort-Object LastWriteTime -Descending | Select-Object -First 1
    if ($latest) {
        $report.last_backup = [ordered]@{
            file     = $latest.Name
            size     = $latest.Length
            modified = $latest.LastWriteTime.ToString('yyyy-MM-dd HH:mm:ss')
        }
    }
}

if (Test-Path -LiteralPath $Logs) {
    foreach ($file in (Get-ChildItem -LiteralPath $Logs -File -ErrorAction SilentlyContinue | Sort-Object Name)) {
        $report.logs += [ordered]@{ file = $file.Name; size = $file.Length; modified = $file.LastWriteTime.ToString('yyyy-MM-dd HH:mm:ss') }
    }
}

# -- output -------------------------------------------------------------------
if ($Json) {
    $report | ConvertTo-Json -Depth 5
}
else {
    Write-Host ''
    Write-Host ('  {0} - health check' -f $Product)
    Write-Host '  ---------------------------'
    Write-Host ''

    if ($report.installed) {
        Write-Host ('  version       : {0}' -f $(if ($report.version) { $report.version } else { 'unknown' }))
        Write-Host ('  program       : {0}' -f $Exe)
    }
    else {
        Write-Host ('  program       : NOT INSTALLED (expected at {0})' -f $Exe) -ForegroundColor Red
    }

    if ($report.database) {
        Write-Host ('  database      : {0} ({1}, written {2})' -f $Db, (Format-Size $report.database.size), $report.database.modified)
    }
    else {
        Write-Host '  database      : not created yet - the till creates it on first start'
    }

    if ($report.device_id) {
        Write-Host ('  device id     : {0}' -f $report.device_id)
        if ($report.registered) {
            Write-Host '  registered    : yes'
        }
        else {
            Write-Host '  registered    : no - activate it in Settings -> This till' -ForegroundColor Yellow
        }
    }
    else {
        Write-Host '  device id     : not set yet - open Settings -> This till'
    }

    if ($report.till_answers) {
        Write-Host ('  till          : answering on http://127.0.0.1:{0}/' -f $report.port) -ForegroundColor Green
    }
    else {
        Write-Host ('  till          : not answering on port {0} (is it running?)' -f $report.port) -ForegroundColor Yellow
    }

    if ($report.sync) {
        Write-Host ('  sync state    : {0} ({1})' -f $report.sync.state, $report.sync.connectivity)
        Write-Host ('  queue         : {0} pending, {1} failed, {2} conflicts, {3} synced' -f `
            $report.sync.pending, $report.sync.failed, $report.sync.conflicts, $report.sync.synced)
        if ($report.sync.last_sync_at) {
            $detail = ''
            if ($report.sync.last_result) {
                $detail = ' - uploaded {0}, downloaded {1}, duplicates {2}' -f `
                    $report.sync.last_result.uploaded, $report.sync.last_result.downloaded, $report.sync.last_result.duplicates
            }
            Write-Host ('  last sync     : {0}{1}' -f $report.sync.last_sync_at, $detail)
        }
        else {
            Write-Host '  last sync     : never (nothing has been sent yet)'
        }
    }
    elseif ($report.installed) {
        Write-Host '  sync state    : not read yet - start the till once, then run this again'
    }

    if ($report.last_backup) {
        Write-Host ('  last backup   : {0} ({1}, {2})' -f $report.last_backup.file, (Format-Size $report.last_backup.size), $report.last_backup.modified)
    }
    else {
        Write-Host '  last backup   : none yet - Settings -> Backup'
    }

    Write-Host ('  logs          : {0}' -f $Logs)

    if ($report.problems.Count -gt 0) {
        Write-Host ''
        Write-Host '  Problems found:' -ForegroundColor Red
        foreach ($problem in $report.problems) { Write-Host ('    - {0}' -f $problem) -ForegroundColor Red }
    }

    Write-Host ''
    Write-Host '  A "pending" count above is not an error while the shop is offline:'
    Write-Host '  those sales are stored locally and are sent by the next Sync Now.'
    Write-Host ''
}

if (-not $report.installed) { exit 1 }
if ($report.problems.Count -gt 0) { exit 2 }
exit 0
