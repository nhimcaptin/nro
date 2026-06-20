@echo off
cd /d "%~dp0"
if not exist "dist\NgocRongOnline.jar" (
    echo Chua co file dist\NgocRongOnline.jar
    echo Hay build project trong NetBeans: Clean and Build
    pause
    exit /b 1
)
start "" javaw -server -Dfile.encoding=UTF-8 -Xms1000M -Xmx1000M -jar dist\NgocRongOnline.jar
exit
