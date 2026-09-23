@echo off
REM ─────────────────────────────────────────────────────────────
REM  Afghan China MIS — one-click updater (Windows)
REM  Run this after every "git pull" (it pulls for you too).
REM  It updates the code, the database, permissions and images —
REM  the missing-migration "Server Error" can never happen again.
REM ─────────────────────────────────────────────────────────────
cd /d "%~dp0"

echo.
echo [1/6] Pulling latest code...
REM The old development branch (claude/afghan-china-v2-setup-u3v99o) no longer
REM exists on GitHub; every change is merged into main, so update from main.
git fetch origin main
if errorlevel 1 (
  echo  ^> Could not reach GitHub. Check the internet connection and run UPDATE.bat again.
  pause
  exit /b 1
)
git checkout -f -B main origin/main

echo.
echo [2/6] Migrating the database...
cd backend
php artisan migrate --force

echo.
echo [3/6] Refreshing permissions and roles...
php artisan db:seed --class=PermissionSeeder --force

echo.
echo [4/6] Seeding product images (repo photos + placeholders)...
php artisan db:seed --class=ProductImageSeeder --force

echo.
echo [5/6] Clearing caches...
php artisan optimize:clear

echo.
echo [6/6] Health check...
php artisan acsc:doctor

cd ..
echo.
echo  ^> Update complete. Restart "php artisan serve" and "quasar dev" if they were running.
echo  ^> If the health check above listed any problem, send me that text.
pause
