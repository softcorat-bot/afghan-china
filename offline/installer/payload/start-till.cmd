@echo off
rem SoftCora POS — the till itself, with a log. Closing this window stops the till.
setlocal
set "SOFTCORA_DATA=%LOCALAPPDATA%\SoftCoraPOS\data"
set "SOFTCORA_HOST=127.0.0.1"
set "SOFTCORA_PORT=7817"
cd /d "%LOCALAPPDATA%\SoftCoraPOS\app"
echo SoftCora POS starting... data: %SOFTCORA_DATA%
"%LOCALAPPDATA%\SoftCoraPOS\app\SoftCora-POS.exe" >> "%LOCALAPPDATA%\SoftCoraPOS\logs\till.log" 2>&1
