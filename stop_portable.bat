@echo off
echo Stopping Lab Activity System...
echo =============================

:: Stop Apache and MySQL
taskkill /F /IM httpd.exe 2>NUL
taskkill /F /IM mysqld.exe 2>NUL

echo Services stopped successfully!
timeout /t 2 /nobreak > nul