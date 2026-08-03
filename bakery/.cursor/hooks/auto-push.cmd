@echo off
REM Reliable Windows entry for Cursor project hooks (cwd = project root).
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0auto-push.ps1"
exit /b 0
