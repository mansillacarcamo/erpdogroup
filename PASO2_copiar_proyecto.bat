@echo off
title DOGroup - Copiar proyecto a Laragon
color 0B
echo.
echo  ==========================================
echo   Copiando DOGroup a Laragon
echo  ==========================================
echo.

:: Verificar que Laragon está instalado
if not exist "C:\laragon\www" (
    echo  [ERROR] Laragon no encontrado en C:\laragon\
    echo  Ejecuta primero: PASO1_instalar_laragon.bat
    pause
    exit /b 1
)

:: Crear carpeta del proyecto
set DEST=C:\laragon\www\dogroup
if not exist "%DEST%" mkdir "%DEST%"

:: Copiar todos los archivos del proyecto
echo  Copiando archivos del proyecto...
xcopy /E /Y /Q "%~dp0*" "%DEST%\"

echo.
echo  ==========================================
echo   Proyecto copiado en: %DEST%
echo  ==========================================
echo.
echo  Abriendo el sistema en tu navegador...
echo.

:: Esperar un momento y abrir
timeout /t 2 /nobreak >nul
start "" "http://localhost/dogroup/login.php"

echo  Si no se abre automaticamente, ve a:
echo  http://localhost/dogroup/login.php
echo.
echo  Credenciales:
echo  Usuario: cesar.mansilla (o cualquier usuario)
echo  Clave:   la que tengas configurada
echo.
pause
