@echo off
REM ============================================================================
REM  cron-databox-sync.cmd - hourly synchronisation of data box message envelopes
REM  Frequency: once an hour, recommended minute 17
REM
REM  Lists received + sent messages of the connected data boxes (ISDS) and raises
REM  dashboard items for new messages. Runs only with a login that cannot read
REM  messages, so it never delivers them (see manual chapter "Datove schranky").
REM
REM  Task Scheduler:
REM    schtasks /create /tn "MyInvoice DataboxSync" ^
REM      /tr "%~f0" /sc hourly /mo 1 /ru SYSTEM
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_DATA_DIR (set "LOG_DIR=%MYINVOICE_DATA_DIR%\log\cron") else (set "LOG_DIR=%PROJECT_ROOT%\log\cron")
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "TODAY=%%i"
php "%PROJECT_ROOT%\api\bin\cron-databox-sync.php" %* >> "%LOG_DIR%\databox-sync-%TODAY%.log" 2>&1
exit /b %ERRORLEVEL%
