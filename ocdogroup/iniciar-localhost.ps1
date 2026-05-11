# Servidor local OCDO Group — usa IPv4 para que el navegador abra bien
# Uso: clic derecho -> Ejecutar con PowerShell, o: powershell -ExecutionPolicy Bypass -File .\iniciar-localhost.ps1

$ErrorActionPreference = "Stop"
$puerto = 8000
$raiz   = $PSScriptRoot
Set-Location $raiz

Write-Host "Carpeta: $raiz" -ForegroundColor Cyan
Write-Host "Abre en el navegador: http://127.0.0.1:$puerto/" -ForegroundColor Green
Write-Host "Ctrl+C para detener el servidor.`n" -ForegroundColor Yellow

php -S "127.0.0.1:$puerto" -t $raiz
