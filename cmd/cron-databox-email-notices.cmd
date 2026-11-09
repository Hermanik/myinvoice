@echo off
REM ============================================================================
REM  cron-databox-email-notices.cmd - watch data boxes through ISDS notification e-mails
REM  Frequency: every 30 minutes
REM
REM  Reads the configured IMAP folder, proves each "new data message" notice genuine
REM  (DKIM) and records the announced message; optionally mails an alert. Never logs
REM  in to the data box system, so it never delivers a message (see manual chapter
REM  "Datove schranky").
REM
REM  Task Scheduler:
REM    schtasks /create /tn "MyInvoice DataboxEmailNotices" ^
REM      /tr "%~f0" /sc minute /mo 30 /ru SYSTEM
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_DATA_DIR (set "LOG_DIR=%MYINVOICE_DATA_DIR%\log\cron") else (set "LOG_DIR=%PROJECT_ROOT%\log\cron")
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "TODAY=%%i"
php "%PROJECT_ROOT%\api\bin\cron-databox-email-notices.php" %* >> "%LOG_DIR%\databox-email-notices-%TODAY%.log" 2>&1
exit /b %ERRORLEVEL%
