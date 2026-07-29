@echo off
chcp 65001 >nul
cd /d "%~dp0"

echo [1/3] 写入 Capacitor 配置...
call npm run config
if errorlevel 1 goto :fail

echo [2/3] 构建并同步到 Android 工程...
call npm run sync
if errorlevel 1 goto :fail

echo [3/3] 尝试打开 Android Studio...
call npx cap open android
if errorlevel 1 (
  echo.
  echo 未能自动打开 Android Studio。请先安装：
  echo   https://developer.android.com/studio
  echo 安装完成后重新运行本脚本，再在 Studio 里：
  echo   Build → Generate Signed Bundle / APK
  goto :eof
)
echo.
echo 已打开 Android Studio。打包步骤见 README.md
goto :eof

:fail
echo 失败，请检查上方报错。
exit /b 1
