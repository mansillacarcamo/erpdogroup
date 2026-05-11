@echo off
title Instalar Laragon para DOGroup
color 0A
echo.
echo  ==========================================
echo   Instalando Laragon + DOGroup
echo  ==========================================
echo.
echo  Paso 1: Descargando Laragon...
echo.

powershell -Command "Invoke-WebRequest -Uri 'https://github.com/leokhoa/laragon/releases/download/6.0.0/laragon-wamp.exe' -OutFile '%TEMP%\laragon-setup.exe' -UseBasicParsing"

if exist "%TEMP%\laragon-setup.exe" (
    echo  Descarga completada. Abriendo instalador...
    echo.
    echo  INSTRUCCIONES:
    echo  1. Acepta la instalacion de Laragon
    echo  2. Cuando termine, abre Laragon
    echo  3. Haz clic en "Start All"
    echo  4. Ejecuta el archivo: PASO2_copiar_proyecto.bat
    echo.
    start "" "%TEMP%\laragon-setup.exe"
) else (
    echo  [Error] No se pudo descargar. Descarga manual:
    echo  https://laragon.org/download
    start "" "https://laragon.org/download"
)
echo.
pause
