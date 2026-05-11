# Genera ZIP listo para subir al hosting (ej. Benza Hosting) del sistema OCDO Group.
# Ejecutar:
#   powershell -ExecutionPolicy Bypass -File .\empaquetar-benhosting.ps1
#
# IMPORTANTE: este ZIP es para ACTUALIZAR el sitio sin pisar datos de producción.
# Por eso EXCLUYE:
#   - ocdogroup.db        (base SQLite de producción: usuarios, OCs, cotizaciones, clientes)
#   - uploads/*           (archivos subidos por los usuarios)
#   - .git, *.zip, scripts de empaquetado, archivos temporales
#
# Si quieres un ZIP COMPLETO (primera instalación en hosting nuevo), pasa -Completo.
#
# Por defecto el ZIP se guarda en el ESCRITORIO del usuario actual.
# Puedes pasar -Salida "C:\ruta\custom.zip" para forzar otro destino.
param(
  [switch]$Completo,
  [switch]$IncluirDB,
  [switch]$IncluirUploads,
  [string]$Salida
)

$ErrorActionPreference = "Stop"
$raiz = $PSScriptRoot

if ([string]::IsNullOrWhiteSpace($Salida)) {
  $desktop = [Environment]::GetFolderPath('Desktop')
  $nombreZip = if ($Completo) { "ocdogroup-benhosting-COMPLETO.zip" } else { "ocdogroup-benhosting.zip" }
  $Salida = Join-Path $desktop $nombreZip
}

$staging = Join-Path $env:TEMP ("ocdogroup-stg-" + [Guid]::NewGuid().ToString('N'))

if (Test-Path $Salida) { Remove-Item $Salida -Force }
New-Item -ItemType Directory -Path $staging -Force | Out-Null

# Patrones a excluir SIEMPRE (basura local / control de versiones / scripts internos)
$excluirSiempre = @(
  '\.git(\\|/|$)',
  '\.gitignore$',
  '\.vscode(\\|/|$)',
  '\.idea(\\|/|$)',
  'node_modules(\\|/|$)',
  '\.DS_Store$',
  'Thumbs\.db$',
  '\.zip$',
  'response\.html$',
  'resp\.html$',
  '_test_.*\.php$',
  '_migrar_.*\.php$',
  'empaquetar-benhosting\.ps1$'
)

# Patrones EXTRA que se excluyen SOLO en modo "actualizacion" (no -Completo)
$excluirActualizacion = @(
  '^ocdogroup\.db$',
  '^uploads[\\/].+'
)

$extra = if (-not $Completo) { $excluirActualizacion } else { @() }

if ($IncluirDB)       { $extra = $extra | Where-Object { $_ -notmatch 'ocdogroup\.db' } }
if ($IncluirUploads)  { $extra = $extra | Where-Object { $_ -notmatch 'uploads' } }

$exclusiones = $excluirSiempre + $extra

Write-Host "Modo:    $(if ($Completo) { 'COMPLETO (primera instalacion)' } else { 'ACTUALIZACION (preserva datos de produccion)' })" -ForegroundColor Cyan
Write-Host "Origen:  $raiz"
Write-Host "Staging: $staging"
Write-Host "Salida:  $Salida"
Write-Host ""

# Copia segura: recorre archivos y aplica exclusiones por regex sobre la ruta relativa
$todos = Get-ChildItem -Path $raiz -Recurse -File -Force
$incluidos = 0
$excluidos = 0
foreach ($f in $todos) {
  $rel = $f.FullName.Substring($raiz.Length).TrimStart('\','/')
  $skip = $false
  foreach ($pat in $exclusiones) {
    if ($rel -match $pat) { $skip = $true; break }
  }
  if ($skip) {
    $excluidos++
    continue
  }
  $destFile = Join-Path $staging $rel
  $destDir = Split-Path $destFile -Parent
  if (-not (Test-Path $destDir)) { New-Item -ItemType Directory -Path $destDir -Force | Out-Null }
  Copy-Item -LiteralPath $f.FullName -Destination $destFile -Force
  $incluidos++
}

Write-Host "Archivos incluidos: $incluidos"
Write-Host "Archivos excluidos: $excluidos"

# Comprimir el CONTENIDO de staging (no la carpeta padre) para que al descomprimir
# en public_html queden index.php, css/, img/, vendor/, etc. directamente en la raiz.
Compress-Archive -Path (Join-Path $staging '*') -DestinationPath $Salida -CompressionLevel Optimal

Remove-Item $staging -Recurse -Force

$tam = (Get-Item $Salida).Length / 1MB
Write-Host ""
Write-Host "OK -> $Salida" -ForegroundColor Green
Write-Host ("Tamano: {0} MB" -f ([math]::Round($tam, 2)))

if (-not $Completo) {
  Write-Host ""
  Write-Host "Para hosting NUEVO (sin datos): vuelve a ejecutar con  -Completo" -ForegroundColor Yellow
}
