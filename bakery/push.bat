@echo off
REM One-click: upload changed bakery files to DreamHost STAGING (not live /bake).
cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\push_sftp_stage.ps1" %*
exit /b %ERRORLEVEL%
