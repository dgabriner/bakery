@echo off
REM Upload bakery files to staging.sourflour.org (NOT production).
REM Uses the same .env.sftp host/user/password, plus SFTP_STAGING_REMOTE_ROOT.
REM
REM Closeout Radar (this branch):
REM   push_staging.bat -Files closeout_radar.php,includes/closeout_radar.php,includes/navigation_catalog.php
REM
REM Dry run:
REM   push_staging.bat -DryRun -Files closeout_radar.php,includes/closeout_radar.php,includes/navigation_catalog.php
cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\push_sftp.ps1" -Staging %*
exit /b %ERRORLEVEL%
