@echo off
setlocal enabledelayedexpansion
REM ============================================================
REM  Airking Inventory - Production Setup / Deploy Script
REM  Run this on a NEW device to build the FAST version of the app.
REM  See DEPLOYMENT.md for the full explanation.
REM ============================================================

echo.
echo ============================================================
echo   AIRKING INVENTORY - PRODUCTION SETUP
echo ============================================================
echo.

REM --- resolve project root to this script's folder ---
set "ROOT=%~dp0"
cd /d "%ROOT%"

REM ------------------------------------------------------------
REM  1. BACKEND
REM ------------------------------------------------------------
echo [1/4] Backend: installing PHP dependencies...
cd /d "%ROOT%back-end"
if not exist ".env" (
    echo   ^^! WARNING: back-end\.env not found.
    echo   ^^! Create it and set: APP_ENV=production, APP_DEBUG=false,
    echo   ^^!                    SESSION_DRIVER=file, CACHE_STORE=file
    echo.
)
call composer install --optimize-autoloader --no-dev
if errorlevel 1 goto :error

echo.
echo [2/4] Backend: caching config and routes...
call php artisan config:cache
call php artisan route:cache
echo   Done.

REM ------------------------------------------------------------
REM  2. FRONTEND
REM ------------------------------------------------------------
echo.
echo [3/4] Frontend: installing packages and building production bundle...
cd /d "%ROOT%frontend"
if not exist "node_modules" (
    call npm install
    if errorlevel 1 goto :error
)
call npm run build
if errorlevel 1 goto :error
echo   Production build created at frontend\build\

REM ------------------------------------------------------------
REM  3. OPCACHE CHECK (per-device, cannot be automated safely)
REM ------------------------------------------------------------
echo.
echo [4/4] Checking OPcache...
php -r "exit(function_exists('opcache_get_status') && @opcache_get_status()!==false ? 0 : 1);" >nul 2>&1
if errorlevel 1 (
    echo.
    echo   *** ACTION REQUIRED: OPcache is NOT enabled. ***
    echo   Edit your php.ini and set:
    echo       zend_extension=opcache
    echo       opcache.enable=1
    echo       opcache.enable_cli=1
    echo       opcache.memory_consumption=256
    echo       opcache.validate_timestamps=1
    echo   Then restart Apache. See DEPLOYMENT.md.
) else (
    echo   OPcache is enabled. Good.
)

echo.
echo ============================================================
echo   SETUP COMPLETE
echo ============================================================
echo.
echo   To run the FAST version now:
echo       cd frontend  ^&^&  npm run serve:build      (http://localhost:5000)
echo.
echo   For real deployment, serve frontend\build and back-end\public
echo   through XAMPP Apache. See DEPLOYMENT.md.
echo.
echo   REMINDER: never use 'react-scripts start' or 'php artisan serve'
echo   for the client - those are for coding only.
echo.
goto :end

:error
echo.
echo   !!! Setup FAILED. Check the error above. !!!
echo.
exit /b 1

:end
endlocal
pause
