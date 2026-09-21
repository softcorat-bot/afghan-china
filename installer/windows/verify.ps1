# End-to-end check of an installed Afghan China Offline on Windows.
# Used by CI (on a real Windows runner) and by admins after installing.
#
#   powershell -ExecutionPolicy Bypass -File verify.ps1 -InstallDir "C:\Program Files\Afghan China Offline"
param(
  [string]$InstallDir = 'C:\Program Files\Afghan China Offline',
  [int]$Port = 8080
)

$ErrorActionPreference = 'Stop'
$failures = @()

function Check($name, [scriptblock]$test) {
  try {
    &$test
    Write-Host "[ok] $name"
  } catch {
    $script:failures += "$name : $_"
    Write-Host "[FAIL] $name : $_"
  }
}

$php = Join-Path $InstallDir 'php\php.exe'
$artisanDir = Join-Path $InstallDir 'backend'
$spa = Join-Path $InstallDir 'backend\public\app\index.html'

Check 'payload present' {
  if (-not (Test-Path $php)) { throw "missing $php" }
  if (-not (Test-Path (Join-Path $artisanDir 'artisan'))) { throw 'missing backend\artisan' }
  if (-not (Test-Path $spa)) { throw 'missing backend\public\app\index.html' }
}

Check 'bundled php runs with sqlite' {
  $v = (& $php -v | Select-Object -First 1)
  if ($v -notmatch 'PHP 8\.3') { throw "unexpected runtime: $v" }
  $sqlite = (& $php -r "echo (int) extension_loaded('pdo_sqlite"), PHP_EOL;")
  if ($sqlite.Trim() -ne '1') { throw 'pdo_sqlite not loaded' }
}

Check 'offline agent answers' {
  Push-Location $artisanDir
  try {
    $out = (& $php artisan offline:status 2>&1 | Out-String)
    if ($out -notmatch 'Mode:\s+OFFLINE') { throw "not in offline mode:`n$out" }
  } finally { Pop-Location }
}

Check 'server boots and serves api + app' {
  Push-Location $artisanDir
  try {
    $server = Start-Process -FilePath $php -ArgumentList @('artisan', 'serve', '--host=127.0.0.1', "--port=$Port") -PassThru -WindowStyle Hidden
    try {
      $up = $null
      for ($i = 0; $i -lt 30; $i++) {
        try {
          $up = Invoke-WebRequest -Uri "http://127.0.0.1:$Port/up" -TimeoutSec 3 -UseBasicParsing
          if ($up.StatusCode -eq 200) { break }
        } catch { Start-Sleep -Seconds 2 }
      }
      if (-not $up -or $up.StatusCode -ne 200) { throw 'GET /up never answered 200' }

      $app = Invoke-WebRequest -Uri "http://127.0.0.1:$Port/app/" -TimeoutSec 10 -UseBasicParsing
      if ($app.StatusCode -ne 200 -or $app.Content -notmatch 'Afghan') { throw 'GET /app/ did not serve the dashboard' }

      $api = Invoke-WebRequest -Uri "http://127.0.0.1:$Port/api/offline/status" -TimeoutSec 10 -UseBasicParsing -SkipHttpErrorCheck
      if ($api.StatusCode -ne 401 -and $api.StatusCode -ne 200) { throw "GET /api/offline/status -> $($api.StatusCode), expected 401 (auth) or 200" }
    } finally {
      Stop-Process -Id $server.Id -Force -ErrorAction SilentlyContinue
    }
  } finally { Pop-Location }
}

if ($failures.Count -gt 0) {
  Write-Host ''
  Write-Host 'VERIFY FAILED:'
  $failures | ForEach-Object { Write-Host " - $_" }
  exit 1
}

Write-Host ''
Write-Host 'VERIFY PASSED'
