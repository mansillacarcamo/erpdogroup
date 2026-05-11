# ─────────────────────────────────────────────────────────────
#  RESTAURAR BACKUP — DoGroup
#  Lista los backups disponibles y restaura el elegido
#  Antes de sobrescribir, guarda los archivos actuales en
#  backups/_pre_restore_<timestamp>/ por si hay que revertir.
# ─────────────────────────────────────────────────────────────

$ErrorActionPreference = 'Stop'
$base    = Split-Path -Parent $MyInvocation.MyCommand.Path
$destino = Join-Path $base 'backups'

if (!(Test-Path $destino)) {
  Write-Host "No existe la carpeta backups/. Ejecuta primero backup_auto.ps1" -ForegroundColor Red
  exit 1
}

# 1) Listar backups disponibles, más reciente primero
$zips = Get-ChildItem -Path $destino -Filter 'backup_*.zip' -File |
  Sort-Object LastWriteTime -Descending

if ($zips.Count -eq 0) {
  Write-Host "No hay backups en $destino" -ForegroundColor Red
  exit 1
}

Write-Host ""
Write-Host "Backups disponibles:" -ForegroundColor Cyan
Write-Host "─────────────────────────────────────────────"
for ($i = 0; $i -lt $zips.Count; $i++) {
  $z = $zips[$i]
  $tam = [math]::Round($z.Length / 1MB, 2)
  Write-Host ("  [{0}]  {1}   ({2} MB)   {3}" -f ($i+1), $z.Name, $tam, $z.LastWriteTime)
}
Write-Host "─────────────────────────────────────────────"
Write-Host ""

# 2) Elegir cuál restaurar
$idx = Read-Host "Numero de backup a restaurar (Enter = 1, el mas reciente)"
if ([string]::IsNullOrWhiteSpace($idx)) { $idx = 1 }
$idx = [int]$idx
if ($idx -lt 1 -or $idx -gt $zips.Count) {
  Write-Host "Numero fuera de rango" -ForegroundColor Red
  exit 1
}
$zip = $zips[$idx - 1]

Write-Host ""
Write-Host ("Vas a restaurar: {0}" -f $zip.Name) -ForegroundColor Yellow
Write-Host "Esto SOBRESCRIBIRA las bases .db actuales y la carpeta uploads/" -ForegroundColor Yellow
Write-Host "(se guardara una copia de los archivos actuales en backups/_pre_restore_*)"
$confirm = Read-Host "Escribe SI para continuar"
if ($confirm -ne 'SI') {
  Write-Host "Cancelado" -ForegroundColor Yellow
  exit 0
}

# 3) Crear copia de seguridad de los archivos ACTUALES antes de sobrescribir
$stamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$pre   = Join-Path $destino "_pre_restore_$stamp"
New-Item -ItemType Directory -Path $pre | Out-Null

Get-ChildItem -Path $base -Filter '*.db' -File | ForEach-Object {
  Copy-Item $_.FullName -Destination $pre -Force
}
$sub = Join-Path $base 'ocdogroup'
if (Test-Path $sub) {
  Get-ChildItem -Path $sub -Filter '*.db' -File | ForEach-Object {
    Copy-Item $_.FullName -Destination (Join-Path $pre ('ocdogroup_' + $_.Name)) -Force
  }
}
$uploads = Join-Path $base 'uploads'
if (Test-Path $uploads) {
  Copy-Item -Path $uploads -Destination (Join-Path $pre 'uploads') -Recurse -Force
}
Write-Host ("Copia previa guardada en: {0}" -f $pre) -ForegroundColor Green

# 4) Descomprimir el backup elegido en una carpeta temporal
$tmp = Join-Path $destino ("_restore_tmp_" + $stamp)
New-Item -ItemType Directory -Path $tmp | Out-Null
Expand-Archive -Path $zip.FullName -DestinationPath $tmp -Force

# 5) Restaurar bases .db
Get-ChildItem -Path $tmp -Filter '*.db' -File | ForEach-Object {
  if ($_.Name -like 'ocdogroup_*') {
    # ocdogroup_xxx.db => va a la subcarpeta ocdogroup/ con su nombre original
    $nombreOriginal = $_.Name -replace '^ocdogroup_', ''
    $dst = Join-Path $sub $nombreOriginal
    if (!(Test-Path $sub)) { New-Item -ItemType Directory -Path $sub | Out-Null }
    Copy-Item $_.FullName -Destination $dst -Force
    Write-Host ("  Restaurado: ocdogroup/{0}" -f $nombreOriginal) -ForegroundColor Green
  } else {
    Copy-Item $_.FullName -Destination (Join-Path $base $_.Name) -Force
    Write-Host ("  Restaurado: {0}" -f $_.Name) -ForegroundColor Green
  }
}

# 6) Restaurar uploads/
$tmpUploads = Join-Path $tmp 'uploads'
if (Test-Path $tmpUploads) {
  if (Test-Path $uploads) { Remove-Item $uploads -Recurse -Force }
  Copy-Item -Path $tmpUploads -Destination $base -Recurse -Force
  Write-Host "  Restaurado: uploads/" -ForegroundColor Green
}

# 7) Limpiar carpeta temporal
Remove-Item $tmp -Recurse -Force

Write-Host ""
Write-Host "RESTAURACION COMPLETA" -ForegroundColor Green
Write-Host ("Si algo salio mal, los archivos previos estan en: {0}" -f $pre)
