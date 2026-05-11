<?php
/**
 * backup.php — Copia de seguridad automática de DOGroup
 * Acceso: Solo admin
 * URL: tudominio.cl/backup.php
 */
require_once 'config.php';
requireAuth();
if ($usuario['rol'] !== 'admin') { http_response_code(403); die('Acceso denegado.'); }

$accion = $_GET['accion'] ?? 'ver';

// Carpeta de backups (fuera de public_html si es posible)
$backupDir = dirname($_SERVER['DOCUMENT_ROOT'] ?? __DIR__) . '/backups_dogroup';
if (!is_dir($backupDir)) {
    // Fallback dentro del proyecto pero protegido
    $backupDir = __DIR__ . '/backups';
    if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
    // Proteger con .htaccess
    file_put_contents($backupDir . '/.htaccess', "Require all denied\n");
}

$dbPath = __DIR__ . '/ocdogroup.db';

/* ── Crear backup ── */
if ($accion === 'crear') {
    $fecha    = date('Y-m-d_H-i-s');
    $destino  = $backupDir . "/dogroup_backup_{$fecha}.db";
    
    if (!file_exists($dbPath)) {
        die('Error: BD no encontrada en '.$dbPath);
    }
    
    // Usar SQLite VACUUM INTO si está disponible (SQLite 3.27+)
    try {
        $tmpPdo = new PDO('sqlite:'.$dbPath);
        $tmpPdo->exec("VACUUM INTO '$destino'");
        $size = filesize($destino);
        $_SESSION['backup_msg'] = "✅ Backup creado: dogroup_backup_{$fecha}.db (".round($size/1024)." KB)";
    } catch(Exception $e) {
        // Fallback: copia directa del archivo
        if (copy($dbPath, $destino)) {
            $size = filesize($destino);
            $_SESSION['backup_msg'] = "✅ Backup creado: dogroup_backup_{$fecha}.db (".round($size/1024)." KB)";
        } else {
            $_SESSION['backup_msg'] = "❌ Error al crear backup: ".$e->getMessage();
        }
    }
    header('Location: backup.php'); exit;
}

/* ── Descargar BD directamente ── */
if ($accion === 'descargar_db') {
    if (!file_exists($dbPath)) { die('BD no encontrada.'); }
    $fecha = date('Y-m-d');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="dogroup_'.$fecha.'.db"');
    header('Content-Length: ' . filesize($dbPath));
    readfile($dbPath);
    exit;
}

/* ── Descargar backup específico ── */
if ($accion === 'descargar' && !empty($_GET['file'])) {
    $file = basename($_GET['file']);
    $ruta = $backupDir . '/' . $file;
    if (file_exists($ruta) && str_ends_with($file, '.db')) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.$file.'"');
        header('Content-Length: ' . filesize($ruta));
        readfile($ruta);
        exit;
    }
}

/* ── Eliminar backup ── */
if ($accion === 'eliminar' && !empty($_GET['file'])) {
    $file = basename($_GET['file']);
    $ruta = $backupDir . '/' . $file;
    if (file_exists($ruta)) { unlink($ruta); }
    header('Location: backup.php'); exit;
}

// Listar backups existentes
$backups = [];
foreach (glob($backupDir . '/dogroup_backup_*.db') as $f) {
    $backups[] = [
        'nombre'  => basename($f),
        'tamanio' => round(filesize($f)/1024),
        'fecha'   => date('d/m/Y H:i', filemtime($f)),
        'ts'      => filemtime($f),
    ];
}
usort($backups, fn($a,$b) => $b['ts'] - $a['ts']);

// Stats BD
$dbSize = file_exists($dbPath) ? round(filesize($dbPath)/1024) : 0;
$tablas = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn();
$migs   = $pdo->query("SELECT COUNT(*) FROM migrations_log WHERE estado='ok'")->fetchColumn();

require_once 'includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h3 class="fw-bold mb-1"><i class="bi bi-shield-lock-fill text-success me-2"></i>Copias de Seguridad</h3>
    <p class="text-muted small mb-0">Protege los datos del sistema DOGroup</p>
  </div>
</div>

<?php if (!empty($_SESSION['backup_msg'])): ?>
<div class="alert alert-<?= str_starts_with($_SESSION['backup_msg'],'✅')?'success':'danger' ?> alert-dismissible fade show">
  <?= htmlspecialchars($_SESSION['backup_msg']) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php unset($_SESSION['backup_msg']); endif; ?>

<div class="row g-3 mb-4">
  <!-- Estado BD -->
  <div class="col-md-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <h6 class="fw-bold mb-3"><i class="bi bi-database-fill text-primary me-1"></i>Base de Datos</h6>
        <div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted small">Archivo</span><span class="small fw-semibold">ocdogroup.db</span></div>
        <div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted small">Tamaño</span><span class="small fw-semibold"><?= $dbSize ?> KB</span></div>
        <div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted small">Tablas</span><span class="small fw-semibold"><?= $tablas ?></span></div>
        <div class="d-flex justify-content-between py-1"><span class="text-muted small">Migraciones</span><span class="small fw-semibold"><?= $migs ?> aplicadas</span></div>
      </div>
    </div>
  </div>

  <!-- Acciones rápidas -->
  <div class="col-md-8">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <h6 class="fw-bold mb-3"><i class="bi bi-lightning-fill text-warning me-1"></i>Acciones</h6>
        <div class="d-flex gap-2 flex-wrap">
          <a href="backup.php?accion=crear" class="btn btn-success fw-bold"
             onclick="return confirm('¿Crear copia de seguridad ahora?')">
            <i class="bi bi-database-fill-add me-1"></i>Crear backup ahora
          </a>
          <a href="backup.php?accion=descargar_db" class="btn btn-primary fw-bold">
            <i class="bi bi-download me-1"></i>Descargar BD actual
          </a>
        </div>
        <div class="alert alert-info mt-3 py-2 mb-0" style="font-size:.82rem">
          <i class="bi bi-lightbulb-fill me-1"></i>
          <strong>Recomendación:</strong> Descarga la BD antes de subir una actualización del sistema.
          El archivo <code>.db</code> contiene todos tus datos: tickets, usuarios, validaciones, OC, etc.
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Lista de backups -->
<div class="card border-0 shadow-sm">
  <div class="card-header bg-white fw-bold d-flex align-items-center justify-content-between">
    <span><i class="bi bi-clock-history me-2"></i>Historial de backups</span>
    <span class="badge bg-secondary"><?= count($backups) ?></span>
  </div>
  <?php if (empty($backups)): ?>
  <div class="card-body text-center text-muted py-4">
    <i class="bi bi-database-x" style="font-size:2.5rem;opacity:.3;display:block;margin-bottom:10px"></i>
    Sin backups aún. Crea uno ahora.
  </div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr><th>Archivo</th><th>Tamaño</th><th>Fecha</th><th class="text-end">Acciones</th></tr>
      </thead>
      <tbody>
      <?php foreach ($backups as $i => $b): ?>
      <tr <?= $i===0?'class="table-success"':'' ?>>
        <td>
          <i class="bi bi-database-fill text-success me-2"></i>
          <code style="font-size:.82rem"><?= htmlspecialchars($b['nombre']) ?></code>
          <?php if ($i===0): ?><span class="badge bg-success ms-1">Más reciente</span><?php endif; ?>
        </td>
        <td><small><?= $b['tamanio'] ?> KB</small></td>
        <td><small><?= $b['fecha'] ?></small></td>
        <td class="text-end">
          <a href="backup.php?accion=descargar&file=<?= urlencode($b['nombre']) ?>" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-download me-1"></i>Descargar
          </a>
          <a href="backup.php?accion=eliminar&file=<?= urlencode($b['nombre']) ?>"
             class="btn btn-sm btn-outline-danger ms-1"
             onclick="return confirm('¿Eliminar este backup?')">
            <i class="bi bi-trash"></i>
          </a>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- Guía de actualización segura -->
<div class="card border-0 shadow-sm mt-3">
  <div class="card-header bg-white fw-bold">
    <i class="bi bi-arrow-up-circle-fill text-primary me-2"></i>Cómo actualizar el sistema sin perder datos
  </div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-3 text-center">
        <div style="width:48px;height:48px;border-radius:50%;background:#d1fae5;display:flex;align-items:center;justify-content:center;margin:0 auto 8px;font-size:1.3rem;font-weight:900;color:#065f46">1</div>
        <strong style="font-size:.85rem">Descargar BD</strong>
        <p class="text-muted small mt-1">Clic en "Descargar BD actual" — guárdala en tu PC</p>
      </div>
      <div class="col-md-3 text-center">
        <div style="width:48px;height:48px;border-radius:50%;background:#dbeafe;display:flex;align-items:center;justify-content:center;margin:0 auto 8px;font-size:1.3rem;font-weight:900;color:#1e40af">2</div>
        <strong style="font-size:.85rem">Subir ZIP nuevo</strong>
        <p class="text-muted small mt-1">Sube el nuevo ZIP en BenHosting y extrae los archivos</p>
      </div>
      <div class="col-md-3 text-center">
        <div style="width:48px;height:48px;border-radius:50%;background:#fef3c7;display:flex;align-items:center;justify-content:center;margin:0 auto 8px;font-size:1.3rem;font-weight:900;color:#92400e">3</div>
        <strong style="font-size:.85rem">Verificar BD</strong>
        <p class="text-muted small mt-1">El sistema de migraciones actualiza la BD automáticamente al primer acceso</p>
      </div>
      <div class="col-md-3 text-center">
        <div style="width:48px;height:48px;border-radius:50%;background:#f3e8ff;display:flex;align-items:center;justify-content:center;margin:0 auto 8px;font-size:1.3rem;font-weight:900;color:#6b21a8">4</div>
        <strong style="font-size:.85rem">Si algo falla</strong>
        <p class="text-muted small mt-1">Restaura la BD descargada en el paso 1 subiendo el archivo .db al servidor</p>
      </div>
    </div>
  </div>
</div>

<?php require_once 'includes/footer.php'; ?>
