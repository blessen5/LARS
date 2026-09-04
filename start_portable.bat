@echo off
setlocal EnableDelayedExpansion

:: Get the drive letter of the pendrive
set "DRIVE=%~d0"
set "APP_PATH=%~dp0"

echo Starting Portable WAMP Server from %DRIVE%
echo =====================================

:: Stop any running Apache/MySQL instances
echo Checking for running services...
taskkill /F /IM httpd.exe 2>NUL
taskkill /F /IM mysqld.exe 2>NUL

:: Set paths for portable WAMP
set "APACHE_PATH=%APP_PATH%wamp64\bin\apache\apache2.4.54.2"
set "MYSQL_PATH=%APP_PATH%wamp64\bin\mysql\mysql8.0.31"
set "PHP_PATH=%APP_PATH%wamp64\bin\php\php8.2.0"

:: Start Apache
echo Starting Apache...
cd /d "%APACHE_PATH%\bin"
start "" httpd.exe

:: Start MySQL
echo Starting MySQL...
cd /d "%MYSQL_PATH%\bin"
start "" mysqld.exe --defaults-file="%APP_PATH%wamp64\bin\mysql\mysql8.0.31\my.ini"

:: Wait for services to start
echo Waiting for services to initialize...
timeout /t 5 /nobreak > nul

:: Open the default browser to the lab activity system
echo Opening Lab Activity System...
start http://localhost/lab_activity/login.php

echo.
echo System is ready!
echo To close the system, run stop_portable.bat
echo.
pause