@echo off
setlocal enabledelayedexpansion
title LARS - Local Area Network & Workstation Setup
echo ========================================================
echo   LARS - Lab Activity Reporting System LAN Setup
echo ========================================================
echo.

:menu
echo Choose an option:
echo [1] Set up WAMP / Server Computer
echo [2] Set up Client Workstation (Configure Server IP)
echo [3] Run Connection Diagnostic Test
echo [4] Exit
echo.

set /p choice=Enter your choice (1-4): 

if "%choice%"=="1" goto server
if "%choice%"=="2" goto client
if "%choice%"=="3" goto test
if "%choice%"=="4" goto end
echo Invalid selection. Please choose 1, 2, 3, or 4.
goto menu

:server
echo.
echo ========================================================
echo   Server Setup ^& Diagnostics
echo ========================================================
echo.
echo [1/3] Detecting Local IPv4 Address(es)...
ipconfig | findstr /R "IPv4.*[0-9]"
echo.
echo Note down the IPv4 Address above to enter on student client PCs.
echo.
echo [2/3] Checking Web Server (Port 80) ^& Database (Port 3306)...
netstat -an | find ":80" >nul
if %ERRORLEVEL% NEQ 0 (
    echo   [!] WARNING: Web server (Port 80) does not appear to be listening.
    echo       Please ensure WAMP/Apache is started.
) else (
    echo   [OK] Web Server (Port 80) is listening.
)

netstat -an | find ":3306" >nul
if %ERRORLEVEL% NEQ 0 (
    echo   [!] WARNING: MySQL Database (Port 3306) does not appear to be listening.
) else (
    echo   [OK] MySQL Database (Port 3306) is listening.
)

echo.
echo [3/3] Verifying local web accessibility...
curl -s -o nul -w "%%{http_code}" http://localhost/lab_activity/login.php | findstr "200 302" >nul
if %ERRORLEVEL% NEQ 0 (
    echo   [!] Could not verify http://localhost/lab_activity/login.php
    echo       Make sure files reside in your web root (e.g. C:\wamp64\www\lab_activity).
) else (
    echo   [OK] LARS web portal is locally accessible.
)
echo.
pause
goto menu

:client
echo.
echo ========================================================
echo   Client Workstation Setup
echo ========================================================
echo.
set /p serverip=Enter the Teacher/Server Computer's IP address: 
if "%serverip%"=="" (
    echo IP address cannot be empty.
    goto menu
)

echo.
echo Testing connection to http://%serverip%/lab_activity/login.php ...
curl -s -m 5 -o nul -w "%%{http_code}" http://%serverip%/lab_activity/login.php | findstr "200 302" >nul
if %ERRORLEVEL% NEQ 0 (
    echo.
    echo   [ERROR] Cannot reach the server at %serverip%!
    echo   Troubleshooting tips:
    echo     1. Ensure both computers are on the same Wi-Fi / LAN subnet.
    echo     2. Ensure WAMP is active (green tray icon) on the server.
    echo     3. Ensure Windows Firewall on the server allows inbound Port 80.
) else (
    echo.
    echo   [OK] Connection successful!
    if not exist "electron" mkdir electron
    (
        echo {
        echo   "serverIP": "%serverip%"
        echo }
    ) > electron\server-config.json
    echo   [OK] Saved configuration to electron\server-config.json
)
echo.
pause
goto menu

:test
echo.
echo ========================================================
echo   System Configuration Test
echo ========================================================
echo.
if exist "electron\server-config.json" (
    echo Current Server Configuration:
    type electron\server-config.json
    echo.
    echo Starting Desktop Application...
    cd electron
    call npm start
    cd ..
) else (
    echo No server configuration found. Please run Option 2 (Client Setup) first.
)
echo.
pause
goto menu

:end
echo.
echo Thank you for using Lab Activity Reporting System (LARS)!
echo.
endlocal