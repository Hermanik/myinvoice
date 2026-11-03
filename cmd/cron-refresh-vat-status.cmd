@echo off
REM ============================================================================
REM  cron-refresh-vat-status.cmd - daily recompute of the cached VAT status
REM  Frequency: once a day shortly after midnight, recommended 00:05
REM
REM  Recomputes supplier.is_vat_payer / is_identified ("status today") from the
REM  VAT status history (supplier_vat_status_history). A history row dated in the
REM  future (e.g. VAT payer from 2027-01-01) therefore flips the cache by itself.
REM
REM  Task Scheduler:
REM    schtasks /create /tn "MyInvoice Refresh VAT Status" ^
REM      /tr "%~f0" /sc daily /st 00:05 /ru SYSTEM
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_DATA_DIR (set "LOG_DIR=%MYINVOICE_DATA_DIR%\log\cron") else (set "LOG_DIR=%PROJECT_ROOT%\log\cron")
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "TODAY=%%i"
php "%PROJECT_ROOT%\api\bin\cron-refresh-vat-status.php" %* >> "%LOG_DIR%\refresh-vat-status-%TODAY%.log" 2>&1
exit /b %ERRORLEVEL%
