@echo off
chcp 936 >nul
cd /d "%~dp0"

if not exist "github-upload" (
  echo [错误] 找不到 github-upload 文件夹，请确认它和本文件在同一目录。
  pause
  exit /b 1
)

echo [1/3] 同步代码文件到 github-upload ...
copy /Y "index.html"      "github-upload\" >nul
copy /Y "README.md"       "github-upload\" >nul
copy /Y "server.js"       "github-upload\" >nul
copy /Y "sw.js"           "github-upload\" >nul
copy /Y "manifest.json"   "github-upload\" >nul
copy /Y "api\index.php"   "github-upload\api\" >nul
copy /Y "同步到github.bat"  "github-upload\" >nul
copy /Y "打开作业清单.bat"   "github-upload\" >nul
echo       完成（图标、截图没动，改过的话请手动复制）
echo.

cd /d "%~dp0github-upload"

echo [2/3] 提交改动 ...
git add -A
git diff --cached --quiet
if errorlevel 1 (
  git commit -m "update: sync latest changes" >nul
  git log --oneline -1
) else (
  echo       没有需要提交的新改动
)
echo.

echo [3/3] 推送到 GitHub ...
REM 本机系统代理会拦截 git 推送请求（报 502），这里强制直连
git -c http.proxy= -c https.proxy= push origin main
if errorlevel 1 (
  echo.
  echo [失败] 推送没有成功，常见原因：
  echo   1^) 网络连不上 github.com，稍后重试
  echo   2^) 需要重新登录 GitHub，手动执行一次 git push
  echo   3^) 远端有别人的新提交，先执行 git pull --rebase
  echo.
  pause
  exit /b 1
)

echo.
echo [完成] 已推送到 https://github.com/Len-Stitch/homework-board
echo        刷新网页时按 Ctrl+F5 才能看到新内容。
echo.
pause
