@echo off
REM ============================================================================
REM  cron-refresh-repo-rates.cmd - monthly append of new CNB repo rates
REM  Frequency: 1st of every month, recommended 07:10
REM
REM  Appends the CNB two-week repo rates published since the last run to the
REM  append-only codebook behind the statutory late-payment interest (existing
REM  rows are never rewritten). Without it reminders stop showing interest after
REM  the first day of a half-year until an admin fetches the rates by hand.
REM
REM  Task Scheduler:
REM    schtasks /create /tn "MyInvoice Refresh Repo Rates" ^
REM      /tr "%~f0" /sc monthly /d 1 /st 07:10 /ru SYSTEM
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_DATA_DIR (set "LOG_DIR=%MYINVOICE_DATA_DIR%\log\cron") else (set "LOG_DIR=%PROJECT_ROOT%\log\cron")
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "TODAY=%%i"
php "%PROJECT_ROOT%\api\bin\cron-refresh-repo-rates.php" %* >> "%LOG_DIR%\refresh-repo-rates-%TODAY%.log" 2>&1
exit /b %ERRORLEVEL%
