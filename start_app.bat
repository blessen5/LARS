@echo off
echo Starting WAMP Server...
net start wampmysqld64
net start wampapache64
timeout /t 5
echo Starting Lab Activity System...
cd electron
npm start