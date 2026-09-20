@echo off
rem SoftCora POS - starts the till if it is not already running, then opens the
rem screen in the default browser.
rem
rem The installer replaces this file with a generated copy that carries the port
rem and the folders chosen for this PC; this one is what runs when the payload is
rem used by hand, for example from the portable zip.
rem
rem Data and logs stay in %LOCALAPPDATA%\SoftCoraPOS whichever way the till is
rem started, so one PC has one database and one identity.
rem
rem TEXT ENCODING: pure ASCII, CRLF line endings (see install.cmd).
setlocal EnableExtensions
title SoftCora POS

set "ROOT=%LOCALAPPDATA%\SoftCoraPOS"
set "SOFTCORA_DATA=%ROOT%\data"
set "SOFTCORA_LOGS=%ROOT%\logs"
set "SOFTCORA_HOST=127.0.0.1"
set "SOFTCORA_PORT=7817"
set "SOFTCORA_APP=%~dp0"
set "SOFTCORA_URL=http://127.0.0.1:%SOFTCORA_PORT%/"

if not exist "%SOFTCORA_DATA%" mkdir "%SOFTCORA_DATA%"
if not exist "%SOFTCORA_LOGS%" mkdir "%SOFTCORA_LOGS%"
cd /d "%SOFTCORA_APP%"

rem curl.exe ships with Windows 10 1803 and later. Without it there is nothing
rem to probe with, so the till is started and given a fixed moment instead.
set "HAVE_CURL=0"
where curl.exe >nul 2>&1
if not errorlevel 1 set "HAVE_CURL=1"

if "%HAVE_CURL%"=="0" goto start
curl -s -o NUL --max-time 2 "%SOFTCORA_URL%api/device" >nul 2>&1
if not errorlevel 1 goto open

:start
start "SoftCora POS" /min "%SOFTCORA_APP%start-till.cmd"
if "%HAVE_CURL%"=="0" goto blindwait

set /a TRIES=0
:wait
set /a TRIES+=1
rem ping is used for the pause: timeout.exe refuses to wait when its input is
rem redirected, which is how a shortcut launched from a script arrives.
ping -n 2 127.0.0.1 >nul
curl -s -o NUL --max-time 2 "%SOFTCORA_URL%api/device" >nul 2>&1
if not errorlevel 1 goto open
if %TRIES% GEQ 20 goto slow
goto wait

:blindwait
ping -n 5 127.0.0.1 >nul
goto open

:slow
echo   The till did not answer within 20 seconds.
echo   Its log is at %SOFTCORA_LOGS%\till.log
echo.

:open
start "" "%SOFTCORA_URL%"
exit /b 0
