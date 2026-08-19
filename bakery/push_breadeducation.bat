@echo off
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\push_breadeducation_sftp.ps1" %*
