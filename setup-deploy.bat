@echo off
setlocal enabledelayedexpansion
REM ============================================================
REM  Airking Inventory - One-Command Production Setup
REM  Sets up the FAST (production) version of the app on this device.
REM  Safe to re-run: every step is idempotent (won't clobber data/config).
REM  See DEPLOYMENT.md for details.
REM ============================================================

REM ---- Adjust these for the target device if needed ----
set "XAMPP=C:\XAMPP1"
set "DBNAME=airkingV5"
set "DBUSER=root"
set "DBPASS="
REM ------------------------------------------------------

set "ROOT=%~dp0"
set "MYSQL=%XAMPP%\mysql\bin\mysql.exe"
set "HTTPD=%XAMPP%\apache\bin\httpd.exe"
if "%DBPASS%"=="" ( set "MYSQL_PW=" ) else ( set "MYSQL_PW=-p%DBPASS%" )

echo.
echo ============================================================
echo   AIRKING INVENTORY - PRODUCTION SETUP  (db: %DBNAME%)
echo ============================================================

REM ------------------------------------------------------------
echo.
echo [1/8] Backend: composer install...
cd /d "%ROOT%back-end"
call composer install --optimize-autoloader --no-dev
if errorlevel 1 goto :error

REM ------------------------------------------------------------
echo.
echo [2/8] Backend: .env (create + production values)...
if not exist ".env" (
    if exist ".env.example" ( copy /y ".env.example" ".env" >nul ) else ( type nul > ".env" )
    echo   .env created from example.
)
powershell -NoProfile -Command ^
  "$f='%ROOT%back-end\.env'; $c=Get-Content -Raw $f;" ^
  "$c=$c -replace '(?m)^APP_ENV=.*','APP_ENV=production';" ^
  "$c=$c -replace '(?m)^APP_DEBUG=.*','APP_DEBUG=false';" ^
  "$c=$c -replace '(?m)^SESSION_DRIVER=.*','SESSION_DRIVER=file';" ^
  "$c=$c -replace '(?m)^CACHE_STORE=.*','CACHE_STORE=file';" ^
  "$c=$c -replace '(?m)^DB_DATABASE=.*','DB_DATABASE=%DBNAME%';" ^
  "$c=$c -replace '(?m)^DB_USERNAME=.*','DB_USERNAME=%DBUSER%';" ^
  "Set-Content -Path $f -Value $c -Encoding ascii"
findstr /B /C:"APP_KEY=base64" ".env" >nul 2>&1
if errorlevel 1 ( call php artisan key:generate ) else ( echo   APP_KEY already set. )

REM ------------------------------------------------------------
echo.
echo [3/8] OPcache in php.ini...
set "INI=%XAMPP%\php\php.ini"
if exist "%INI%" (
    powershell -NoProfile -Command ^
      "$f='%INI%'; $c=Get-Content -Raw $f;" ^
      "$c=$c -replace '(?m)^;?\s*zend_extension=opcache','zend_extension=opcache';" ^
      "$c=$c -replace '(?m)^;?\s*opcache\.enable=.*','opcache.enable=1';" ^
      "$c=$c -replace '(?m)^;?\s*opcache\.enable_cli=.*','opcache.enable_cli=1';" ^
      "$c=$c -replace '(?m)^;?\s*opcache\.memory_consumption=.*','opcache.memory_consumption=256';" ^
      "$c=$c -replace '(?m)^;?\s*opcache\.validate_timestamps=.*','opcache.validate_timestamps=1';" ^
      "Set-Content -Path $f -Value $c -Encoding ascii; Write-Host '   OPcache enabled in php.ini.'"
) else (
    echo   ^^! php.ini not found at %INI% - set XAMPP path at top of script.
)

REM ------------------------------------------------------------
echo.
echo [4/8] Database (create if missing, import dump only if empty)...
"%MYSQL%" -u %DBUSER% %MYSQL_PW% -e "CREATE DATABASE IF NOT EXISTS %DBNAME% CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>nul
if errorlevel 1 (
    echo   ^^! Could not reach MySQL. Start MySQL in XAMPP, or import the dump manually.
) else (
    for /f %%T in ('"%MYSQL%" -u %DBUSER% %MYSQL_PW% -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='%DBNAME%';"') do set "TBLCOUNT=%%T"
    if "!TBLCOUNT!"=="0" (
        if exist "%ROOT%airking_cleans.sql" (
            echo   importing airking_cleans.sql ...
            "%MYSQL%" -u %DBUSER% %MYSQL_PW% %DBNAME% -e "source %ROOT:\=/%airking_cleans.sql"
            echo   database imported.
        ) else (
            echo   ^^! airking_cleans.sql not found - import your dump manually.
        )
    ) else (
        echo   database already has tables ^(!TBLCOUNT!^) - skipping import.
    )
)

REM ------------------------------------------------------------
echo.
echo [5/8] Backend: storage link + cache config/routes...
cd /d "%ROOT%back-end"
call php artisan storage:link
call php artisan config:cache
call php artisan route:cache

REM ------------------------------------------------------------
echo.
echo [6/8] Frontend: install + production build...
cd /d "%ROOT%frontend"
if not exist "node_modules" ( call npm install & if errorlevel 1 goto :error )
call npm run build
if errorlevel 1 goto :error

REM ------------------------------------------------------------
echo.
echo [7/8] Apache: enable proxy module + include vhost...
set "HTTPD_CONF=%XAMPP%\apache\conf\httpd.conf"
if exist "%HTTPD_CONF%" (
    powershell -NoProfile -Command ^
      "$f='%HTTPD_CONF%'; $c=Get-Content -Raw $f; $n=$c -replace '(?m)^#\s*(LoadModule proxy_http_module modules/mod_proxy_http.so)','$1'; if($n -ne $c){Set-Content $f $n -Encoding ascii; Write-Host '   mod_proxy_http enabled.'} else {Write-Host '   mod_proxy_http already on.'}"
)
set "VHOSTS=%XAMPP%\apache\conf\extra\httpd-vhosts.conf"
if exist "%VHOSTS%" (
    findstr /C:"apache/airking.conf" "%VHOSTS%" >nul 2>&1
    if errorlevel 1 (
        echo.>> "%VHOSTS%"
        echo # Airking Inventory production vhost - source in the repo.>> "%VHOSTS%"
        set "SRC=%ROOT%apache\airking.conf"
        echo Include "!SRC:\=/!">> "%VHOSTS%"
        echo   vhost include added.
    ) else ( echo   vhost already included. )
)

REM ------------------------------------------------------------
echo.
echo [8/8] Apache: test config + restart...
if exist "%HTTPD%" (
    "%HTTPD%" -t
    if errorlevel 1 (
        echo   ^^! Apache config test FAILED - not restarting. Fix the error above.
    ) else (
        taskkill /IM httpd.exe /F >nul 2>&1
        timeout /t 2 /nobreak >nul
        start "" "%HTTPD%"
        timeout /t 4 /nobreak >nul
        netstat -ano | findstr ":8081" >nul 2>&1 && echo   Apache up, backend on :8081. || echo   ^^! :8081 not listening - check logs.
    )
)

echo.
echo ============================================================
echo   SETUP COMPLETE
echo ============================================================
echo.
echo   Open:
echo     - This PC:      http://localhost/
echo     - Phone/tablet: http://THIS-PC-IP/        (same Wi-Fi, run ipconfig)
echo     - phpMyAdmin:   http://localhost/phpmyadmin
echo.
echo   NOTE: XAMPP Control Panel may show Apache red after this restart even
echo   though it is running. Click Stop then Start in the panel to resync.
echo.
echo   After frontend code changes: cd frontend ^&^& npm run build
echo.
goto :end

:error
echo.
echo   !!! SETUP FAILED - check the error above. !!!
echo.
endlocal
exit /b 1

:end
endlocal
pause
