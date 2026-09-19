@echo off
rem SoftCora POS — the entry point the self-extracting installer runs.
rem
rem The setup .exe unpacks itself into a temporary folder and then runs this
rem file from there, so nothing here may assume it is running from the final
rem location. Nothing needs administrator rights: the till installs for the
rem current user, into %LOCALAPPDATA%\SoftCoraPOS.
setlocal
title SoftCora POS — Setup

echo.
echo   SoftCora POS — offline point of sale
echo   ===================================
echo.

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0install.ps1" %*
set "RESULT=%ERRORLEVEL%"

if not "%RESULT%"=="0" (
  echo.
  echo   Installation did not finish ^(exit %RESULT%^).
  echo   Nothing was deleted, and the shop's data folder is untouched.
  echo.
  pause
  exit /b %RESULT%
)

echo.
echo   Starting SoftCora POS…
echo   (The till's own window carries the log. Closing it stops the till.)
echo.

rem Launch the *installed* launcher, never the temporary copy: the setup deletes
rem its temporary folder the moment this script returns.
start "" "%LOCALAPPDATA%\SoftCoraPOS\app\SoftCora POS.cmd"
exit /b 0
