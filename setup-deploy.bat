@echo off
setlocal enabledelayedexpansion
REM ============================================================
REM  Airking Inventory - Production Setup / Deploy Script
REM  Run this on a NEW device to build + serve the FAST version.
REM  See DEPLOYMENT.md for the full explanation.
REM ============================================================

REM --- Adjust this if XAMPP is not installed at C:\XAMPP1 ---
set "XAMPP=C:\XAMPP1"

echo.
echo ============================================================
echo   AIRKING INVENTORY - PRODUCTION SETUP
echo ============================================================
echo.

set "ROOT=%~dp0"
cd /d "%ROOT%"

REM ------------------------------------------------------------
REM  1. BACKEND
REM ------------------------------------------------------------
echo [1/6] Backend: installing PHP dependencies...
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
echo [2/6] Backend: storage link + caching config/routes...
call php artisan storage:link
call php artisan config:cache
call php artisan route:cache
echo   Done.

REM ------------------------------------------------------------
REM  2. FRONTEND
REM ------------------------------------------------------------
echo.
echo [3/6] Frontend: installing packages and building production bundle...
cd /d "%ROOT%frontend"
if not exist "node_modules" (
    call npm install
    if errorlevel 1 goto :error
)
call npm run build
if errorlevel 1 goto :error
echo   Production build created at frontend\build\

REM ------------------------------------------------------------
REM  3. APACHE: enable mod_proxy_http (idempotent)
REM ------------------------------------------------------------
echo.
echo [4/6] Apache: ensuring mod_proxy_http is enabled...
set "HTTPD_CONF=%XAMPP%\apache\conf\httpd.conf"
if exist "%HTTPD_CONF%" (
    powershell -NoProfile -Command ^
      "$f='%HTTPD_CONF%'; $c=Get-Content -Raw $f; $n=$c -replace '(?m)^#\s*(LoadModule proxy_http_module modules/mod_proxy_http.so)','$1'; if($n -ne $c){Set-Content -Path $f -Value $n -Encoding ascii; Write-Host '   enabled mod_proxy_http.'} else {Write-Host '   already enabled.'}"
) else (
    echo   ^^! httpd.conf not found at %HTTPD_CONF% - set XAMPP path at top of script.
)

REM ------------------------------------------------------------
REM  4. APACHE: include the Airking vhost (idempotent)
REM ------------------------------------------------------------
echo.
echo [5/6] Apache: ensuring the Airking vhost is included...
set "VHOSTS=%XAMPP%\apache\conf\extra\httpd-vhosts.conf"
set "VHOST_SRC=%ROOT%apache\airking.conf"
if exist "%VHOSTS%" (
    findstr /C:"apache/airking.conf" "%VHOSTS%" >nul 2>&1
    if errorlevel 1 (
        echo.>> "%VHOSTS%"
        echo # Airking Inventory production vhost - source in the repo.>> "%VHOSTS%"
        echo Include "%VHOST_SRC:\=/%">> "%VHOSTS%"
        echo   Include added.
    ) else (
        echo   already included.
    )
) else (
    echo   ^^! httpd-vhosts.conf not found - set XAMPP path at top of script.
)

REM ------------------------------------------------------------
REM  5. CHECKS + config test
REM ------------------------------------------------------------
echo.
echo [6/6] Checking OPcache + Apache config...
php -r "exit(function_exists('opcache_get_status') && @opcache_get_status()!==false ? 0 : 1);" >nul 2>&1
if errorlevel 1 (
    echo   *** ACTION REQUIRED: OPcache is NOT enabled in php.ini. ***
    echo       zend_extension=opcache
    echo       opcache.enable=1
    echo       opcache.enable_cli=1
    echo       opcache.memory_consumption=256
    echo       opcache.validate_timestamps=1
) else (
    echo   OPcache: enabled.
)
if exist "%XAMPP%\apache\bin\httpd.exe" (
    "%XAMPP%\apache\bin\httpd.exe" -t
)

echo.
echo ============================================================
echo   SETUP COMPLETE
echo ============================================================
echo.
echo   NEXT: restart Apache so the config loads
echo     - XAMPP Control Panel -^> Apache -^> Stop, then Start
echo     - If the restart does not take (check: netstat -ano ^| findstr :8081),
echo       kill httpd.exe in Task Manager and Start Apache again.
echo.
echo   THEN open:
echo     - This PC:        http://localhost/
echo     - Phone/tablet:   http://THIS-PC-IP/       (same Wi-Fi)
echo     - phpMyAdmin:     http://localhost/phpmyadmin
echo.
echo   Realtime (optional): php artisan reverb:start --host=0.0.0.0 --port=8080
echo.
echo   REMINDER: rebuild the frontend after code changes: cd frontend ^&^& npm run build
echo   Never use 'react-scripts start' / 'php artisan serve' for the client.
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
