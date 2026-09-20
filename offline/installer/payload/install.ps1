<#
    SoftCora POS - per-user installer.

    Installs the till into %LOCALAPPDATA%\SoftCoraPOS, creates the shortcuts and
    registers it in "Apps & features".

    The rules this script exists to keep:
      * the data folder (database, device identity, outbox, backups) is never
        overwritten or deleted by an install - only created if missing;
      * updating means replacing program files, nothing else;
      * no administrator rights, no firewall rule, no reboot;
      * the packaged program is proved to run on this PC, and an existing
        database is proved intact, *before* any installed file is replaced;
      * every step is written to logs\install.log, so a failure on a shop PC can
        be diagnosed from that one file.

    Exit codes (install.cmd reads them):
      0   installed - the caller may launch the till
      3   installed - the caller asked not to launch it (-NoLaunch)
      1   failed - nothing was deleted and the data folder is untouched

    ---------------------------------------------------------------------------
    THIS FILE MUST STAY PURE ASCII.
    ---------------------------------------------------------------------------
    Windows PowerShell 5.1 reads a .ps1 that has no byte-order mark using the
    machine's ANSI code page, not UTF-8. On a Western machine that is cp1252,
    where the UTF-8 bytes of an em dash (E2 80 94) decode to three characters
    whose last one is U+201D, RIGHT DOUBLE QUOTATION MARK - and PowerShell
    accepts the typographic quotes as string delimiters. One em dash inside a
    string therefore closes that string early, the rest of the file is parsed as
    code, and the install dies far away from the real cause:

        install.ps1:152 char:100
        + ... in your browser (http://127.0.0.1:' -NoNewline; Write-Host "$Port)."
        Unexpected token ')' in expression or statement.

    Arrows are the same trap (U+2192 decodes to a sequence ending in U+2019, a
    right single quote). installer/check-payload.mjs enforces ASCII on every
    .ps1 and .cmd in the payload and fails the build otherwise, and the build
    stamps a UTF-8 BOM on the copies it ships so a later edit cannot bring the
    bug back by accident. Write "-" and "->" here, never the typographic forms.
#>
#Requires -Version 5.1
[CmdletBinding()]
param(
    # Port the till listens on. Leave it out on an update and the port already
    # configured on this PC is kept, so a re-install cannot move a shop's till.
    [int]$Port = 7817,

    # Do not add the Start-up shortcut that opens the till at sign-in.
    [switch]$SkipAutostart,

    # Install only; leave launching to whoever called this script.
    [switch]$NoLaunch,

    # No console output (everything is still written to the log).
    [switch]$Silent
)

$ErrorActionPreference = 'Stop'

# -- names and places ---------------------------------------------------------
$Product     = 'SoftCora POS'
$Publisher   = 'SoftCora'
$ExeName     = 'SoftCora-POS.exe'
$ProcessName = 'SoftCora-POS'

# A profile folder is the one thing this installer cannot work without, and it is
# checked before any path is built from it: with no %LOCALAPPDATA% there is
# nothing to join, and "Join-Path failed" is not an answer a shop can act on.
if (-not $env:LOCALAPPDATA) {
    Write-Host '  ! Windows did not report a user profile folder (%LOCALAPPDATA%),' -ForegroundColor Red
    Write-Host '    so there is nowhere to install to. Run this setup from a normal' -ForegroundColor Red
    Write-Host '    signed-in user account, not from a service.' -ForegroundColor Red
    exit 1
}

$Root         = Join-Path $env:LOCALAPPDATA 'SoftCoraPOS'
$AppDir       = Join-Path $Root 'app'
$DataDir      = Join-Path $Root 'data'
$LogDir       = Join-Path $Root 'logs'
$LogFile      = Join-Path $LogDir 'install.log'
$TillLog      = Join-Path $LogDir 'till.log'

$SettingsKey  = 'HKCU:\Software\SoftCora\POS'
$UninstallKey = 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Uninstall\SoftCoraPOS'

$PayloadDir = $PSScriptRoot
$PayloadApp = Join-Path $PayloadDir 'app'
$PayloadExe = Join-Path $PayloadApp $ExeName

$script:Quiet = [bool]$Silent

# -- logging ------------------------------------------------------------------
# File first, console second: a log that cannot be written must never be the
# reason an install fails.
function Write-Log {
    param(
        [Parameter(Mandatory = $true)][AllowEmptyString()][string]$Message,
        [string]$Level = 'INFO'
    )

    $line = '{0} [{1}] {2}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Level, $Message

    try {
        if (-not (Test-Path -LiteralPath $LogDir)) { New-Item -ItemType Directory -Path $LogDir -Force | Out-Null }

        # Roll at 1 MB keeping two generations - the same shape as the till's own
        # logs, so the folder stays readable.
        if ((Test-Path -LiteralPath $LogFile) -and ((Get-Item -LiteralPath $LogFile).Length -gt 1MB)) {
            for ($generation = 2; $generation -ge 1; $generation--) {
                $older = Join-Path $LogDir ('install.{0}.log' -f $generation)
                $newer = Join-Path $LogDir ('install.{0}.log' -f ($generation - 1))
                if ($generation -eq 1) { $newer = $LogFile }
                if (Test-Path -LiteralPath $newer) { Move-Item -LiteralPath $newer -Destination $older -Force }
            }
        }

        Add-Content -LiteralPath $LogFile -Value $line -Encoding Ascii
    }
    catch {
        # nowhere to write to; the console below is all that is left
    }

    if (-not $script:Quiet) {
        switch ($Level) {
            'ERROR' { Write-Host ('  ' + $Message) -ForegroundColor Red }
            'WARN'  { Write-Host ('  ' + $Message) -ForegroundColor Yellow }
            'OK'    { Write-Host ('  ' + $Message) -ForegroundColor Green }
            default { Write-Host ('  ' + $Message) }
        }
    }
}

function Fail {
    param([Parameter(Mandatory = $true)][string]$Message)

    Write-Log $Message 'ERROR'
    if (-not $script:Quiet) {
        Write-Host ''
        Write-Host '  The installation stopped. Nothing was deleted, and the' -ForegroundColor Red
        Write-Host ('  data folder at {0} was not touched.' -f $DataDir) -ForegroundColor Red
        Write-Host ('  Log: {0}' -f $LogFile) -ForegroundColor Red
        Write-Host ''
    }
    exit 1
}

# Anything this script did not foresee ends up here, with a line number, in the
# log - which is the difference between a fixable support call and a guess.
trap {
    $where = ''
    if ($_.InvocationInfo) { $where = ' (line {0})' -f $_.InvocationInfo.ScriptLineNumber }
    Write-Log ('unexpected: ' + $_.Exception.Message + $where) 'ERROR'
    Fail ('The installer hit an error it did not expect' + $where + ': ' + $_.Exception.Message)
    continue
}

# -- helpers ------------------------------------------------------------------

# Runs the packaged till against a chosen data folder and captures what it
# printed, with a timeout. Used to prove the program runs on this PC before any
# installed file is replaced.
function Invoke-TillCli {
    param(
        [Parameter(Mandatory = $true)][string]$Exe,
        [Parameter(Mandatory = $true)][string]$DataPath,
        [Parameter(Mandatory = $true)][string[]]$Arguments,
        [int]$TimeoutSeconds = 120
    )

    $outFile = Join-Path $env:TEMP ('softcora-out-' + [guid]::NewGuid().ToString('n') + '.txt')
    $errFile = Join-Path $env:TEMP ('softcora-err-' + [guid]::NewGuid().ToString('n') + '.txt')

    # The child inherits this process' environment, so the variables are set here
    # and put back afterwards; nothing else in the install may see them.
    $wanted = @{ 'SOFTCORA_DATA' = $DataPath; 'SOFTCORA_HOST' = '127.0.0.1' }
    $previous = @{}
    foreach ($name in $wanted.Keys) {
        $previous[$name] = [Environment]::GetEnvironmentVariable($name)
        [Environment]::SetEnvironmentVariable($name, $wanted[$name])
    }

    $result = [pscustomobject]@{ ExitCode = -1; StdOut = ''; StdErr = ''; TimedOut = $false }

    try {
        $process = Start-Process -FilePath $Exe `
            -ArgumentList $Arguments `
            -WorkingDirectory (Split-Path -Parent $Exe) `
            -NoNewWindow -PassThru `
            -RedirectStandardOutput $outFile `
            -RedirectStandardError $errFile

        if ($process.WaitForExit($TimeoutSeconds * 1000)) {
            $result.ExitCode = $process.ExitCode
        }
        else {
            $result.TimedOut = $true
            try { $process.Kill() } catch { }
            try { $process.WaitForExit(5000) | Out-Null } catch { }
        }

        if (Test-Path -LiteralPath $outFile) { $result.StdOut = [string](Get-Content -LiteralPath $outFile -Raw -ErrorAction SilentlyContinue) }
        if (Test-Path -LiteralPath $errFile) { $result.StdErr = [string](Get-Content -LiteralPath $errFile -Raw -ErrorAction SilentlyContinue) }
    }
    finally {
        foreach ($name in $previous.Keys) { [Environment]::SetEnvironmentVariable($name, $previous[$name]) }
        Remove-Item -LiteralPath $outFile, $errFile -Force -ErrorAction SilentlyContinue
    }

    return $result
}

# Copies with a short retry: antivirus and the search indexer hold freshly
# written files for a moment, and that is no reason to fail an install.
function Copy-WithRetry {
    param(
        [Parameter(Mandatory = $true)][string]$From,
        [Parameter(Mandatory = $true)][string]$To,
        [switch]$Recurse,
        [int]$Attempts = 5
    )

    for ($attempt = 1; $attempt -le $Attempts; $attempt++) {
        try {
            if ($Recurse) { Copy-Item -Path $From -Destination $To -Recurse -Force -ErrorAction Stop }
            else { Copy-Item -LiteralPath $From -Destination $To -Force -ErrorAction Stop }
            return
        }
        catch {
            if ($attempt -eq $Attempts) { throw }
            Write-Log ('copy busy, retrying {0}/{1}: {2}' -f $attempt, $Attempts, $_.Exception.Message) 'WARN'
            Start-Sleep -Milliseconds (400 * $attempt)
        }
    }
}

function Get-StoredValue {
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

function New-Shortcut {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][string]$Target,
        [string]$Arguments = '',
        [string]$WorkingDirectory = '',
        [string]$Icon = ''
    )

    $shortcut = $script:Shell.CreateShortcut($Path)
    $shortcut.TargetPath = $Target
    if ($Arguments) { $shortcut.Arguments = $Arguments }
    if ($WorkingDirectory) { $shortcut.WorkingDirectory = $WorkingDirectory }
    if ($Icon) { $shortcut.IconLocation = $Icon }
    $shortcut.Description = $Product
    $shortcut.Save()
}

function Read-VersionFrom {
    param([Parameter(Mandatory = $true)][string]$File)

    if (-not (Test-Path -LiteralPath $File)) { return $null }
    $first = Get-Content -LiteralPath $File -TotalCount 1 -ErrorAction SilentlyContinue
    if (-not $first) { return $null }
    return ($first.Trim() -split '\s+')[-1]
}

function New-TemporaryFolder {
    param([string]$Prefix = 'softcora-')

    $path = Join-Path $env:TEMP ($Prefix + [guid]::NewGuid().ToString('n'))
    New-Item -ItemType Directory -Path $path -Force | Out-Null
    return $path
}

# -- 0. somewhere to log to, and what this run is -----------------------------
foreach ($directory in @($Root, $LogDir)) {
    if (-not (Test-Path -LiteralPath $directory)) { New-Item -ItemType Directory -Path $directory -Force | Out-Null }
}

$installedVersion = Read-VersionFrom (Join-Path $AppDir 'VERSION.txt')
$version = Read-VersionFrom (Join-Path $PayloadDir 'VERSION.txt')
if (-not $version) { $version = Read-VersionFrom (Join-Path $PayloadApp 'VERSION.txt') }
if (-not $version) { $version = '0.0.0' }

Write-Log ('---- {0} {1} installer started ----' -f $Product, $version)
Write-Log ('payload {0}' -f $PayloadDir)
Write-Log ('windows {0}, powershell {1}, user {2}' -f [Environment]::OSVersion.Version, $PSVersionTable.PSVersion, $env:USERNAME)
if ($installedVersion) { Write-Log ('updating the installed {0} to {1}' -f $installedVersion, $version) }
else { Write-Log 'first install on this PC' }

# -- 1. pre-flight: fail before touching anything -----------------------------
if (-not (Test-Path -LiteralPath $PayloadExe)) {
    Fail ('The installer payload is incomplete: there is no {0} in {1}.' -f $ExeName, $PayloadApp)
}

if (($Port -lt 1) -or ($Port -gt 65535)) { Fail ('Port {0} is not a usable TCP port (1-65535).' -f $Port) }

$drive = (Get-Item -LiteralPath $Root).PSDrive.Name
$freeMb = [math]::Round((Get-PSDrive -Name $drive).Free / 1MB)
if ($freeMb -lt 300) { Fail ('Drive {0} has only {1} MB free; the till needs about 300 MB.' -f $drive, $freeMb) }
Write-Log ('drive {0} has {1} MB free' -f $drive, $freeMb)

# Prove the packaged program runs on this machine, in a throw-away data folder,
# before anything installed is replaced. A blocked, quarantined or corrupt
# executable fails here with its own message, and the existing install stays
# exactly as it was.
Write-Log 'checking that the packaged program runs on this PC'
$probeData = New-TemporaryFolder 'softcora-probe-'
try {
    $probe = Invoke-TillCli -Exe $PayloadExe -DataPath $probeData -Arguments @('--cli', 'status')
}
finally {
    Remove-Item -LiteralPath $probeData -Recurse -Force -ErrorAction SilentlyContinue
}

if ($probe.TimedOut) { Fail ('{0} did not answer within 120 seconds.' -f $ExeName) }
if ($probe.ExitCode -ne 0) {
    $detail = ($probe.StdErr + ' ' + $probe.StdOut).Trim()
    Fail ('{0} did not run on this PC (exit {1}). {2}' -f $ExeName, $probe.ExitCode, $detail)
}
Write-Log 'the packaged program runs' 'OK'

# An update must not walk into a database the till itself would refuse. This is
# the same gate the till applies (assertUpgradeSafe), run before any file moves.
if (Test-Path -LiteralPath (Join-Path $DataDir 'softcora-pos.sqlite')) {
    Write-Log ('checking the existing database in {0}' -f $DataDir)
    $check = Invoke-TillCli -Exe $PayloadExe -DataPath $DataDir -Arguments @('--cli', 'verify')

    $verdict = $null
    try { $verdict = $check.StdOut | ConvertFrom-Json } catch { $verdict = $null }

    if ($null -eq $verdict) {
        Write-Log ('the upgrade check returned no JSON (exit {0}): {1}' -f $check.ExitCode, ($check.StdOut + $check.StdErr).Trim()) 'WARN'
    }
    elseif ($verdict.ok) {
        # A first install has no database to report on, so neither field has to
        # be there; the log line must not depend on them.
        $checkedDevice = if ($verdict.device_id) { [string]$verdict.device_id } else { 'not set yet' }
        $checkedPending = if ($null -ne $verdict.pending) { [int]$verdict.pending } else { 0 }
        Write-Log ('upgrade check passed: device {0}, {1} record(s) still to sync' -f $checkedDevice, $checkedPending) 'OK'
    }
    else {
        Fail ('The update was refused to protect the shop data: ' + $verdict.reason)
    }
}

# -- 2. the port this till will use -------------------------------------------
# An update keeps the port it already had unless one is given explicitly: a
# bookmarked till screen must not move because a setup was run again.
if (-not $PSBoundParameters.ContainsKey('Port')) {
    $storedPort = Get-StoredValue -Name 'Port'
    if ($storedPort) {
        $Port = [int]$storedPort
        Write-Log ('keeping the port already configured on this PC: {0}' -f $Port)
    }
}
Write-Log ('port {0}' -f $Port)

# -- 3. stop a running till so its files can be replaced ----------------------
# Stopping is safe: every sale is committed to SQLite in its own transaction and
# the outbox lives in the database, not in memory.
$running = @(Get-Process -Name $ProcessName -ErrorAction SilentlyContinue)
if ($running.Count -gt 0) {
    Write-Log 'stopping the running till (it is started again at the end)'
    $running | Stop-Process -Force -ErrorAction SilentlyContinue

    for ($waited = 0; $waited -lt 40; $waited++) {
        Start-Sleep -Milliseconds 250
        if (@(Get-Process -Name $ProcessName -ErrorAction SilentlyContinue).Count -eq 0) { break }
    }
    if (@(Get-Process -Name $ProcessName -ErrorAction SilentlyContinue).Count -gt 0) {
        Fail 'The running till could not be stopped. Close it, then run the setup again.'
    }
}

# Somebody else may own the port. That does not stop the install, but the shop
# should hear it here rather than from a till that will not open.
$portFree = $true
try {
    $listener = New-Object System.Net.Sockets.TcpListener([System.Net.IPAddress]::Loopback, $Port)
    $listener.Start()
    $listener.Stop()
}
catch {
    $portFree = $false
}
if (-not $portFree) {
    Write-Log ('port {0} is already used by another program; the till cannot open until it is free' -f $Port) 'WARN'
}

# -- 4. folders: data is created only if absent, never touched again ----------
foreach ($directory in @($Root, $AppDir, $DataDir, $LogDir)) {
    if (-not (Test-Path -LiteralPath $directory)) {
        New-Item -ItemType Directory -Path $directory -Force | Out-Null
        Write-Log ('created {0}' -f $directory)
    }
}

# -- 5. program files ---------------------------------------------------------
Write-Log 'copying program files'
Copy-WithRetry -From (Join-Path $PayloadApp '*') -To $AppDir -Recurse

# The launchers and the helper scripts live next to the payload; keep a copy in
# the app folder so a repair install can always restore them.
foreach ($file in @('start-till.cmd', 'SoftCora POS.cmd', 'verify.ps1', 'uninstall.ps1', 'README-FIRST.txt')) {
    $from = Join-Path $PayloadDir $file
    if (Test-Path -LiteralPath $from) { Copy-WithRetry -From $from -To (Join-Path $AppDir $file) }
}

$installedExe = Join-Path $AppDir $ExeName
if (-not (Test-Path -LiteralPath $installedExe)) { Fail ('{0} was not copied into {1}.' -f $ExeName, $AppDir) }

$sourceHash = (Get-FileHash -LiteralPath $PayloadExe -Algorithm SHA256).Hash
$targetHash = (Get-FileHash -LiteralPath $installedExe -Algorithm SHA256).Hash
if ($sourceHash -ne $targetHash) {
    Fail 'The copied program does not match the payload (SHA-256 differs). Antivirus may have altered it.'
}
Write-Log ('program files in place, sha256 {0}' -f $targetHash) 'OK'

# -- 6. launchers that know this PC's port and folders ------------------------
# Written as ASCII with CRLF endings: cmd.exe mis-parses LF-only .cmd files and
# has no idea what to do with a byte-order mark.
Write-Log 'writing the launchers'

$launcherLines = @(
    '@echo off',
    'rem SoftCora POS - starts the till if it is not running, then opens the screen.',
    ('rem Generated by install.ps1 on {0}; edits are lost on the next update.' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss')),
    'setlocal EnableExtensions',
    ('set "SOFTCORA_DATA={0}"' -f $DataDir),
    ('set "SOFTCORA_LOGS={0}"' -f $LogDir),
    'set "SOFTCORA_HOST=127.0.0.1"',
    ('set "SOFTCORA_PORT={0}"' -f $Port),
    ('set "SOFTCORA_APP={0}"' -f $AppDir),
    ('set "SOFTCORA_URL=http://127.0.0.1:{0}/"' -f $Port),
    'cd /d "%SOFTCORA_APP%"',
    '',
    'rem curl.exe ships with Windows 10 1803 and later; without it there is nothing',
    'rem to probe with, so the till is started and given a fixed moment instead.',
    'set "HAVE_CURL=0"',
    'where curl.exe >nul 2>&1',
    'if not errorlevel 1 set "HAVE_CURL=1"',
    '',
    'if "%HAVE_CURL%"=="0" goto start',
    'curl -s -o NUL --max-time 2 "%SOFTCORA_URL%api/device" >nul 2>&1',
    'if not errorlevel 1 goto open',
    '',
    ':start',
    'start "SoftCora POS" /min "%~dp0start-till.cmd"',
    'if "%HAVE_CURL%"=="0" goto blindwait',
    '',
    'set /a TRIES=0',
    ':wait',
    'set /a TRIES+=1',
    'rem ping is used for the pause: timeout.exe refuses to wait when input is',
    'rem redirected, which is how a shortcut launched from a script arrives.',
    'ping -n 2 127.0.0.1 >nul',
    'curl -s -o NUL --max-time 2 "%SOFTCORA_URL%api/device" >nul 2>&1',
    'if not errorlevel 1 goto open',
    'if %TRIES% GEQ 20 goto slow',
    'goto wait',
    '',
    ':blindwait',
    'ping -n 5 127.0.0.1 >nul',
    'goto open',
    '',
    ':slow',
    'echo   The till did not answer within 20 seconds.',
    ('echo   Its log is at {0}' -f $TillLog),
    'echo.',
    '',
    ':open',
    'start "" "%SOFTCORA_URL%"',
    'exit /b 0'
)
Set-Content -LiteralPath (Join-Path $AppDir 'SoftCora POS.cmd') -Value $launcherLines -Encoding Ascii

$tillLines = @(
    '@echo off',
    'rem SoftCora POS - the till itself, with a log. Closing this window stops the till.',
    'rem Generated by install.ps1; edits are lost on the next update.',
    'setlocal EnableExtensions',
    ('set "SOFTCORA_DATA={0}"' -f $DataDir),
    ('set "SOFTCORA_LOGS={0}"' -f $LogDir),
    'set "SOFTCORA_HOST=127.0.0.1"',
    ('set "SOFTCORA_PORT={0}"' -f $Port),
    ('set "SOFTCORA_TILLLOG={0}"' -f $TillLog),
    ('if not exist "{0}" mkdir "{0}"' -f $DataDir),
    ('if not exist "{0}" mkdir "{0}"' -f $LogDir),
    ('cd /d "{0}"' -f $AppDir),
    'title SoftCora POS',
    'echo SoftCora POS starting...',
    ('echo   data : {0}' -f $DataDir),
    ('echo   log  : {0}' -f $TillLog),
    ('echo   url  : http://127.0.0.1:{0}/' -f $Port),
    'echo   Close this window to stop the till.',
    'echo.',
    ('"{0}" >> "{1}" 2>&1' -f $installedExe, $TillLog),
    'set "RC=%ERRORLEVEL%"',
    'echo.',
    'echo The till stopped with exit code %RC%. The last lines of its log:',
    'echo.',
    'rem The path travels in an environment variable so no quoting can break it.',
    'powershell -NoProfile -ExecutionPolicy Bypass -Command "if (Test-Path -LiteralPath $env:SOFTCORA_TILLLOG) { Get-Content -LiteralPath $env:SOFTCORA_TILLLOG -Tail 15 }"',
    'echo.',
    'pause',
    'exit /b %RC%'
)
Set-Content -LiteralPath (Join-Path $AppDir 'start-till.cmd') -Value $tillLines -Encoding Ascii

# -- 7. shortcuts -------------------------------------------------------------
Write-Log 'creating shortcuts'
$script:Shell = New-Object -ComObject WScript.Shell

$launcher = Join-Path $AppDir 'SoftCora POS.cmd'
$console = Join-Path $AppDir 'start-till.cmd'
$icon = $installedExe

$startMenu = Join-Path ([Environment]::GetFolderPath('Programs')) $Product
if (-not (Test-Path -LiteralPath $startMenu)) { New-Item -ItemType Directory -Path $startMenu -Force | Out-Null }

New-Shortcut -Path (Join-Path $startMenu ($Product + '.lnk')) -Target $launcher -WorkingDirectory $AppDir -Icon $icon
New-Shortcut -Path (Join-Path $startMenu ($Product + ' (console).lnk')) -Target $console -WorkingDirectory $AppDir -Icon $icon
New-Shortcut -Path (Join-Path $startMenu 'Health check.lnk') -Target 'powershell.exe' `
    -Arguments ('-NoProfile -ExecutionPolicy Bypass -File "{0}"' -f (Join-Path $AppDir 'verify.ps1')) `
    -WorkingDirectory $AppDir -Icon $icon
New-Shortcut -Path (Join-Path $startMenu ('Uninstall ' + $Product + '.lnk')) -Target 'powershell.exe' `
    -Arguments ('-NoProfile -ExecutionPolicy Bypass -File "{0}"' -f (Join-Path $AppDir 'uninstall.ps1')) `
    -WorkingDirectory $AppDir

New-Shortcut -Path (Join-Path ([Environment]::GetFolderPath('Desktop')) ($Product + '.lnk')) -Target $launcher -WorkingDirectory $AppDir -Icon $icon

# Autostart follows the choice already stored on this PC unless this run states
# one, so an update can neither start nor stop opening the till by accident.
$wantAutostart = $true
if ($PSBoundParameters.ContainsKey('SkipAutostart')) {
    $wantAutostart = $false
}
else {
    $storedAutostart = Get-StoredValue -Name 'Autostart'
    if ($null -ne $storedAutostart) { $wantAutostart = ([int]$storedAutostart -eq 1) }
}

$startupShortcut = Join-Path ([Environment]::GetFolderPath('Startup')) ($Product + '.lnk')
if ($wantAutostart) {
    Write-Log 'making the till start with Windows'
    New-Shortcut -Path $startupShortcut -Target $launcher -WorkingDirectory $AppDir -Icon $icon
}
elseif (Test-Path -LiteralPath $startupShortcut) {
    Write-Log 'removing the Start-up shortcut (autostart is off for this install)'
    Remove-Item -LiteralPath $startupShortcut -Force
}

# -- 8. Apps & features, and what this install chose --------------------------
Write-Log 'registering the app'
if (-not (Test-Path -LiteralPath $UninstallKey)) { New-Item -Path $UninstallKey -Force | Out-Null }

$uninstallCommand = 'powershell.exe -NoProfile -ExecutionPolicy Bypass -File "{0}"' -f (Join-Path $AppDir 'uninstall.ps1')
$size = (Get-ChildItem -LiteralPath $Root -Recurse -File -ErrorAction SilentlyContinue | Measure-Object -Property Length -Sum).Sum

Set-ItemProperty -LiteralPath $UninstallKey -Name 'DisplayName'            -Value $Product
Set-ItemProperty -LiteralPath $UninstallKey -Name 'DisplayVersion'         -Value $version
Set-ItemProperty -LiteralPath $UninstallKey -Name 'Publisher'              -Value $Publisher
Set-ItemProperty -LiteralPath $UninstallKey -Name 'InstallLocation'        -Value $Root
Set-ItemProperty -LiteralPath $UninstallKey -Name 'DisplayIcon'            -Value $icon
Set-ItemProperty -LiteralPath $UninstallKey -Name 'UninstallString'        -Value $uninstallCommand
Set-ItemProperty -LiteralPath $UninstallKey -Name 'QuietUninstallString'   -Value ($uninstallCommand + ' -Silent')
Set-ItemProperty -LiteralPath $UninstallKey -Name 'NoModify'               -Value 1 -Type DWord
Set-ItemProperty -LiteralPath $UninstallKey -Name 'NoRepair'               -Value 1 -Type DWord
Set-ItemProperty -LiteralPath $UninstallKey -Name 'InstallDate'            -Value (Get-Date -Format 'yyyyMMdd')
Set-ItemProperty -LiteralPath $UninstallKey -Name 'EstimatedSize'          -Value ([int]($size / 1KB)) -Type DWord

# What this install chose, so the next one can keep it instead of guessing.
if (-not (Test-Path -LiteralPath $SettingsKey)) { New-Item -Path $SettingsKey -Force | Out-Null }
Set-ItemProperty -LiteralPath $SettingsKey -Name 'Port'        -Value $Port -Type DWord
Set-ItemProperty -LiteralPath $SettingsKey -Name 'Host'        -Value '127.0.0.1'
Set-ItemProperty -LiteralPath $SettingsKey -Name 'InstallPath' -Value $Root
Set-ItemProperty -LiteralPath $SettingsKey -Name 'AppPath'     -Value $AppDir
Set-ItemProperty -LiteralPath $SettingsKey -Name 'DataPath'    -Value $DataDir
Set-ItemProperty -LiteralPath $SettingsKey -Name 'LogPath'     -Value $LogDir
Set-ItemProperty -LiteralPath $SettingsKey -Name 'Version'     -Value $version
Set-ItemProperty -LiteralPath $SettingsKey -Name 'Autostart'   -Value $(if ($wantAutostart) { 1 } else { 0 }) -Type DWord
Set-ItemProperty -LiteralPath $SettingsKey -Name 'InstalledAt' -Value (Get-Date -Format 'o')

# -- 9. done ------------------------------------------------------------------
Write-Log ('installed {0} {1} into {2}' -f $Product, $version, $AppDir) 'OK'
Write-Log ('data folder kept at {0}' -f $DataDir) 'OK'

if (-not $script:Quiet) {
    Write-Host ''
    Write-Host ('  {0} {1} is installed.' -f $Product, $version) -ForegroundColor Green
    Write-Host ''
    Write-Host '  Next:' -ForegroundColor Green
    Write-Host ('    1. The till opens in your browser at http://127.0.0.1:{0}/' -f $Port)
    Write-Host '    2. In Settings -> This till, enter the server address and the'
    Write-Host '       activation code your administrator created under Settings -> Devices.'
    Write-Host '    3. Add a staff sign-in so the shop can log in with no internet.'
    Write-Host ''
    Write-Host ('  Program : {0}' -f $AppDir)
    Write-Host ('  Data    : {0}   (never replaced by an update)' -f $DataDir)
    Write-Host ('  Log     : {0}' -f $LogFile)
    if (-not $portFree) {
        Write-Host ''
        Write-Host ('  Note: port {0} is in use by another program on this PC.' -f $Port) -ForegroundColor Yellow
    }
    Write-Host ''
    Write-Host '  Uninstall any time from Apps & features; your data is kept.'
    Write-Host ''
}

if ($NoLaunch) { exit 3 }
exit 0
