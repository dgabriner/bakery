@echo off
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0build_deploy_zip.ps1"
pause
