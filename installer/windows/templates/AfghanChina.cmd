@echo off
rem Afghan China Offline - starts the local server and opens the app.
rem The app is the SAME dashboard as online; it just talks to this PC.
setlocal

set PORT=8080
if not "%AFGHANCHINA_PORT%"=="" set PORT=%AFGHANCHINA_PORT%

set APP_DIR=%~dp0
set PHP=%APP_DIR%php\php.exe

if not exist "%PHP%" (
  echo [Afghan China] Runtime missing: %PHP%
  echo Reinstall from Afghan-China-Offline-Setup.exe
  pause
  exit /b 1
)

cd /d "%APP_DIR%backend" || (
  echo [Afghan China] Backend missing: %APP_DIR%backend
  pause
  exit /b 1
)

if not exist ".env" (
  echo [Afghan China] First run: finishing setup...
  "%PHP%" artisan key:generate --force >nul 2>&1
)

echo [Afghan China] Starting on http://127.0.0.1:%PORT%/app/ ...
start "Afghan China Server" /min "%PHP%" artisan serve --host=127.0.0.1 --port=%PORT%

rem Give the server a moment, then open the dashboard.
timeout /t 3 /nobreak >nul
start "" "http://127.0.0.1:%PORT%/app/"

echo [Afghan China] Running. Close the "Afghan China Server" window to stop it.
endlocal
