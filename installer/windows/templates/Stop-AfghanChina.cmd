@echo off
rem Afghan China Offline - stops the local server.
taskkill /FI "WINDOWTITLE eq Afghan China Server*" >nul 2>&1
echo [Afghan China] Server stopped.
