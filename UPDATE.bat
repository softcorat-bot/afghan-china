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
git fetch origin
git checkout -f -B claude/afghan-china-v2-setup-u3v99o origin/claude/afghan-china-v2-setup-u3v99o

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
