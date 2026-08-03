@echo off
:: Double-click this file and approve "Run as administrator" when prompted.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0allow_local_server_firewall.ps1"
pause
