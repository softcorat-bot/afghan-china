<#
    SoftCora POS - run a built installer on a real Windows PC.

    Everything else in this repository checks the installer without a Windows
    machine: the payload checker reads the scripts, the build self-tests the
    packaged blob, the e2e suite runs the till's engine. None of them runs
    SoftCora-POS.exe itself. This script is that missing step, and it runs the
    exact file a shop downloads:

      * unpacks the setup .exe the way the SFX stub does;
      * runs the program inside it (--cli status, --cli verify) in a throw-away
        data folder - the check the installer itself makes before it copies
        anything, which is where a broken build stops on a real PC;
      * starts the till and fetches its screen and its API over HTTP;
      * with -Install, runs the installer as a user would, then reads
        install.log and verify.ps1 to see that both say what they should.

    It exists because of a real failure. A build was made on a Linux host by the
    host's own Node 22 while the till ships a Node 26 runtime, and a
    single-executable blob is only readable by the exact version that wrote it.
    Every check in the pipeline passed; on a shop's PC the program aborted at
    startup with

        #  SoftCora-POS.exe[14272]: SeaResource node::sea::(anonymous
        #  namespace)::SeaDeserializer::Read() at src/node_sea.cc:172
        #  Assertion failed: (format_value) <= (static_cast<uint8_t>(ModuleFormat::kModule))

    and setup stopped with "SoftCora-POS.exe did not run on this PC". Running the
    .exe anywhere - this script, or CI's windows-latest runner - shows that in
    seconds, so the build pipeline runs it on Windows before an installer can be
    published.

    Usage (Windows PowerShell 5.1; no administrator rights needed):

        powershell -ExecutionPolicy Bypass -File verify-windows.ps1 -Installer C:\build\SoftCora-POS-Setup.exe -Install

        powershell -ExecutionPolicy Bypass -File verify-windows.ps1 -Exe C:\build\app\SoftCora-POS.exe

    Parameters:
      -Installer <path>  the setup .exe to unpack and check (needs 7-Zip)
      -Exe <path>        check an already-extracted SoftCora-POS.exe instead
      -Install          also run the installer into %LOCALAPPDATA%\SoftCoraPOS
                        (this writes to this PC's user profile - use it on a
                        throw-away VM, a CI runner or a bench PC)
      -Json             print one machine-readable object instead of the report
      -Report <path>    also write that object to a file (CI keeps it as an
                        artifact, so a failure can be read after the runner is
                        gone)
      -Port <n>         port for the till the checks start (default 7821)

    On a GitHub runner every failed check is also emitted as a workflow
    annotation and the whole report as a step summary, because a CI log is not
    always readable - and the job that runs this script is the only place the
    installer is executed before it reaches a shop.

    Exit codes:
      0  every check passed
      1  at least one check failed - the report says which, and why

    THIS FILE MUST STAY PURE ASCII with CRLF line endings. It is read by the same
    Windows PowerShell 5.1 that misreads a mark-less .ps1 with the machine's ANSI
    code page - see the note at the top of payload/install.ps1 for what that does
    to a script containing a typographic dash. installer/check-payload.mjs checks
    this file along with the payload.
#>
#Requires -Version 5.1
[CmdletBinding()]
param(
    [string]$Installer,
    [string]$Exe,
    [switch]$Install,
    [switch]$Json,
    [string]$Report = '',
    [int]$Port = 7821
)

$ErrorActionPreference = 'Stop'

$ExeName = 'SoftCora-POS.exe'
$script:Reports = New-Object System.Collections.ArrayList
$script:Failed = 0
$script:Json = $false

# An unforeseen error ends the run like a failed check does: the report is still
# printed (and written, and annotated), the message still says which line broke,
# and the exit code is still non-zero. A CI job whose whole output is "exit code
# 1" costs another round trip through the pipeline, and the next person reading
# this file should not have to guess which of its steps stopped.
trap {
    $where = ''
    if ($_.InvocationInfo -and $_.InvocationInfo.ScriptLineNumber) { $where = ' (line ' + $_.InvocationInfo.ScriptLineNumber + ')' }
    $message = [string]$_.Exception.Message + $where

    [void]$script:Reports.Add([pscustomobject]@{ name = 'the verifier ran to the end'; ok = $false; detail = $message })
    $script:Failed++

    if ($Json) { [pscustomobject]@{ ok = $false; checks = $script:Reports } | ConvertTo-Json -Depth 5 }
    else { Write-Host ('  FAIL the verifier stopped:' + $message) -ForegroundColor Red }

    if ($env:GITHUB_ACTIONS) {
        [Console]::Out.WriteLine(('::error title=verify-windows stopped::' + (($message -replace '\s+', ' ').Trim() -replace '%', '%25')))
    }
    if ($Report) {
        try { ([pscustomobject]@{ ok = $false; checks = $script:Reports } | ConvertTo-Json -Depth 5) | Out-File -LiteralPath $Report -Encoding utf8 } catch { }
    }
    exit 1
}

# A workflow annotation survives the runner: the job's log does not always, and
# the JSON in the log is not something a person reads at a glance.
function Write-Annotation {
    param([string]$Title, [string]$Message, [string]$Level = 'error')

    if (-not $env:GITHUB_ACTIONS) { return }
    $single = (($Message -replace '\s+', ' ').Trim() -replace '%', '%25')
    if ($single.Length -gt 500) { $single = $single.Substring(0, 500) + '...' }
    [Console]::Out.WriteLine(('::{0} title={1}::{2}' -f $Level, $Title, $single))
}

function Write-StepSummary {
    param([string]$Text)

    if (-not $env:GITHUB_STEP_SUMMARY) { return }
    try { Add-Content -LiteralPath $env:GITHUB_STEP_SUMMARY -Value $Text -ErrorAction SilentlyContinue } catch { }
}

# Which part of the run is speaking, in the log only: with -Json the output has
# to stay one object.
function Write-Phase {
    param([string]$Name)

    if (-not $Json) { Write-Host ''; Write-Host ('  -- ' + $Name) -ForegroundColor Cyan }
}

function Add-Check {
    param(
        [Parameter(Mandatory = $true)][string]$Name,
        [Parameter(Mandatory = $true)][bool]$Ok,
        [string]$Detail = ''
    )

    [void]$script:Reports.Add([pscustomobject]@{ name = $Name; ok = $Ok; detail = $Detail })
    if (-not $Ok) { $script:Failed++ }

    if (-not $Json) {
        if ($Ok) { Write-Host ('  ok   ' + $Name) -ForegroundColor Green }
        else { Write-Host ('  FAIL ' + $Name) -ForegroundColor Red }
        if ($Detail) { Write-Host ('       ' + $Detail) -ForegroundColor DarkGray }
    }
}

function New-TempFolder {
    param([string]$Prefix = 'softcora-verify-')

    $path = Join-Path $env:TEMP ($Prefix + [guid]::NewGuid().ToString('n'))
    New-Item -ItemType Directory -Path $path -Force | Out-Null
    return $path
}

function Find-SevenZip {
    # On PATH first (a Windows runner has it, a bench PC may not), then the
    # places an installer puts it: 7-Zip itself, and the Chocolatey shims.
    foreach ($name in @('7z.exe', '7za.exe')) {
        $command = Get-Command $name -ErrorAction SilentlyContinue
        if ($command -and $command.Source) { return $command.Source }
    }

    $roots = @($env:ProgramFiles, ${env:ProgramFiles(x86)}, $env:ProgramData, $env:ChocolateyInstall)
    foreach ($root in $roots) {
        if (-not $root) { continue }
        foreach ($relative in @('7-Zip\7z.exe', 'chocolatey\bin\7z.exe', '7z.exe')) {
            $candidate = Join-Path $root $relative
            if (Test-Path -LiteralPath $candidate) { return $candidate }
        }
    }

    return $null
}

# Puts a set of SOFTCORA_* variables into this process for as long as it takes to
# start a child, and hands back what was there before. The child inherits them at
# creation, so nothing else on this machine ever sees them.
function Set-ChildEnvironment {
    param([hashtable]$Environment)

    $previous = @{}
    foreach ($name in $Environment.Keys) {
        $previous[$name] = [Environment]::GetEnvironmentVariable($name)
        [Environment]::SetEnvironmentVariable($name, $Environment[$name])
    }

    return $previous
}

function Restore-Environment {
    param([hashtable]$Previous)

    foreach ($name in $Previous.Keys) { [Environment]::SetEnvironmentVariable($name, $Previous[$name]) }
}

function New-ChildEnvironment {
    param([string]$DataPath, [hashtable]$Environment)

    $wanted = @{ 'SOFTCORA_HOST' = '127.0.0.1' }
    if ($DataPath) { $wanted['SOFTCORA_DATA'] = $DataPath }
    if ($Environment) { foreach ($name in $Environment.Keys) { $wanted[$name] = $Environment[$name] } }

    return $wanted
}

# Runs a program to completion with redirected output and a timeout - the way
# install.ps1 proves the payload runs on a PC.
function Invoke-Program {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][string[]]$Arguments,
        [string]$DataPath = '',
        [hashtable]$Environment = $null,
        [int]$TimeoutSeconds = 120
    )

    $outFile = Join-Path $env:TEMP ('softcora-out-' + [guid]::NewGuid().ToString('n') + '.txt')
    $errFile = Join-Path $env:TEMP ('softcora-err-' + [guid]::NewGuid().ToString('n') + '.txt')
    $result = [pscustomobject]@{ ExitCode = $null; StdOut = ''; StdErr = ''; TimedOut = $false }

    $previous = Set-ChildEnvironment -Environment (New-ChildEnvironment -DataPath $DataPath -Environment $Environment)

    try {
        $process = Start-Process -FilePath $Path -ArgumentList $Arguments -NoNewWindow -PassThru -RedirectStandardOutput $outFile -RedirectStandardError $errFile

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
        Restore-Environment -Previous $previous
        Remove-Item -LiteralPath $outFile, $errFile -Force -ErrorAction SilentlyContinue
    }

    return $result
}

# Starts a program and leaves it running: the till itself, when the checks need to
# talk to it over HTTP.
function Start-ChildProgram {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][string[]]$Arguments,
        [string]$DataPath = '',
        [hashtable]$Environment = $null,
        [string]$OutFile = '',
        [string]$ErrFile = ''
    )

    $previous = Set-ChildEnvironment -Environment (New-ChildEnvironment -DataPath $DataPath -Environment $Environment)

    try {
        if ($OutFile) {
            return Start-Process -FilePath $Path -ArgumentList $Arguments -NoNewWindow -PassThru -RedirectStandardOutput $OutFile -RedirectStandardError $ErrFile
        }

        return Start-Process -FilePath $Path -ArgumentList $Arguments -NoNewWindow -PassThru
    }
    finally {
        Restore-Environment -Previous $previous
    }
}

# The program's own words, collapsed onto one line, for a failure report: a
# Windows assertion dump is worth more than a message that says nothing.
function Summarise-Output {
    param([string]$Text)

    if (-not $Text) { return '' }
    $single = ($Text -replace '\s+', ' ').Trim()
    if ($single.Length -gt 600) { return $single.Substring(0, 600) + '...' }
    return $single
}

function Get-ParsedJson {
    param([string]$Text)

    try { return ($Text | ConvertFrom-Json) } catch { return $null }
}

function Stop-Child {
    param($Process)

    if ($null -eq $Process) { return }
    try {
        if (-not $Process.HasExited) { Stop-Process -Id $Process.Id -Force -ErrorAction SilentlyContinue }
    }
    catch { }
}

function Get-HttpAnswer {
    param([string]$Url)

    try {
        $answer = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 5 -ErrorAction Stop
        return [pscustomobject]@{ Code = [int]$answer.StatusCode; Body = [string]$answer.Content }
    }
    catch {
        return [pscustomobject]@{ Code = 0; Body = '' }
    }
}

function Read-LogTail {
    param([string]$Path)

    if (-not (Test-Path -LiteralPath $Path)) { return '' }
    $text = [string](Get-Content -LiteralPath $Path -Raw -ErrorAction SilentlyContinue)
    return (Summarise-Output $text)
}

# ---------------------------------------------------------------------------
# 0. what to check
# ---------------------------------------------------------------------------
if (-not $Json) {
    Write-Host ''
    Write-Host '  SoftCora POS - verify a Windows build'
    Write-Host '  ------------------------------------'
    Write-Host ''
}

if (-not $Installer -and -not $Exe) {
    Write-Host '  Give me something to check: -Installer <setup.exe> or -Exe <SoftCora-POS.exe>.'
    exit 1
}

$workRoot = $null

if ($Installer) {
    $Installer = (Resolve-Path -LiteralPath $Installer).Path
    $sevenZip = Find-SevenZip

    if (-not $sevenZip) {
        Add-Check -Name '7-Zip is available to unpack the setup' -Ok $false -Detail 'install 7-Zip (the windows-latest CI runner already has it)'
    }
    else {
        $workRoot = New-TempFolder
        $unpack = Join-Path $workRoot 'payload'
        # Start-Process, not a call with 2>&1: in Windows PowerShell 5.1 a native
        # command's stderr merged into the pipeline can raise a terminating error
        # under $ErrorActionPreference = 'Stop', which would end this script with
        # no report at all - the exact way a CI failure becomes unreadable.
        $sevenOut = Join-Path $workRoot 'sevenzip.out.txt'
        $sevenErr = Join-Path $workRoot 'sevenzip.err.txt'
        $seven = Start-Process -FilePath $sevenZip `
            -ArgumentList @('x', ('-o"' + $unpack + '"'), '-y', ('"' + $Installer + '"')) `
            -NoNewWindow -Wait -PassThru -RedirectStandardOutput $sevenOut -RedirectStandardError $sevenErr
        $sevenCode = $seven.ExitCode
        $sevenDetail = $Installer + ' (unpacked with ' + $sevenZip + ')'
        if ($sevenCode -ne 0) { $sevenDetail = Summarise-Output ((Read-LogTail $sevenOut) + ' ' + (Read-LogTail $sevenErr)) }

        Add-Check -Name 'the setup .exe unpacks' -Ok ($sevenCode -eq 0) -Detail $sevenDetail

        if ($sevenCode -eq 0 -and -not (Test-Path -LiteralPath (Join-Path $unpack 'install.cmd'))) {
            # The stub is a 7-Zip archive in front of a 7-Zip archive, and which
            # of the two a given 7-Zip build opens depends on how it probes the
            # file. Both layouts are accepted: if the payload arrived as the
            # inner archive, it is unpacked in turn.
            $inner = @(Get-ChildItem -LiteralPath $unpack -Filter '*.7z' -File -ErrorAction SilentlyContinue)
            foreach ($archive in $inner) {
                $innerOut = Join-Path $workRoot 'payload-inner'
                Start-Process -FilePath $sevenZip -ArgumentList @('x', ('-o"' + $innerOut + '"'), '-y', ('"' + $archive.FullName + '"')) `
                    -NoNewWindow -Wait -RedirectStandardOutput (Join-Path $workRoot 'sevenzip2.out.txt') -RedirectStandardError (Join-Path $workRoot 'sevenzip2.err.txt')
                if (Test-Path -LiteralPath (Join-Path $innerOut 'install.cmd')) { $unpack = $innerOut; break }
            }
        }

        if ($sevenCode -eq 0) {
            $required = @('install.cmd', 'install.ps1', 'uninstall.ps1', 'verify.ps1', 'app\VERSION.txt', 'app\RUNTIME.txt', ('app\' + $ExeName))
            $missing = @()
            foreach ($file in $required) {
                if (-not (Test-Path -LiteralPath (Join-Path $unpack $file))) { $missing += $file }
            }

            $payloadDetail = 'all of ' + ($required -join ', ')
            if ($missing.Count -gt 0) { $payloadDetail = 'missing: ' + ($missing -join ', ') }
            Add-Check -Name 'the payload contains the whole installer' -Ok ($missing.Count -eq 0) -Detail $payloadDetail

            $Exe = Join-Path $unpack ('app\' + $ExeName)
        }
    }
}

if (-not $Exe -or -not (Test-Path -LiteralPath $Exe)) {
    Add-Check -Name 'there is a program to run' -Ok $false -Detail ('looked for ' + $Exe)
    if ($workRoot) { Remove-Item -LiteralPath $workRoot -Recurse -Force -ErrorAction SilentlyContinue }
    if ($Json) { [pscustomobject]@{ ok = $false; checks = $script:Reports } | ConvertTo-Json -Depth 5 }
    exit 1
}

$Exe = (Resolve-Path -LiteralPath $Exe).Path
if (-not $Json) {
    Write-Host ('  program  : ' + $Exe)
    Write-Host ('  powershell: ' + $PSVersionTable.PSVersion)
    if ($workRoot) { Write-Host ('  7-Zip    : ' + $sevenZip) }
    Write-Host ''
}

# ---------------------------------------------------------------------------
# 1. the program runs at all - the first thing the installer checks on a shop PC
# ---------------------------------------------------------------------------
Write-Phase 'the program inside the installer'

$probeData = New-TempFolder 'softcora-probe-'
$probe = Invoke-Program -Path $Exe -Arguments @('--cli', 'status') -DataPath $probeData

if ($probe.TimedOut) {
    Add-Check -Name 'the packaged program runs' -Ok $false -Detail ($ExeName + ' did not answer within 120 seconds')
}
elseif ($null -eq $probe.ExitCode) {
    Add-Check -Name 'the packaged program runs' -Ok $false -Detail ('it exited without reporting a code; output: ' + (Summarise-Output ($probe.StdErr + ' ' + $probe.StdOut)))
}
elseif ($probe.ExitCode -ne 0) {
    Add-Check -Name 'the packaged program runs' -Ok $false -Detail ('exit ' + $probe.ExitCode + ': ' + (Summarise-Output ($probe.StdErr + ' ' + $probe.StdOut)))
}
else {
    $status = Get-ParsedJson $probe.StdOut
    $statusDetail = 'it printed something that is not JSON: ' + (Summarise-Output $probe.StdOut)
    if ($null -ne $status) { $statusDetail = '--cli status answered: device ' + $status.device_id + ', state ' + $status.state }
    Add-Check -Name 'the packaged program runs' -Ok ($null -ne $status) -Detail $statusDetail
}

# The same gate the installer applies to an existing database before an update.
$verify = Invoke-Program -Path $Exe -Arguments @('--cli', 'verify') -DataPath $probeData
$verdict = Get-ParsedJson $verify.StdOut
$verifyOk = ($verify.ExitCode -eq 0 -and $null -ne $verdict -and $verdict.ok -eq $true)
$verifyDetail = 'exit ' + $verify.ExitCode + ': ' + (Summarise-Output ($verify.StdErr + ' ' + $verify.StdOut))
if ($null -ne $verdict) { $verifyDetail = 'ok=' + $verdict.ok + ', first_install=' + $verdict.first_install }
Add-Check -Name 'the upgrade check answers' -Ok $verifyOk -Detail $verifyDetail

$database = Join-Path $probeData 'softcora-pos.sqlite'
Add-Check -Name 'the program created its database' -Ok (Test-Path -LiteralPath $database) -Detail $database

# ---------------------------------------------------------------------------
# 2. the till serves its own screen and API
# ---------------------------------------------------------------------------
Write-Phase 'the till, serving its screen and its API'

$serverData = New-TempFolder 'softcora-serve-'
$serverOut = Join-Path $serverData 'server.out.txt'
$serverErr = Join-Path $serverData 'server.err.txt'
$serverProcess = $null
$base = 'http://127.0.0.1:{0}' -f $Port

try {
    $serverProcess = Start-ChildProgram -Path $Exe -Arguments @('--cli', 'serve') -DataPath $serverData -Environment @{ 'SOFTCORA_PORT' = [string]$Port } -OutFile $serverOut -ErrFile $serverErr

    $up = $false
    for ($attempt = 0; $attempt -lt 40; $attempt++) {
        Start-Sleep -Milliseconds 500
        $answer = Get-HttpAnswer ($base + '/api/device')
        if ($answer.Code -eq 200) { $up = $true; break }
    }

    $upDetail = Read-LogTail $serverErr
    if ($up) { $upDetail = $base + '/api/device answered 200' }
    Add-Check -Name 'the till serves its API' -Ok $up -Detail $upDetail

    if ($up) {
        $screen = Get-HttpAnswer ($base + '/')
        $screenOk = ($screen.Code -eq 200 -and $screen.Body -match 'SoftCora POS')
        Add-Check -Name 'the embedded screen is served' -Ok $screenOk -Detail ($screen.Code.ToString() + ', ' + $screen.Body.Length + ' bytes')

        foreach ($asset in @('/app.js', '/styles.css')) {
            $assetAnswer = Get-HttpAnswer ($base + $asset)
            $assetOk = ($assetAnswer.Code -eq 200 -and $assetAnswer.Body.Length -gt 0)
            Add-Check -Name ('the embedded ' + $asset + ' is served') -Ok $assetOk -Detail ($assetAnswer.Code.ToString() + ', ' + $assetAnswer.Body.Length + ' bytes')
        }
    }
}
finally {
    Stop-Child -Process $serverProcess
    Remove-Item -LiteralPath $serverData -Recurse -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $probeData -Recurse -Force -ErrorAction SilentlyContinue
}

# ---------------------------------------------------------------------------
# 3. the installer itself, on this PC (opt in: it writes to this user profile)
# ---------------------------------------------------------------------------
if ($Install -and $workRoot) {
    $payloadRoot = Join-Path $workRoot 'payload'
    $installedRoot = Join-Path $env:LOCALAPPDATA 'SoftCoraPOS'
    $installedExe = Join-Path $installedRoot ('app\' + $ExeName)
    $installLog = Join-Path $installedRoot 'logs\install.log'
    $shopData = Join-Path $installedRoot 'data'

    Write-Phase 'the installer, as a shop runs it'

    $versionLine = [string](Get-Content -LiteralPath (Join-Path $payloadRoot 'app\VERSION.txt') -TotalCount 1 -ErrorAction SilentlyContinue)
    $expectedVersion = ($versionLine.Trim() -split '\s+')[-1]

    # install.cmd is what the SFX stub runs. -NoLaunch keeps this script in
    # control of the machine instead of leaving a till running on it, and
    # -SkipAutostart keeps the installer out of this user's Startup folder.
    $comspec = $env:ComSpec
    $installRun = Invoke-Program -Path $comspec `
        -Arguments @('/c', ('"' + (Join-Path $payloadRoot 'install.cmd') + '"'), '-NoLaunch', '-SkipAutostart') `
        -TimeoutSeconds 600

    $installOk = ($installRun.ExitCode -eq 0)
    $installDetail = 'exit ' + $installRun.ExitCode + ': ' + (Summarise-Output ($installRun.StdErr + ' ' + $installRun.StdOut))
    if ($installOk) { $installDetail = 'install.cmd exited 0' }
    Add-Check -Name 'the installer runs to the end' -Ok $installOk -Detail $installDetail

    if (Test-Path -LiteralPath $installLog) {
        $logLines = @(Get-Content -LiteralPath $installLog)
        $errors = @($logLines | Where-Object { $_ -match '\[ERROR\]' })
        $logDetail = (Split-Path -Leaf $installLog) + ', ' + $logLines.Count + ' line(s)'
        if ($errors.Count -gt 0) { $logDetail = ($errors -join ' | ') }
        Add-Check -Name 'install.log has no errors in it' -Ok ($errors.Count -eq 0) -Detail $logDetail

        $installedLines = @($logLines | Where-Object { $_ -match 'installed SoftCora POS' })
        $installedDetail = 'no line naming the installed version'
        if ($installedLines.Count -gt 0) { $installedDetail = $installedLines[-1].Trim() }
        Add-Check -Name 'install.log says what it installed' -Ok ($installedLines.Count -gt 0) -Detail $installedDetail
    }
    else {
        Add-Check -Name 'the installer left a log behind' -Ok $false -Detail $installLog
    }

    Add-Check -Name 'the program is installed where Windows expects it' -Ok (Test-Path -LiteralPath $installedExe) -Detail $installedExe

    if (Test-Path -LiteralPath $installedExe) {
        $installedHash = (Get-FileHash -LiteralPath $installedExe -Algorithm SHA256).Hash
        $payloadHash = (Get-FileHash -LiteralPath $Exe -Algorithm SHA256).Hash
        Add-Check -Name 'the installed program is the payload, byte for byte' -Ok ($installedHash -eq $payloadHash) -Detail ('sha256 ' + $installedHash)
    }

    # Nothing may have created a database in the shop's data folder: that folder
    # is the shop's, and installing does not sell anything.
    $shopDatabase = Join-Path $shopData 'softcora-pos.sqlite'
    $shopOk = -not (Test-Path -LiteralPath $shopDatabase)
    $shopDetail = 'no new database in ' + $shopData
    if (-not $shopOk) { $shopDetail = 'a database appeared at ' + $shopDatabase }
    Add-Check -Name 'the shop data folder is untouched' -Ok $shopOk -Detail $shopDetail

    $health = Invoke-Program -Path 'powershell.exe' `
        -Arguments @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (Join-Path $installedRoot 'app\verify.ps1'), '-Json') `
        -DataPath $shopData `
        -TimeoutSeconds 180
    $report = Get-ParsedJson $health.StdOut
    $healthOk = ($health.ExitCode -eq 0 -and $null -ne $report -and $report.installed -eq $true)
    $healthDetail = 'exit ' + $health.ExitCode + ': ' + (Summarise-Output ($health.StdErr + ' ' + $health.StdOut))
    if ($null -ne $report) { $healthDetail = 'installed=' + $report.installed + ', version ' + $report.version + ', problems ' + @($report.problems).Count }
    Add-Check -Name 'verify.ps1 reports the installation as healthy' -Ok $healthOk -Detail $healthDetail

    if ($null -ne $report -and $report.version) {
        Add-Check -Name 'the installed version is the version in the payload' -Ok ($report.version -eq $expectedVersion) -Detail ('payload ' + $expectedVersion + ', installed ' + $report.version)
    }
}
elseif ($Install) {
    Add-Check -Name 'the installer runs on this PC' -Ok $false -Detail '-Install needs -Installer (the setup .exe to unpack)'
}

# ---------------------------------------------------------------------------
# 4. what happened
# ---------------------------------------------------------------------------
if ($workRoot) { Remove-Item -LiteralPath $workRoot -Recurse -Force -ErrorAction SilentlyContinue }

$ok = ($script:Failed -eq 0)

if ($Json) {
    [pscustomobject]@{ ok = $ok; checks = $script:Reports } | ConvertTo-Json -Depth 5
}
else {
    Write-Host ''
    if ($ok) {
        Write-Host ('  all ' + $script:Reports.Count + ' checks passed on this Windows PC') -ForegroundColor Green
        Write-Host ''
    }
    else {
        Write-Host ('  ' + $script:Failed + ' of ' + $script:Reports.Count + ' checks failed - this build must not ship') -ForegroundColor Red
        Write-Host ''
    }
}

# Failures are said out loud three ways, because the person who has to fix the
# next one may have nothing but the run's annotations to go on: one annotation
# per failed check, the report on disk for -Report, and the whole report as the
# step summary.
foreach ($check in $script:Reports) {
    if (-not $check.ok) { Write-Annotation -Title 'verify-windows' -Message ($check.name + ': ' + $check.detail) }
}

$lines = @('SoftCora POS - the installer on a Windows PC: ' + $script:Reports.Count + ' checks, ' + $script:Failed + ' failed', '')
foreach ($check in $script:Reports) {
    $mark = 'ok  '
    if (-not $check.ok) { $mark = 'FAIL' }
    $lines += ('- ' + $mark + ' ' + $check.name + ' - ' + $check.detail)
}
Write-StepSummary ($lines -join "`n")

if ($Report) {
    try {
        ([pscustomobject]@{ ok = $ok; checks = $script:Reports } | ConvertTo-Json -Depth 5) | Out-File -LiteralPath $Report -Encoding utf8
    }
    catch {
        Add-Check -Name 'the report was written where the caller asked' -Ok $false -Detail ([string]$_.Exception.Message)
        $ok = $false
    }
}

if ($ok) { exit 0 }
exit 1
