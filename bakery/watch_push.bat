@echo off
REM Keep local bakery files mirrored to DreamHost (file watcher + debounced SFTP).
cd /d "%~dp0"
echo Starting bakery auto-push watcher...
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\watch_and_push.ps1" %*
exit /b %ERRORLEVEL%
