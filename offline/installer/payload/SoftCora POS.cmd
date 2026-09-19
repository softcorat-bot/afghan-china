@echo off
rem SoftCora POS — starts the till (if it is not already running) and opens the screen.
rem The installer overwrites this file with one that knows the configured port and
rem data folder; this copy is what runs when the payload is used by hand.
setlocal
set "SOFTCORA_DATA=%LOCALAPPDATA%\SoftCoraPOS\data"
set "SOFTCORA_HOST=127.0.0.1"
set "SOFTCORA_PORT=7817"
cd /d "%LOCALAPPDATA%\SoftCoraPOS\app"

curl -s -o NUL --max-time 2 http://127.0.0.1:7817/api/device >NUL 2>&1
if errorlevel 1 (
  start "" /min "%~dp0start-till.cmd"
  timeout /t 3 /nobreak >NUL
)

start "" "http://127.0.0.1:7817/"
exit /b 0
