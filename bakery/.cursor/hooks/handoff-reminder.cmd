@echo off
REM Optional stop-hook nag: remind agents to write a Homebase §10 handoff.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0handoff-reminder.ps1"
exit /b 0
