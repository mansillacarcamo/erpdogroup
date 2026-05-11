# ─────────────────────────────────────────────────────────────
#  BACKUP AUTOMÁTICO — DoGroup
#  Copia las bases de datos y archivos subidos a backups/
#  Mantiene los últimos 12 backups (60 días si corre cada 5).
#  Envía correo de notificación a deptoinformatica@dogroup.cl
# ─────────────────────────────────────────────────────────────

$ErrorActionPreference = 'Stop'
$base    = Split-Path -Parent $MyInvocation.MyCommand.Path
$destino = Join-Path $base 'backups'
$stamp   = Get-Date -Format 'yyyyMMdd_HHmmss'
$carpeta = Join-Path $destino "backup_$stamp"
$logFile = Join-Path $destino 'backup.log'

function Log($msg) {
  $linea = "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] $msg"
  Add-Content -Path $logFile -Value $linea -Encoding utf8
}

# Localiza el ejecutable de PHP. Primero el del PATH, despues Laragon.
function Find-Php {
  $cmd = Get-Command php.exe -ErrorAction SilentlyContinue
  if ($cmd) { return $cmd.Source }
  $candidatos = @(
    'C:\laragon\bin\php\php.exe',
    'C:\xampp\php\php.exe'
  )
  foreach ($c in $candidatos) { if (Test-Path $c) { return $c } }
  $busq = Get-ChildItem 'C:\laragon\bin\php' -Recurse -Filter 'php.exe' -ErrorAction SilentlyContinue | Select-Object -First 1
  if ($busq) { return $busq.FullName }
  return $null
}

function Notificar($estado, $archivo, $tamano, $mensaje) {
  $php = Find-Php
  $emailScript = Join-Path $base 'backup_email.php'
  if (-not $php -or -not (Test-Path $emailScript)) {
    Log "AVISO: No se pudo enviar correo (php.exe o backup_email.php no disponibles)."
    return
  }
  try {
    if ($estado -eq 'ok') {
      & $php $emailScript "--estado=ok" "--archivo=$archivo" "--tamano=$tamano" 2>&1 | Out-Null
    } else {
      & $php $emailScript "--estado=error" "--mensaje=$mensaje" 2>&1 | Out-Null
    }
    Log "Notificacion enviada a deptoinformatica@dogroup.cl"
  } catch {
    Log "ERROR al notificar: $($_.Exception.Message)"
  }
}

try {
  if (!(Test-Path $destino)) { New-Item -ItemType Directory -Path $destino | Out-Null }
  New-Item -ItemType Directory -Path $carpeta | Out-Null

  # Copiar bases SQLite (raíz y ocdogroup)
  Get-ChildItem -Path $base -Filter '*.db' -File | ForEach-Object {
    Copy-Item $_.FullName -Destination $carpeta -Force
  }
  $sub = Join-Path $base 'ocdogroup'
  if (Test-Path $sub) {
    Get-ChildItem -Path $sub -Filter '*.db' -File | ForEach-Object {
      Copy-Item $_.FullName -Destination (Join-Path $carpeta ('ocdogroup_' + $_.Name)) -Force
    }
  }

  # Copiar carpeta uploads/ (archivos adjuntos: gastos, OC, etc.)
  $uploads = Join-Path $base 'uploads'
  if (Test-Path $uploads) {
    Copy-Item -Path $uploads -Destination (Join-Path $carpeta 'uploads') -Recurse -Force
  }

  # Comprimir el backup en un .zip y borrar la carpeta temporal
  $zipPath = "$carpeta.zip"
  Compress-Archive -Path "$carpeta\*" -DestinationPath $zipPath -Force
  Remove-Item -Path $carpeta -Recurse -Force

  # Calcular tamaño del .zip generado
  $tamMB = [math]::Round((Get-Item $zipPath).Length / 1MB, 2)
  $tamTxt = "$tamMB MB"
  Log "Backup OK -> $zipPath ($tamTxt)"

  # Retener solo los últimos 12 .zip de backup
  Get-ChildItem -Path $destino -Filter 'backup_*.zip' -File |
    Sort-Object LastWriteTime -Descending |
    Select-Object -Skip 12 |
    ForEach-Object {
      Remove-Item $_.FullName -Force
      Log "Eliminado backup antiguo -> $($_.Name)"
    }

  Notificar 'ok' (Split-Path $zipPath -Leaf) $tamTxt $null
}
catch {
  $err = $_.Exception.Message
  Log "ERROR: $err"
  Notificar 'error' $null $null $err
  exit 1
}
