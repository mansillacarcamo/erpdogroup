@echo off
REM ============================================================
REM  Backup automatico de controlgastos.db
REM  Programar en Task Scheduler de Windows (diario a las 23:30)
REM ============================================================

setlocal
set "SCRIPT_DIR=%~dp0"
set "PHP_EXE=php"

REM Si php no esta en PATH, descomentar y ajustar:
REM set "PHP_EXE=C:\Users\INGENIERIA SISTEMAS\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"

"%PHP_EXE%" "%SCRIPT_DIR%backup_db.php"
exit /b %errorlevel%
