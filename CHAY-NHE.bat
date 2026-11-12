@echo off
cd /d "%~dp0"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0docker\start.ps1" -Light
if errorlevel 1 (
  pause
  exit /b 1
)
