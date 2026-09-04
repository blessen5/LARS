@echo off
setlocal EnableDelayedExpansion

:: Get the drive letter of the pendrive
set "DRIVE=%~d0"
set "APP_PATH=%~dp0"

echo Starting Portable Lab Activity System from %DRIVE%
echo ===============================================

:: Stop any running Apache/MySQL instances
echo Checking for running services...
taskkill /F /IM httpd.exe 2>NUL
taskkill /F /IM mysqld.exe 2>NUL

:: Set paths for portable WAMP
set "APACHE_PATH=%APP_PATH%wamp64\bin\apache\apache2.4.54.2"
set "MYSQL_PATH=%APP_PATH%wamp64\bin\mysql\mysql8.0.31"
set "PHP_PATH=%APP_PATH%wamp64\bin\php\php8.2.0"
set "NODE_PATH=%APP_PATH%portable-node"

:: Add PHP and Node to PATH
set "PATH=%PHP_PATH%;%NODE_PATH%;%PATH%"

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

:: Start Electron app
echo Starting Lab Activity System...
cd /d "%APP_PATH%electron"
start /wait cmd /c "npm start"

:: Note: The script will wait for Electron to close before continuing
echo.
echo System is shutting down...

:: Stop Apache and MySQL
taskkill /F /IM httpd.exe 2>NUL
taskkill /F /IM mysqld.exe 2>NUL

echo Services stopped successfully!
echo You can now safely remove your drive.
timeout /t 2 /nobreak > nul
pause