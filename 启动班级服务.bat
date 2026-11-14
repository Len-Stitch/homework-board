@echo off
cd /d "%~dp0"
where node >nul 2>nul
if errorlevel 1 (
  echo.
  echo 没有找到 Node.js。
  echo 请先到 https://nodejs.org 下载安装 LTS 版本，再双击本文件。
  echo.
  pause
  exit /b 1
)
rem 密码不写进代码：同目录若有 config.local.bat，会先加载里面的 CLASS_PASSWORD
if exist "%~dp0config.local.bat" call "%~dp0config.local.bat"
if "%CLASS_PASSWORD%"=="" set /p CLASS_PASSWORD=请输入班级密码后回车：
echo.
echo 正在启动作业清单服务，关闭这个窗口就会停止服务。
echo.
node server.js
pause
