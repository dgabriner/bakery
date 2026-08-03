@echo off
REM One-click: upload changed bakery files to DreamHost and record history.
cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\push_sftp.ps1" %*
exit /b %ERRORLEVEL%
