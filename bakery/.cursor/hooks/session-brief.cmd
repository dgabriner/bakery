@echo off
REM sessionStart: inject craft stanza + brief command (fail open).
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0session-brief.ps1"
exit /b 0
