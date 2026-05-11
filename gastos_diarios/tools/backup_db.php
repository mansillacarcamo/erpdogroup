<?php
// ============================================================
//  BACKUP AUTOMATICO - controlgastos.db
//  Uso: php tools/backup_db.php
//  - Genera una copia consistente con VACUUM INTO (seguro con DB en uso)
//  - Guarda en backups/ con timestamp
//  - Rota: conserva los ultimos 14 dias
// ============================================================

$BASE        = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..');
$DB_PATH     = $BASE . DIRECTORY_SEPARATOR . 'controlgastos.db';
$BACKUP_DIR  = $BASE . DIRECTORY_SEPARATOR . 'backups';
$RETAIN_DAYS = 14;

if (!is_file($DB_PATH)) {
    fwrite(STDERR, "[ERROR] No se encontro la base de datos: $DB_PATH\n");
    exit(1);
}

if (!is_dir($BACKUP_DIR)) {
    if (!@mkdir($BACKUP_DIR, 0775, true)) {
        fwrite(STDERR, "[ERROR] No se pudo crear $BACKUP_DIR\n");
        exit(1);
    }
}

$ts          = date('Ymd_His');
$nombreBak   = "controlgastos_$ts.db";
$rutaBak     = $BACKUP_DIR . DIRECTORY_SEPARATOR . $nombreBak;
$rutaBakSql  = $rutaBak;
$rutaBakSqlEsc = str_replace("'", "''", $rutaBakSql);

$inicio = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] Backup iniciado\n";
echo "  Origen : $DB_PATH\n";
echo "  Destino: $rutaBak\n";

try {
    $pdo = new PDO('sqlite:' . $DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // VACUUM INTO crea una copia consistente sin bloquear escrituras concurrentes
    $pdo->exec("VACUUM INTO '$rutaBakSqlEsc'");
    $pdo = null;
} catch (Exception $e) {
    fwrite(STDERR, "[ERROR] VACUUM INTO fallo: " . $e->getMessage() . "\n");
    // Fallback: copia binaria simple (puede ser inconsistente si hay escritura activa)
    if (!@copy($DB_PATH, $rutaBak)) {
        fwrite(STDERR, "[ERROR] Copia binaria tambien fallo\n");
        exit(2);
    }
    echo "  [WARN] Se uso copia binaria (fallback). Se recomienda revisar.\n";
}

$tam = is_file($rutaBak) ? filesize($rutaBak) : 0;
$dur = round((microtime(true) - $inicio) * 1000);
echo "  OK ($tam bytes, $dur ms)\n";

// ============================================================
//  ROTACION: borrar backups mas viejos que RETAIN_DAYS
// ============================================================
$limite = time() - ($RETAIN_DAYS * 86400);
$borrados = 0;
foreach (glob($BACKUP_DIR . DIRECTORY_SEPARATOR . 'controlgastos_*.db') as $f) {
    if (filemtime($f) < $limite) {
        if (@unlink($f)) { $borrados++; }
    }
}
if ($borrados) echo "  Rotacion: $borrados backup(s) antiguo(s) eliminado(s)\n";

// ============================================================
//  LOG persistente
// ============================================================
$logLine = "[" . date('Y-m-d H:i:s') . "] $nombreBak ($tam bytes, $dur ms)\n";
@file_put_contents($BACKUP_DIR . DIRECTORY_SEPARATOR . '_backup.log', $logLine, FILE_APPEND);

echo "[" . date('Y-m-d H:i:s') . "] Backup completado\n";
exit(0);
