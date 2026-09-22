# Builds the Offline Mode Windows payload from this repository.
#
#   payload/  php/ backend/ launchers  -> compiled by AfghanChinaOffline.iss
#                                    into Afghan-China-Offline-Setup.exe
#
# Usage (Windows, from the repo root):
#   powershell -ExecutionPolicy Bypass -File installer\windows\build-payload.ps1
#
# Usage (CI):
#   pwsh installer/windows/build-payload.ps1 -OutDir dist/payload -AppVersion 1.2.0
#
# Requires on the build machine: php (CLI, 8.4+), composer, node, pnpm.
# What the SHOP's pc needs: nothing. The payload carries its own PHP runtime.
#
# The runtime pin MUST satisfy composer.lock's platform floor (Laravel 13.19 +
# Symfony 8.1 need PHP >= 8.4.1). The 8.4 line ships VS17 (VS2022) binaries.
param(
  [string]$RepoRoot = (Split-Path -Parent (Split-Path -Parent $PSScriptRoot)),
  [string]$OutDir = (Join-Path $PSScriptRoot 'payload'),
  [string]$AppVersion = '1.0.0',
  [string]$PhpVersion = $env:AFGHANCHINA_PHP_VERSION,
  [string]$VsVersion = '17'
)

$ErrorActionPreference = 'Stop'

if (-not $PhpVersion) { $PhpVersion = '8.4.25' }

function Require-Command($name, $hint) {
  if (-not (Get-Command $name -ErrorAction SilentlyContinue)) {
    throw "Missing required tool: $name. $hint"
  }
}

Require-Command 'php' 'Install PHP 8.4+ CLI on the BUILD machine (the payload ships its own runtime).'
Require-Command 'composer' 'Install Composer on the build machine.'
Require-Command 'node' 'Install Node.js on the build machine.'
Require-Command 'pnpm' 'Run: npm install -g pnpm'

$BackendSrc = Join-Path $RepoRoot 'backend'
$FrontendSrc = Join-Path $RepoRoot 'frontend'
$Templates = Join-Path $PSScriptRoot 'templates'

if (-not (Test-Path (Join-Path $BackendSrc 'artisan'))) { throw "Not a repo root: $RepoRoot" }

Write-Host "==> payload -> $OutDir (app $AppVersion, php $PhpVersion)"
if (Test-Path $OutDir) { Remove-Item -Recurse -Force $OutDir }
New-Item -ItemType Directory -Force $OutDir | Out-Null

# ── 1. PHP runtime (pinned embed build) ──────────────────────────────────────
$PhpZip = "php-$PhpVersion-nts-Win32-vs$VsVersion-x64.zip"
$PhpUrl = "https://downloads.php.net/~windows/releases/archives/$PhpZip"
$PhpDir = Join-Path $OutDir 'php'
New-Item -ItemType Directory -Force $PhpDir | Out-Null

$zipPath = Join-Path ([System.IO.Path]::GetTempPath()) $PhpZip
if (-not (Test-Path $zipPath)) {
  Write-Host "==> downloading $PhpUrl"
  try {
    Invoke-WebRequest -Uri $PhpUrl -OutFile $zipPath
  } catch {
    throw "Could not download $PhpUrl. The PHP pin may have moved; retry with -PhpVersion <exact> (see https://www.php.net/downloads.php). $_"
  }
}
Expand-Archive -Path $zipPath -DestinationPath $PhpDir -Force

$embedPhp = Join-Path $PhpDir 'php.exe'
$actual = (& $embedPhp -v | Select-Object -First 1)
Write-Host "==> runtime: $actual"
if ($actual -notmatch [regex]::Escape($PhpVersion)) {
  throw "Runtime version mismatch: expected $PhpVersion, got: $actual"
}

# Minimal php.ini: enable every extension Laravel + SQLite + uploads need.
# Some builds compile core extensions statically (no DLL to enable), others
# ship them as DLLs - probing the ext/ dir keeps this correct either way,
# and never emits "unable to load dynamic library" warnings.
$wanted = @('curl', 'ctype', 'dom', 'fileinfo', 'filter', 'gd', 'hash', 'mbstring',
  'openssl', 'pdo_sqlite', 'session', 'simplexml', 'sodium', 'sqlite3',
  'tokenizer', 'xml', 'zip')
$extLines = foreach ($name in $wanted) {
  if (Test-Path (Join-Path $PhpDir "ext\php_$name.dll")) { "extension=$name" }
}
$ini = @"
; Afghan China Offline - bundled runtime configuration
memory_limit = 512M
max_execution_time = 300
date.timezone = Asia/Kabul
extension_dir = "ext"
$($extLines -join "`r`n")
sqlite3.defensive = 1
"@
[System.IO.File]::WriteAllText((Join-Path $PhpDir 'php.ini'), $ini, [System.Text.Encoding]::ASCII)

# ── 2. Backend (composer, no dev) ────────────────────────────────────────────
$BackendDst = Join-Path $OutDir 'backend'
New-Item -ItemType Directory -Force $BackendDst | Out-Null
Write-Host '==> copying backend'
$exclude = @('.git', 'node_modules', '.env', '.env.*', 'storage\logs\*', 'tests')
Copy-Item -Path (Join-Path $BackendSrc '*') -Destination $BackendDst -Recurse -Force -Exclude $exclude
# .env files may still match oddly; remove defensively (the installer writes .env).
Get-ChildItem -Path $BackendDst -Filter '.env*' -File | Remove-Item -Force -ErrorAction SilentlyContinue

Write-Host '==> composer install (no-dev)'
Push-Location $BackendDst
try {
  & composer install --no-dev --optimize-autoloader --no-interaction --no-progress
  if ($LASTEXITCODE -ne 0) { throw "composer install failed ($LASTEXITCODE)" }
} finally {
  Pop-Location
}

# ── 3. Frontend (same SPA, pointed at the local API) ────────────────────────
Write-Host '==> building frontend'
Push-Location $FrontendSrc
try {
  & pnpm install --frozen-lockfile
  if ($LASTEXITCODE -ne 0) { throw "pnpm install failed ($LASTEXITCODE)" }
  & pnpm build
  if ($LASTEXITCODE -ne 0) { throw "pnpm build failed ($LASTEXITCODE)" }
} finally {
  Pop-Location
}

$dist = Join-Path $FrontendSrc 'dist/spa'
if (-not (Test-Path (Join-Path $dist 'index.html'))) { throw "quasar build produced no dist/spa/index.html" }

$appDir = Join-Path $BackendDst 'public/app'
New-Item -ItemType Directory -Force $appDir | Out-Null
Copy-Item -Path (Join-Path $dist '*') -Destination $appDir -Recurse -Force

# The SPA reads window.__API_URL__ from config.js at startup (already wired in
# index.html). The installer rewrites this file with the real local port.
$configJs = "window.__API_URL__ = 'http://127.0.0.1:8080'" + "`r`n"
[System.IO.File]::WriteAllText((Join-Path $appDir 'config.js'), $configJs, [System.Text.Encoding]::ASCII)

# ── 4. Launchers, templates, version ─────────────────────────────────────────
Copy-Item -Path (Join-Path $Templates '*') -Destination $OutDir -Recurse -Force
[System.IO.File]::WriteAllText((Join-Path $OutDir 'VERSION.txt'), "Afghan China Offline $AppVersion`r`n", [System.Text.Encoding]::ASCII)

# ── 5. Payload lint (the repo's own hard-won lesson) ─────────────────────────
# Windows hosts read these files with legacy code pages: non-ASCII bytes or LF
# endings have bricked installs before. Refuse to ship a payload like that.
$lintErrors = @()
# The php/ runtime ships third-party docs (news.txt, readme-redist-bins.txt) that
# legitimately contain non-ASCII bytes; only our own files must be ASCII-clean.
Get-ChildItem -Path $OutDir -Include *.cmd, *.iss, *.txt, *.template -Recurse |
  Where-Object { $_.FullName -notlike "$PhpDir*" } | ForEach-Object {
  $bytes = [System.IO.File]::ReadAllBytes($_.FullName)
  if ($bytes | Where-Object { $_ -gt 127 }) { $lintErrors += "$($_.Name): non-ASCII byte found" }
}
if ($lintErrors.Count -gt 0) { throw ($lintErrors -join "`n") }

# ── 6. Smoke test the payload's own runtime ──────────────────────────────────
# Laravel's hard requirements (composer.lock) plus the offline stack's SQLite.
$required = @('ctype', 'filter', 'hash', 'mbstring', 'openssl', 'session',
  'tokenizer', 'pdo_sqlite', 'sqlite3')
$missing = @()
foreach ($name in $required) {
  $loaded = (& $embedPhp -r "echo (int) extension_loaded('$name'), PHP_EOL;").Trim()
  if ($loaded -ne '1') { $missing += $name }
}
if ($missing.Count -gt 0) { throw ("Bundled PHP is missing required extensions: " + ($missing -join ', ')) }

Write-Host "==> payload ready: $OutDir"
Get-ChildItem $OutDir | Format-Table Name
