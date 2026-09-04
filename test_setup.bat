@echo off
echo Lab Activity System - Local Network Setup
echo =======================================
echo.

:menu
echo Choose an option:
echo 1. Set up WAMP Computer (Server)
echo 2. Set up Client Computer
echo 3. Test Connection
echo 4. Exit
echo.

set /p choice=Enter your choice (1-4): 

if "%choice%"=="1" goto server
if "%choice%"=="2" goto client
if "%choice%"=="3" goto test
if "%choice%"=="4" goto end

:server
echo.
echo Setting up WAMP Computer...
echo -------------------------
echo 1. Getting IP Address...
ipconfig | findstr "IPv4"
echo.
echo Note down the IPv4 Address shown above. You'll need it for the client computer.
echo.
echo 2. Testing WAMP...
netstat -an | find ":80" >nul
if %ERRORLEVEL% NEQ 0 (
    echo WARNING: WAMP server might not be running!
    echo Please make sure WAMP is started and the icon is green
)
echo.
echo 3. Testing website access...
curl -s http://localhost/lab_activity/login.php >nul
if %ERRORLEVEL% NEQ 0 (
    echo WARNING: Cannot access the website!
    echo Make sure lab_activity files are in wamp64/www/lab_activity/
) else (
    echo Website is accessible!
)
echo.
pause
goto menu

:client
echo.
echo Setting up Client Computer...
echo --------------------------
set /p serverip=Enter the WAMP computer's IP address: 
echo.
echo Testing connection to server...
curl -s http://%serverip%/lab_activity/login.php >nul
if %ERRORLEVEL% NEQ 0 (
    echo ERROR: Cannot connect to the server!
    echo Please check:
    echo - WAMP is running on the server computer
    echo - Both computers are on the same network
    echo - Windows Firewall is not blocking the connection
) else (
    echo Connection successful!
    echo Updating configuration...
    echo {"serverIP": "%serverip%"} > electron\server-config.json
    echo Configuration updated! You can now start the Electron app.
)
echo.
pause
goto menu

:test
echo.
echo Testing System...
echo ---------------
if exist electron\server-config.json (
    echo Reading server configuration...
    type electron\server-config.json
) else (
    echo No configuration found. Please run the client setup first.
)
echo.
echo Starting Electron app...
cd electron
npm start
cd ..
echo.
pause
goto menu

:end
echo.
echo Thank you for using Lab Activity System!
echo.
pause