@echo off
cd /d "%~dp0"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0docker\start.ps1"
if errorlevel 1 (
  echo.
  echo Startup failed. Read the error above or README.md.
  pause
  exit /b 1
)
