@echo off
cd /d "%~dp0"
if not exist "dist\NgocRongOnline.jar" (
    echo Chua co file dist\NgocRongOnline.jar
    echo Hay build project trong NetBeans: Clean and Build
    pause
    exit /b 1
)
:restart
echo Dang khoi dong server...
java -server -Dfile.encoding=UTF-8 -Xms1000M -Xmx1000M -jar "dist\NgocRongOnline.jar"
echo Server da dung, se khoi dong lai sau 5 giay...
timeout /t 5 /nobreak >nul
goto restart
