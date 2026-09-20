@echo off
rem SoftCora POS - the till itself, with a log. Closing this window stops the
rem till, which is the intended way to stop it: every sale is already committed
rem to the local database, and anything not yet on the server waits in the
rem outbox for the next sync.
rem
rem The installer replaces this file with a generated copy that carries the port
rem and the folders chosen for this PC.
rem
rem TEXT ENCODING: pure ASCII, CRLF line endings (see install.cmd).
setlocal EnableExtensions
title SoftCora POS

set "ROOT=%LOCALAPPDATA%\SoftCoraPOS"
set "SOFTCORA_DATA=%ROOT%\data"
set "SOFTCORA_LOGS=%ROOT%\logs"
set "SOFTCORA_HOST=127.0.0.1"
set "SOFTCORA_PORT=7817"
set "SOFTCORA_TILLLOG=%SOFTCORA_LOGS%\till.log"

if not exist "%SOFTCORA_DATA%" mkdir "%SOFTCORA_DATA%"
if not exist "%SOFTCORA_LOGS%" mkdir "%SOFTCORA_LOGS%"
cd /d "%~dp0"

echo SoftCora POS starting...
echo   data : %SOFTCORA_DATA%
echo   log  : %SOFTCORA_TILLLOG%
echo   url  : http://127.0.0.1:%SOFTCORA_PORT%/
echo   Close this window to stop the till.
echo.

if not exist "%~dp0SoftCora-POS.exe" goto noexe

"%~dp0SoftCora-POS.exe" >> "%SOFTCORA_TILLLOG%" 2>&1
set "RC=%ERRORLEVEL%"

echo.
echo The till stopped with exit code %RC%. The last lines of its log:
echo.
rem The path travels in an environment variable, so no quoting can break it.
powershell -NoProfile -ExecutionPolicy Bypass -Command "if (Test-Path -LiteralPath $env:SOFTCORA_TILLLOG) { Get-Content -LiteralPath $env:SOFTCORA_TILLLOG -Tail 15 }"
echo.
pause
exit /b %RC%

:noexe
echo   SoftCora-POS.exe is not in this folder:
echo     %~dp0
echo   Unpack the portable zip again, or run the installer.
echo.
pause
exit /b 1
