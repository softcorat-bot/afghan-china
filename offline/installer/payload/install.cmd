@echo off
rem SoftCora POS - the entry point the self-extracting installer runs.
rem
rem The setup .exe unpacks itself into a temporary folder and runs this file
rem from there, so nothing here may assume it is running from the final
rem location, and the launcher it starts must be the *installed* one: the setup
rem deletes its temporary folder the moment this script returns.
rem
rem No administrator rights are needed; the till installs for the current user
rem into %LOCALAPPDATA%\SoftCoraPOS. All the work happens in install.ps1 - this
rem file only finds PowerShell, reports, and starts the till afterwards.
rem
rem Exit codes from install.ps1: 0 installed, 3 installed but do not launch,
rem 1 failed. Anything else is treated as a failure.
rem
rem TEXT ENCODING: keep this file pure ASCII with CRLF line endings. cmd.exe
rem mis-parses LF-only .cmd files, and a byte-order mark shows up as a stray
rem character before @echo off. installer/check-payload.mjs enforces both.
setlocal EnableExtensions
title SoftCora POS Setup

set "PAYLOAD=%~dp0"
set "ROOT=%LOCALAPPDATA%\SoftCoraPOS"
set "PS=%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe"

echo.
echo   SoftCora POS - offline point of sale
echo   ====================================
echo.

if not defined LOCALAPPDATA goto noprofile
if not exist "%PAYLOAD%install.ps1" goto nopayload
if not exist "%PS%" set "PS=powershell.exe"

echo   Installing for %USERNAME% into:
echo     %ROOT%
echo.

"%PS%" -NoProfile -ExecutionPolicy Bypass -File "%PAYLOAD%install.ps1" %*
set "RESULT=%ERRORLEVEL%"

set "LAUNCH=1"
if "%RESULT%"=="3" set "LAUNCH=0"
if "%RESULT%"=="3" set "RESULT=0"

if not "%RESULT%"=="0" goto failed
if "%LAUNCH%"=="0" goto quietdone

echo   Starting SoftCora POS...
echo   The till's own window carries the log; closing it stops the till.
echo.
start "" "%ROOT%\app\SoftCora POS.cmd"
goto done

:quietdone
echo   Installed. The till was not started, as requested.
echo.
goto done

:failed
echo.
echo   Setup did not finish. Exit code %RESULT%.
echo   Nothing was deleted, and the shop's data folder is untouched.
echo.
echo   The reason is in the install log:
echo     %ROOT%\logs\install.log
echo.
echo   Send that file to your administrator if the message is not clear.
echo.
pause
exit /b %RESULT%

:noprofile
echo   Windows did not report a user profile folder, so there is
echo   nowhere to install to. Sign in as a normal user and run the
echo   setup again.
echo.
pause
exit /b 1

:nopayload
echo   This setup file is incomplete: install.ps1 is missing from
echo     %PAYLOAD%
echo   Download the setup again and check its SHA-256 against the
echo   value published with the release.
echo.
pause
exit /b 1

:done
exit /b 0
