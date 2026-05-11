<?php
require_once '../config.php';
requireAuth();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM cotizaciones WHERE id = ?");
$stmt->execute([$id]);
$cot = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cot) { header('Location: historial_cotizaciones.php'); exit; }

$bloqueado = in_array($cot['estado'], ['propuesta','negociacion'], true);
$accionesBloqueadas = ['asociar','asociar_oc','agregar_proceso','adjuntar_archivo'];

$msg = null;
if (!empty($_GET['obra_asignada'])) {
    $msg = ['success', 'Obra asignada correctamente a la cotización.'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($bloqueado && in_array($action, $accionesBloqueadas, true)) {
        $msg = ['warning', 'Esta cotización está en estado "'.ucfirst($cot['estado']).'". Las acciones se habilitarán cuando esté Adjudicada.'];
        $action = '';
    }

    if ($action === 'cambiar_estado') {
        $nuevoEstado = $_POST['estado'] ?? '';
        $comentario = trim($_POST['comentario'] ?? '');
        $estadosValidos = ['propuesta','negociacion','adjudicada','desierta','cerrado','pendiente'];
        if (in_array($nuevoEstado, $estadosValidos)) {
            $estadoAnterior = $cot['estado'];
            $pdo->prepare("UPDATE cotizaciones SET estado = ? WHERE id = ?")->execute([$nuevoEstado, $id]);
            $pdo->prepare("INSERT INTO cot_procesos (cot_id, tipo, titulo, descripcion, estado_anterior, estado_nuevo, usuario) VALUES (?,?,?,?,?,?,?)")
                ->execute([$id, 'cambio_estado', 'Cambio de estado', $comentario ?: null, $estadoAnterior, $nuevoEstado, $usuario['nombre']]);
            $cot['estado'] = $nuevoEstado;
            // Si se acaba de adjudicar, redirigir con flag para abrir el popup de asignar obra
            if ($nuevoEstado === 'adjudicada' && $estadoAnterior !== 'adjudicada') {
                header('Location: seguimiento_cot.php?id=' . $id . '&adjudicada=1');
                exit;
            }
            $msg = ['success', 'Estado actualizado a ' . ucfirst($nuevoEstado)];
        }
    }

    if ($action === 'asignar_obra_cot') {
        $obraIdAsig = (int)($_POST['obra_id'] ?? 0);
        if ($obraIdAsig > 0) {
            $stObra = $pdo->prepare("SELECT codigo, nombre FROM obras WHERE id = ?");
            $stObra->execute([$obraIdAsig]);
            $obraSel = $stObra->fetch(PDO::FETCH_ASSOC);
            if ($obraSel) {
                $pdo->prepare("UPDATE cotizaciones SET obra_id = ?, cliente_obra = ? WHERE id = ?")
                    ->execute([$obraIdAsig, $obraSel['nombre'], $id]);
            } else {
                $pdo->prepare("UPDATE cotizaciones SET obra_id = ? WHERE id = ?")->execute([$obraIdAsig, $id]);
            }
            header('Location: seguimiento_cot.php?id=' . $id . '&obra_asignada=1');
            exit;
        }
        $msg = ['warning', 'No se seleccionó una obra válida.'];
    }

    if ($action === 'agregar_proceso') {
        $titulo = trim($_POST['titulo'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $tipo = trim($_POST['tipo'] ?? 'seguimiento');
        $estadoProc = trim($_POST['estado_proceso'] ?? 'pendiente');
        if ($titulo) {
            $pdo->prepare("INSERT INTO cot_procesos (cot_id, tipo, titulo, descripcion, estado_proceso, usuario) VALUES (?,?,?,?,?,?)")
                ->execute([$id, $tipo, $titulo, $descripcion ?: null, $estadoProc, $usuario['nombre']]);
            $procInsertId = $pdo->lastInsertId();

            $archivosSubidos = 0;
            if (!empty($_FILES['archivos']['name'][0])) {
                $uploadsDir = __DIR__ . '/uploads';
                for ($f = 0; $f < count($_FILES['archivos']['name']); $f++) {
                    if ($_FILES['archivos']['error'][$f] !== UPLOAD_ERR_OK) continue;
                    $nombreOrig = $_FILES['archivos']['name'][$f];
                    $tipoMime = $_FILES['archivos']['type'][$f];
                    $tamano = $_FILES['archivos']['size'][$f];
                    $ext = pathinfo($nombreOrig, PATHINFO_EXTENSION);
                    $nombreArch = uniqid('cot' . $id . '_proc' . $procInsertId . '_') . '.' . $ext;
                    if (move_uploaded_file($_FILES['archivos']['tmp_name'][$f], $uploadsDir . '/' . $nombreArch)) {
                        $pdo->prepare("INSERT INTO cot_archivos (cot_id, proc_id, nombre_original, nombre_archivo, tipo_mime, tamano, usuario) VALUES (?,?,?,?,?,?,?)")
                            ->execute([$id, $procInsertId, $nombreOrig, $nombreArch, $tipoMime, $tamano, $usuario['nombre']]);
                        $archivosSubidos++;
                    }
                }
            }
            $msgExtra = $archivosSubidos > 0 ? " con $archivosSubidos archivo(s)" : '';
            $msg = ['success', 'Proceso agregado correctamente' . $msgExtra];
        }
    }

    if ($action === 'adjuntar_archivo') {
        $procIdAdj = (int)($_POST['proc_id'] ?? 0);
        if (!empty($_FILES['archivos_adj']['name'][0])) {
            $uploadsDir = __DIR__ . '/uploads';
            $archivosSubidos = 0;
            for ($f = 0; $f < count($_FILES['archivos_adj']['name']); $f++) {
                if ($_FILES['archivos_adj']['error'][$f] !== UPLOAD_ERR_OK) continue;
                $nombreOrig = $_FILES['archivos_adj']['name'][$f];
                $tipoMime = $_FILES['archivos_adj']['type'][$f];
                $tamano = $_FILES['archivos_adj']['size'][$f];
                $ext = pathinfo($nombreOrig, PATHINFO_EXTENSION);
                $nombreArch = uniqid('cot' . $id . '_adj_') . '.' . $ext;
                if (move_uploaded_file($_FILES['archivos_adj']['tmp_name'][$f], $uploadsDir . '/' . $nombreArch)) {
                    $pdo->prepare("INSERT INTO cot_archivos (cot_id, proc_id, nombre_original, nombre_archivo, tipo_mime, tamano, usuario) VALUES (?,?,?,?,?,?,?)")
                        ->execute([$id, $procIdAdj ?: null, $nombreOrig, $nombreArch, $tipoMime, $tamano, $usuario['nombre']]);
                    $archivosSubidos++;
                }
            }
            $msg = ['success', "$archivosSubidos archivo(s) adjuntado(s) correctamente"];
        }
    }

    if ($action === 'eliminar_archivo') {
        $archId = (int)($_POST['arch_id'] ?? 0);
        $stmtArch = $pdo->prepare("SELECT * FROM cot_archivos WHERE id = ? AND cot_id = ?");
        $stmtArch->execute([$archId, $id]);
        $archElim = $stmtArch->fetch(PDO::FETCH_ASSOC);
        if ($archElim) {
            $rutaElim = __DIR__ . '/../uploads/' . $archElim['nombre_archivo'];
            if (file_exists($rutaElim)) unlink($rutaElim);
            $pdo->prepare("DELETE FROM cot_archivos WHERE id = ?")->execute([$archId]);
            $msg = ['success', 'Archivo eliminado'];
        }
    }

    if ($action === 'editar_proceso') {
        $procId = (int)($_POST['proc_id'] ?? 0);
        $titulo = trim($_POST['titulo'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $tipo = trim($_POST['tipo'] ?? 'seguimiento');
        $estadoProc = trim($_POST['estado_proceso'] ?? 'pendiente');
        if ($titulo && $procId) {
            $pdo->prepare("UPDATE cot_procesos SET titulo = ?, descripcion = ?, tipo = ?, estado_proceso = ? WHERE id = ? AND cot_id = ?")
                ->execute([$titulo, $descripcion ?: null, $tipo, $estadoProc, $procId, $id]);
            $msg = ['success', 'Proceso actualizado'];
        }
    }

    if ($action === 'cambiar_estado_proceso') {
        $procId = (int)($_POST['proc_id'] ?? 0);
        $estadoProc = trim($_POST['estado_proceso'] ?? '');
        $estadosProcesoValidos = ['pendiente','en_ejecucion','en_evaluacion','en_revision','aprobado','rechazado','desierta','completado'];
        if ($procId && in_array($estadoProc, $estadosProcesoValidos)) {
            $pdo->prepare("UPDATE cot_procesos SET estado_proceso = ? WHERE id = ? AND cot_id = ?")
                ->execute([$estadoProc, $procId, $id]);
            $msg = ['success', 'Estado del proceso actualizado'];
        }
    }

    if ($action === 'eliminar_proceso') {
        $procId = (int)($_POST['proc_id'] ?? 0);
        $pdo->prepare("DELETE FROM cot_procesos WHERE id = ? AND cot_id = ?")->execute([$procId, $id]);
        $msg = ['success', 'Proceso eliminado'];
    }

    if ($action === 'asociar') {
        $destNumero = (int)($_POST['cot_numero_destino'] ?? 0);
        $tipoAsoc = trim($_POST['tipo_asociacion'] ?? 'relacionada');
        $nota = trim($_POST['nota'] ?? '');
        if ($destNumero) {
            $stmtDest = $pdo->prepare("SELECT id FROM cotizaciones WHERE numero = ? AND id != ?");
            $stmtDest->execute([$destNumero, $id]);
            $dest = $stmtDest->fetch(PDO::FETCH_ASSOC);
            if ($dest) {
                $stmtExist = $pdo->prepare("SELECT COUNT(*) FROM cot_asociaciones WHERE cot_id_origen = ? AND cot_id_destino = ?");
                $stmtExist->execute([$id, $dest['id']]);
                if ((int)$stmtExist->fetchColumn() === 0) {
                    $pdo->prepare("INSERT INTO cot_asociaciones (cot_id_origen, cot_id_destino, tipo, nota, usuario) VALUES (?,?,?,?,?)")
                        ->execute([$id, $dest['id'], $tipoAsoc, $nota ?: null, $usuario['nombre']]);
                    $pdo->prepare("INSERT INTO cot_procesos (cot_id, tipo, titulo, descripcion, usuario) VALUES (?,?,?,?,?)")
                        ->execute([$id, 'asociacion', 'Cotización asociada', "Vinculada con Cot. N° $destNumero ($tipoAsoc)", $usuario['nombre']]);
                    $msg = ['success', "Asociada con Cotización N° $destNumero"];
                } else {
                    $msg = ['warning', 'Esta asociación ya existe'];
                }
            } else {
                $msg = ['danger', "No se encontró Cotización N° $destNumero"];
            }
        }
    }

    if ($action === 'eliminar_asociacion') {
        $asocId = (int)($_POST['asoc_id'] ?? 0);
        $pdo->prepare("DELETE FROM cot_asociaciones WHERE id = ?")->execute([$asocId]);
        $msg = ['success', 'Asociación eliminada'];
    }

    if ($action === 'asociar_oc') {
        $esExterna = (int)($_POST['es_externa'] ?? 0);

        if ($esExterna) {
            // OC empresa externa
            $extEmpresa = trim($_POST['oc_ext_empresa'] ?? '');
            $extNumero  = trim($_POST['oc_ext_numero']  ?? '');
            $extMonto   = (float)str_replace(['.','$',' '],['','',''], $_POST['oc_ext_monto'] ?? '0');
            $notaOC     = trim($_POST['nota_oc'] ?? '');

            if (!$extEmpresa || !$extNumero) {
                $msg = ['danger', 'Ingresa la empresa y el número de OC externa.'];
            } else {
                $archRuta = ''; $archNombre = '';
                if (!empty($_FILES['oc_ext_archivo']['name']) && $_FILES['oc_ext_archivo']['error'] === UPLOAD_ERR_OK) {
                    $dirOCExt = __DIR__ . '/../uploads/oc_externas';
                    if (!is_dir($dirOCExt)) mkdir($dirOCExt, 0775, true);
                    $orig = $_FILES['oc_ext_archivo']['name'];
                    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                    if (in_array($ext, ['pdf','jpg','jpeg','png','webp'])) {
                        $nuevo = 'ocext_'.$id.'_'.time().'.'.$ext;
                        if (move_uploaded_file($_FILES['oc_ext_archivo']['tmp_name'], $dirOCExt.'/'.$nuevo)) {
                            $archRuta   = 'oc_externas/'.$nuevo;
                            $archNombre = $orig;
                        }
                    }
                }
                $pdo->prepare("INSERT INTO cot_oc_asociaciones
                    (cot_id, oc_id, nota, usuario, es_externa, oc_ext_numero, oc_ext_empresa, oc_ext_monto, oc_ext_archivo, oc_ext_archivo_nombre)
                    VALUES (?,NULL,?,?,1,?,?,?,?,?)")
                    ->execute([$id, $notaOC ?: null, $usuario['nombre'],
                               $extNumero, $extEmpresa, $extMonto, $archRuta, $archNombre]);
                $pdo->prepare("INSERT INTO cot_procesos (cot_id, tipo, titulo, descripcion, usuario) VALUES (?,?,?,?,?)")
                    ->execute([$id, 'asociacion', 'OC externa adjuntada',
                               "OC N° {$extNumero} de {$extEmpresa}".($extMonto?" — \$".number_format($extMonto,0,',','.'):''),
                               $usuario['nombre']]);
                $msg = ['success', "OC externa N° {$extNumero} de {$extEmpresa} asociada correctamente."];
            }
        } else {
            // OC del sistema
            $ocNumero = (int)($_POST['oc_numero'] ?? 0);
            $notaOC   = trim($_POST['nota_oc'] ?? '');
            if ($ocNumero) {
                $stmtOC = $pdo->prepare("SELECT id, numero, proveedor_nombre, obra, total, estado FROM ordenes_compra WHERE numero = ?");
                $stmtOC->execute([$ocNumero]);
                $ocDest = $stmtOC->fetch(PDO::FETCH_ASSOC);
                if ($ocDest) {
                    $stmtExistOC = $pdo->prepare("SELECT COUNT(*) FROM cot_oc_asociaciones WHERE cot_id = ? AND oc_id = ?");
                    $stmtExistOC->execute([$id, $ocDest['id']]);
                    if ((int)$stmtExistOC->fetchColumn() === 0) {
                        $pdo->prepare("INSERT INTO cot_oc_asociaciones (cot_id, oc_id, nota, usuario, es_externa) VALUES (?,?,?,?,0)")
                            ->execute([$id, $ocDest['id'], $notaOC ?: null, $usuario['nombre']]);
                        $pdo->prepare("INSERT INTO cot_procesos (cot_id, tipo, titulo, descripcion, usuario) VALUES (?,?,?,?,?)")
                            ->execute([$id, 'asociacion', 'OC asociada', "Vinculada con OC N° {$ocDest['numero']} — {$ocDest['proveedor_nombre']}", $usuario['nombre']]);
                        $msg = ['success', "Orden de Compra N° {$ocDest['numero']} asociada correctamente"];
                    } else {
                        $msg = ['warning', 'Esta OC ya está asociada a esta cotización'];
                    }
                } else {
                    $msg = ['danger', "No se encontró Orden de Compra N° $ocNumero"];
                }
            }
        }
    }

    if ($action === 'eliminar_oc_asociacion') {
        $asocOCId = (int)($_POST['asoc_oc_id'] ?? 0);
        $pdo->prepare("DELETE FROM cot_oc_asociaciones WHERE id = ? AND cot_id = ?")->execute([$asocOCId, $id]);
        $msg = ['success', 'Asociación con OC eliminada'];
    }
}

$stmtProc = $pdo->prepare("SELECT * FROM cot_procesos WHERE cot_id = ? ORDER BY fecha DESC");
$stmtProc->execute([$id]);
$procesos = $stmtProc->fetchAll(PDO::FETCH_ASSOC);

$stmtAsoc = $pdo->prepare("
    SELECT a.*, c.numero, c.cliente_nombre, c.cliente_obra, c.total, c.estado AS cot_estado
    FROM cot_asociaciones a
    JOIN cotizaciones c ON a.cot_id_destino = c.id
    WHERE a.cot_id_origen = ?
    ORDER BY a.fecha DESC
");
$stmtAsoc->execute([$id]);
$asociaciones = $stmtAsoc->fetchAll(PDO::FETCH_ASSOC);

$stmtAsocInv = $pdo->prepare("
    SELECT a.*, c.numero, c.cliente_nombre, c.cliente_obra, c.total, c.estado AS cot_estado
    FROM cot_asociaciones a
    JOIN cotizaciones c ON a.cot_id_origen = c.id
    WHERE a.cot_id_destino = ?
    ORDER BY a.fecha DESC
");
$stmtAsocInv->execute([$id]);
$asociacionesInv = $stmtAsocInv->fetchAll(PDO::FETCH_ASSOC);

$todasAsociaciones = array_merge($asociaciones, $asociacionesInv);

$stmtOCAsoc = $pdo->prepare("
    SELECT a.*, oc.numero, oc.proveedor_nombre, oc.obra, oc.total, oc.estado AS oc_estado, oc.neto, oc.fecha AS oc_fecha
    FROM cot_oc_asociaciones a
    LEFT JOIN ordenes_compra oc ON a.oc_id = oc.id AND a.es_externa = 0
    WHERE a.cot_id = ?
    ORDER BY a.fecha DESC
");
$stmtOCAsoc->execute([$id]);
$ocAsociadas = $stmtOCAsoc->fetchAll(PDO::FETCH_ASSOC);

$totalOCAsociadas = 0;
foreach ($ocAsociadas as $oca) {
  if (!empty($oca['es_externa'])) {
    $totalOCAsociadas += (float)$oca['oc_ext_monto'];
  }
}

$stmtArchivos = $pdo->prepare("SELECT * FROM cot_archivos WHERE cot_id = ? ORDER BY fecha DESC");
$stmtArchivos->execute([$id]);
$todosArchivos = $stmtArchivos->fetchAll(PDO::FETCH_ASSOC);

$archivosPorProceso = [];
$archivosGenerales = [];
foreach ($todosArchivos as $arch) {
    if ($arch['proc_id']) {
        $archivosPorProceso[$arch['proc_id']][] = $arch;
    } else {
        $archivosGenerales[] = $arch;
    }
}

$iconosArchivo = [
    'pdf' => ['bi-file-earmark-pdf-fill', 'text-danger'],
    'doc' => ['bi-file-earmark-word-fill', 'text-primary'],
    'docx' => ['bi-file-earmark-word-fill', 'text-primary'],
    'xls' => ['bi-file-earmark-excel-fill', 'text-success'],
    'xlsx' => ['bi-file-earmark-excel-fill', 'text-success'],
    'ppt' => ['bi-file-earmark-ppt-fill', 'text-warning'],
    'pptx' => ['bi-file-earmark-ppt-fill', 'text-warning'],
    'jpg' => ['bi-file-earmark-image-fill', 'text-info'],
    'jpeg' => ['bi-file-earmark-image-fill', 'text-info'],
    'png' => ['bi-file-earmark-image-fill', 'text-info'],
    'gif' => ['bi-file-earmark-image-fill', 'text-info'],
    'zip' => ['bi-file-earmark-zip-fill', 'text-secondary'],
    'rar' => ['bi-file-earmark-zip-fill', 'text-secondary'],
    'txt' => ['bi-file-earmark-text-fill', 'text-muted'],
    'csv' => ['bi-file-earmark-spreadsheet', 'text-success'],
    'dwg' => ['bi-rulers', 'text-dark'],
    'dxf' => ['bi-rulers', 'text-dark'],
];

function formatBytes($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

function getIconoArchivo($nombre, $iconos) {
    $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
    return $iconos[$ext] ?? ['bi-file-earmark-fill', 'text-secondary'];
}

$badgesEstado = [
    'pendiente' => ['warning', 'hourglass-split'],
    'propuesta' => ['info', 'file-earmark-text'],
    'negociacion' => ['primary', 'chat-dots'],
    'adjudicada' => ['success', 'trophy'],
    'desierta' => ['secondary', 'dash-circle'],
    'cerrado' => ['dark', 'lock'],
];

$tiposProceso = [
    'seguimiento' => ['primary', 'geo-alt'],
    'cambio_estado' => ['warning', 'arrow-repeat'],
    'nota' => ['info', 'sticky'],
    'reunion' => ['success', 'people'],
    'llamada' => ['danger', 'telephone'],
    'email' => ['secondary', 'envelope'],
    'documento' => ['dark', 'file-earmark'],
    'asociacion' => ['purple', 'link-45deg'],
];

$estadosProceso = [
    'pendiente' => ['warning', 'hourglass-split', 'Pendiente'],
    'en_ejecucion' => ['primary', 'play-circle', 'En Ejecución'],
    'en_evaluacion' => ['info', 'search', 'En Evaluación'],
    'en_revision' => ['secondary', 'eye', 'En Revisión'],
    'aprobado' => ['success', 'check-circle', 'Aprobado'],
    'rechazado' => ['danger', 'x-circle', 'Rechazado'],
    'desierta' => ['dark', 'dash-circle', 'Desierta'],
    'completado' => ['success', 'check2-all', 'Completado'],
];

/* Lista de obras registradas para el modal de "Adjudicada" */
$obrasRegistradas = [];
try {
  $obrasRegistradas = $pdo->query("SELECT id, codigo, nombre, mandante FROM obras WHERE estado='activa' ORDER BY codigo")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $obrasRegistradas = []; }

require_once '../includes/header.php';
?>


<div class="d-flex align-items-center mb-3">
  <a href="historial_cotizaciones.php" class="btn btn-outline-secondary btn-sm me-3"><i class="bi bi-arrow-left me-1"></i>Historial</a>
  <a href="ver_cotizacion.php?id=<?= $id ?>" class="btn btn-outline-primary btn-sm me-3"><i class="bi bi-eye me-1"></i>Ver Cotización</a>
  <h5 class="mb-0">Seguimiento — Cotización N° <?= $cot['numero'] ?></h5>
  <?php $eb = $badgesEstado[$cot['estado']] ?? ['secondary','circle']; ?>
  <span class="badge bg-<?= $eb[0] ?> ms-3"><i class="bi bi-<?= $eb[1] ?> me-1"></i><?= ucfirst($cot['estado']) ?></span>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msg[0] ?> alert-dismissible fade show"><i class="bi bi-info-circle me-1"></i><?= $msg[1] ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="row g-4">
  <!-- COLUMNA IZQUIERDA: Info + Estado + Asociaciones -->
  <div class="col-lg-4">
    <!-- Info Cotización -->
    <div class="card shadow-sm border-0 mb-3">
      <div class="card-header bg-danger text-white py-2"><h6 class="mb-0"><i class="bi bi-receipt me-2"></i>Cotización N° <?= $cot['numero'] ?></h6></div>
      <div class="card-body p-3" style="font-size:13px;">
        <p class="mb-1"><strong>Cliente:</strong> <?= htmlspecialchars($cot['cliente_nombre']) ?></p>
        <?php
          $obraDisplay = $cot['cliente_obra'] ?? '';
          if (!$obraDisplay && !empty($cot['obra_id'])) {
              $stOb = $pdo->prepare("SELECT codigo, nombre FROM obras WHERE id = ?");
              $stOb->execute([(int)$cot['obra_id']]);
              if ($obraRow = $stOb->fetch(PDO::FETCH_ASSOC)) {
                  $obraDisplay = trim($obraRow['codigo'].' — '.$obraRow['nombre']);
              }
          }
        ?>
        <p class="mb-1"><strong>Obra:</strong> <?= htmlspecialchars($obraDisplay) ?></p>
        <p class="mb-1"><strong>Fecha:</strong> <?= date('d/m/Y', strtotime($cot['fecha'])) ?></p>
        <p class="mb-1"><strong>Total cotización:</strong> <span class="fw-bold text-success">$<?= formatCLP($cot['total']) ?></span></p>
        <?php if ($totalOCAsociadas > 0): ?>
        <p class="mb-1"><strong>Monto del proyecto (OC):</strong> <span class="fw-bold text-primary fs-6">$<?= formatCLP($totalOCAsociadas) ?></span></p>
        <?php endif; ?>
        <p class="mb-0"><strong>Creada por:</strong> <?= htmlspecialchars($cot['creada_por']) ?></p>
      </div>
    </div>

    <!-- Cambiar Estado -->
    <div class="card shadow-sm border-0 mb-3">
      <div class="card-header bg-dark text-white py-2"><h6 class="mb-0"><i class="bi bi-arrow-repeat me-2"></i>Cambiar Estado</h6></div>
      <div class="card-body p-3">
        <form method="POST">
          <input type="hidden" name="action" value="cambiar_estado">
          <div class="mb-2">
            <select name="estado" class="form-select form-select-sm" required>
              <option value="">-- Seleccionar --</option>
              <option value="propuesta" <?= $cot['estado']==='propuesta'?'selected':'' ?>>Propuesta</option>
              <option value="negociacion" <?= $cot['estado']==='negociacion'?'selected':'' ?>>Negociación</option>
              <option value="adjudicada" <?= $cot['estado']==='adjudicada'?'selected':'' ?>>Adjudicada</option>
              <option value="desierta" <?= $cot['estado']==='desierta'?'selected':'' ?>>Desierta</option>
              <option value="cerrado" <?= $cot['estado']==='cerrado'?'selected':'' ?>>Cerrado</option>
              <option value="pendiente" <?= $cot['estado']==='pendiente'?'selected':'' ?>>Pendiente</option>
            </select>
          </div>
          <div class="mb-2">
            <textarea name="comentario" class="form-control form-control-sm" rows="2" placeholder="Comentario (opcional)"></textarea>
          </div>
          <button class="btn btn-warning btn-sm w-100"><i class="bi bi-check-lg me-1"></i>Actualizar Estado</button>
        </form>
      </div>
    </div>

    <!-- Asociar Cotizaciones -->
    <div class="card shadow-sm border-0 mb-3<?= $bloqueado ? ' opacity-75' : '' ?>">
      <div class="card-header bg-dark text-white py-2"><h6 class="mb-0"><i class="bi bi-link-45deg me-2"></i>Asociar Cotización</h6></div>
      <div class="card-body p-3">
        <?php if ($bloqueado): ?>
        <div class="alert alert-warning py-2 px-2 mb-2 small"><i class="bi bi-lock me-1"></i>Disponible cuando la cotización esté <strong>Adjudicada</strong>.</div>
        <?php endif; ?>
        <fieldset <?= $bloqueado ? 'disabled' : '' ?>>
        <form method="POST">
          <input type="hidden" name="action" value="asociar">
          <div class="mb-2">
            <label class="form-label small fw-semibold">Seleccionar Cotización</label>
            <?php
              $stmtCotsDisp = $pdo->prepare("SELECT numero, cliente_nombre, cliente_obra, total, estado FROM cotizaciones WHERE id != ? ORDER BY numero DESC");
              $stmtCotsDisp->execute([$id]);
              $cotsDisp = $stmtCotsDisp->fetchAll(PDO::FETCH_ASSOC);
            ?>
            <?php if (!empty($cotsDisp)): ?>
            <select name="cot_numero_destino" class="form-select form-select-sm" required>
              <option value="">— Seleccionar Cotización —</option>
              <?php foreach ($cotsDisp as $c): ?>
              <option value="<?= (int)$c['numero'] ?>">
                Cot-<?= htmlspecialchars($c['numero']) ?>
                — <?= htmlspecialchars($c['cliente_nombre']) ?>
                <?= $c['cliente_obra'] ? '· '.htmlspecialchars($c['cliente_obra']) : '' ?>
                ($<?= number_format((float)$c['total'],0,',','.') ?>)
                [<?= ucfirst($c['estado']) ?>]
              </option>
              <?php endforeach; ?>
            </select>
            <?php else: ?>
            <div class="form-text text-muted">Sin otras cotizaciones registradas. Puedes ingresar el número manualmente:</div>
            <input type="number" name="cot_numero_destino" class="form-control form-control-sm mt-1" placeholder="Ej: 1150" required>
            <?php endif; ?>
          </div>
          <div class="mb-2">
            <label class="form-label small fw-semibold">Tipo de relación</label>
            <select name="tipo_asociacion" class="form-select form-select-sm">
              <option value="relacionada">Relacionada</option>
              <option value="version_anterior">Versión anterior</option>
              <option value="version_nueva">Versión nueva</option>
              <option value="complementaria">Complementaria</option>
              <option value="reemplaza">Reemplaza a</option>
            </select>
          </div>
          <div class="mb-2">
            <textarea name="nota" class="form-control form-control-sm" rows="1" placeholder="Nota (opcional)"></textarea>
          </div>
          <button class="btn btn-primary btn-sm w-100"><i class="bi bi-link me-1"></i>Vincular</button>
        </form>
        </fieldset>
      </div>
    </div>

    <!-- Asociar OC -->
    <div class="card shadow-sm border-0 mb-3<?= $bloqueado ? ' opacity-75' : '' ?>">
      <div class="card-header bg-danger text-white py-2"><h6 class="mb-0"><i class="bi bi-cart-check me-2"></i>Asociar Orden de Compra</h6></div>
      <div class="card-body p-3">
        <?php if ($bloqueado): ?>
        <div class="alert alert-warning py-2 px-2 mb-2 small"><i class="bi bi-lock me-1"></i>Disponible cuando la cotización esté <strong>Adjudicada</strong>.</div>
        <?php endif; ?>
        <fieldset <?= $bloqueado ? 'disabled' : '' ?>>

        <!-- Tabs: OC del sistema / OC externa -->
        <ul class="nav nav-tabs nav-tabs-sm mb-3" id="tabsOC">
          <li class="nav-item">
            <a class="nav-link active py-1 px-3" href="#" onclick="switchTabOC('sistema',this)">
              <i class="bi bi-database me-1"></i><small>OC del sistema</small>
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link py-1 px-3" href="#" onclick="switchTabOC('externa',this)">
              <i class="bi bi-upload me-1"></i><small>OC empresa externa</small>
            </a>
          </li>
        </ul>

        <!-- TAB 1: OC del sistema -->
        <div id="tabOCSistema">
          <form method="POST">
            <input type="hidden" name="action" value="asociar_oc">
            <input type="hidden" name="es_externa" value="0">
            <div class="mb-2">
              <label class="form-label small fw-semibold">Seleccionar OC registrada</label>
              <?php
                $ocsDisp = $pdo->query("SELECT id, numero, proveedor_nombre, obra, total, estado FROM ordenes_compra ORDER BY numero DESC")->fetchAll(PDO::FETCH_ASSOC);
              ?>
              <select name="oc_numero" class="form-select form-select-sm" required>
                <option value="">— Seleccionar OC —</option>
                <?php foreach ($ocsDisp as $oc): ?>
                <option value="<?= $oc['numero'] ?>">
                  OC-<?= htmlspecialchars($oc['numero']) ?>
                  — <?= htmlspecialchars($oc['proveedor_nombre']) ?>
                  <?= $oc['obra'] ? '· '.$oc['obra'] : '' ?>
                  ($<?= number_format((float)$oc['total'],0,',','.') ?>)
                  [<?= ucfirst($oc['estado']) ?>]
                </option>
                <?php endforeach; ?>
              </select>
              <?php if (empty($ocsDisp)): ?>
              <div class="form-text text-muted">Sin OCs registradas. Puedes ingresar el número manualmente:</div>
              <input type="number" name="oc_numero" class="form-control form-control-sm mt-1" placeholder="Ej: 4174">
              <?php endif; ?>
            </div>
            <div class="mb-2">
              <textarea name="nota_oc" class="form-control form-control-sm" rows="1" placeholder="Nota (opcional)"></textarea>
            </div>
            <button class="btn btn-danger btn-sm w-100"><i class="bi bi-link me-1"></i>Vincular OC</button>
          </form>
        </div>

        <!-- TAB 2: OC empresa externa -->
        <div id="tabOCExterna" style="display:none">
          <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="asociar_oc">
            <input type="hidden" name="es_externa" value="1">
            <div class="mb-2">
              <label class="form-label small fw-semibold">Empresa emisora *</label>
              <input type="text" name="oc_ext_empresa" class="form-control form-control-sm"
                     placeholder="Nombre de la empresa" required>
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">N° OC externa *</label>
              <input type="text" name="oc_ext_numero" class="form-control form-control-sm"
                     placeholder="Ej: OC-2024-0051" required>
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">Monto (CLP)</label>
              <div class="input-group input-group-sm">
                <span class="input-group-text">$</span>
                <input type="text" name="oc_ext_monto" class="form-control form-control-sm"
                       placeholder="0" inputmode="numeric">
              </div>
            </div>
            <div class="mb-2">
              <label class="form-label small fw-semibold">
                <i class="bi bi-paperclip me-1"></i>Adjuntar archivo OC
              </label>
              <input type="file" name="oc_ext_archivo" class="form-control form-control-sm"
                     accept=".pdf,.jpg,.jpeg,.png,.webp" onchange="prevOCExt(this)">
              <div class="form-text">PDF, JPG o PNG</div>
              <img id="prevOCExtImg" src="" style="max-height:80px;border-radius:6px;display:none;margin-top:4px;border:1px solid #dee2e6">
            </div>
            <div class="mb-2">
              <textarea name="nota_oc" class="form-control form-control-sm" rows="1" placeholder="Nota (opcional)"></textarea>
            </div>
            <button class="btn btn-warning btn-sm w-100 fw-bold">
              <i class="bi bi-upload me-1"></i>Adjuntar OC externa
            </button>
          </form>
        </div>

        </fieldset>
      </div>
    </div>

    <!-- OC Asociadas -->
    <?php if (count($ocAsociadas) > 0): ?>
    <div class="card shadow-sm border-0 mb-3">
      <div class="card-header bg-danger text-white py-2">
        <h6 class="mb-0"><i class="bi bi-cart4 me-2"></i>Órdenes de Compra Vinculadas (<?= count($ocAsociadas) ?>)</h6>
      </div>
      <div class="card-body p-2">
        <?php
          $badgesOC = [
            'pendiente' => ['warning', 'hourglass-split'],
            'aprobada' => ['success', 'check-circle'],
            'rechazada' => ['danger', 'x-circle'],
            'en_revision' => ['info', 'eye'],
            'corregir' => ['orange', 'pencil'],
          ];
        ?>
        <?php foreach ($ocAsociadas as $oca):
          $esExterna = !empty($oca['es_externa']);
          $ob = $badgesOC[$oca['oc_estado']] ?? ['secondary','circle'];
        ?>
        <div class="border rounded p-2 mb-2" style="font-size:12px;background:<?= $esExterna?'#fffbeb':'#fff' ?>">
          <div class="d-flex justify-content-between align-items-start">
            <div class="flex-grow-1">
              <?php if ($esExterna): ?>
              <!-- OC EXTERNA -->
              <div class="fw-bold text-warning">
                <i class="bi bi-upload me-1"></i>OC Externa N° <?= htmlspecialchars($oca['oc_ext_numero']) ?>
                <span class="badge bg-warning text-dark ms-1" style="font-size:10px">Empresa externa</span>
              </div>
              <small class="text-muted"><i class="bi bi-building me-1"></i><?= htmlspecialchars($oca['oc_ext_empresa']) ?></small>
              <?php if ($oca['oc_ext_monto'] > 0): ?>
              <br><small class="fw-bold text-success"><i class="bi bi-cash me-1"></i>$<?= number_format((float)$oca['oc_ext_monto'],0,',','.') ?></small>
              <?php endif; ?>
              <?php if ($oca['oc_ext_archivo']): ?>
              <br>
              <?php $extArch = strtolower(pathinfo($oca['oc_ext_archivo'],PATHINFO_EXTENSION)); ?>
              <?php if ($extArch === 'pdf'): ?>
              <a href="../uploads/<?= htmlspecialchars($oca['oc_ext_archivo']) ?>" target="_blank" class="badge bg-danger text-white text-decoration-none mt-1">
                <i class="bi bi-file-earmark-pdf me-1"></i>Ver PDF
              </a>
              <?php else: ?>
              <a href="../uploads/<?= htmlspecialchars($oca['oc_ext_archivo']) ?>" target="_blank">
                <img src="../uploads/<?= htmlspecialchars($oca['oc_ext_archivo']) ?>"
                     style="max-height:60px;max-width:100%;border-radius:5px;border:1px solid #dee2e6;margin-top:4px">
              </a>
              <?php endif; ?>
              <?php endif; ?>
              <?php else: ?>
              <!-- OC DEL SISTEMA -->
              <a href="ver.php?id=<?= $oca['oc_id'] ?>" class="fw-bold text-decoration-none text-danger">
                <i class="bi bi-file-earmark-text me-1"></i>OC N° <?= $oca['numero'] ?>
              </a>
              <span class="badge bg-<?= $ob[0] ?> ms-1" style="font-size:10px;"><?= ucfirst(str_replace('_', ' ', $oca['oc_estado'])) ?></span>
              <br><small class="text-muted"><i class="bi bi-building me-1"></i><?= htmlspecialchars($oca['proveedor_nombre']) ?></small>
              <?php if ($oca['obra']): ?>
              <br><small class="text-muted"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($oca['obra']) ?></small>
              <?php endif; ?>
              <br><small class="fw-bold text-success"><i class="bi bi-cash me-1"></i>$<?= formatCLP($oca['total']) ?></small>
              <small class="text-muted ms-2"><i class="bi bi-calendar me-1"></i><?= date('d/m/Y', strtotime($oca['oc_fecha'])) ?></small>
              <?php endif; ?>
              <?php if ($oca['nota']): ?><br><small class="fst-italic text-muted"><i class="bi bi-chat-left-text me-1"></i><?= htmlspecialchars($oca['nota']) ?></small><?php endif; ?>
            </div>
            <form method="POST" class="d-inline flex-shrink-0 ms-1">
              <input type="hidden" name="action" value="eliminar_oc_asociacion">
              <input type="hidden" name="asoc_oc_id" value="<?= $oca['id'] ?>">
              <button class="btn btn-sm btn-outline-danger py-0 px-1" onclick="return confirm('¿Desvincular esta OC?')"><i class="bi bi-x"></i></button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
        <div class="border-top pt-2 mt-1">
          <div class="d-flex justify-content-between align-items-center" style="font-size:13px;">
            <span class="fw-bold"><i class="bi bi-calculator me-1"></i>Total OC vinculadas:</span>
            <span class="fw-bold text-success fs-6">$<?= formatCLP($totalOCAsociadas) ?></span>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Cotizaciones Asociadas -->
    <?php if (count($todasAsociaciones) > 0): ?>
    <div class="card shadow-sm border-0">
      <div class="card-header bg-dark text-white py-2"><h6 class="mb-0"><i class="bi bi-diagram-3 me-2"></i>Cotizaciones Vinculadas (<?= count($todasAsociaciones) ?>)</h6></div>
      <div class="card-body p-2">
        <?php foreach ($asociaciones as $a):
          $ab = $badgesEstado[$a['cot_estado']] ?? ['secondary','circle'];
        ?>
        <div class="border rounded p-2 mb-2" style="font-size:12px;">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <a href="seguimiento_cot.php?id=<?= $a['cot_id_destino'] ?>" class="fw-bold text-decoration-none">N° <?= $a['numero'] ?></a>
              <span class="badge bg-<?= $ab[0] ?> ms-1" style="font-size:10px;"><?= ucfirst($a['cot_estado']) ?></span>
              <br><small class="text-muted"><?= htmlspecialchars($a['cliente_nombre']) ?></small>
              <br><small class="text-muted">$<?= formatCLP($a['total']) ?> — <?= ucfirst($a['tipo']) ?></small>
              <?php if ($a['nota']): ?><br><small class="fst-italic"><?= htmlspecialchars($a['nota']) ?></small><?php endif; ?>
            </div>
            <div class="d-flex flex-column gap-1">
              <a href="ver_cotizacion.php?id=<?= $a['cot_id_destino'] ?>&from_seg=<?= $id ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0 px-1" title="Ver cotización"><i class="bi bi-eye"></i></a>
              <form method="POST" class="d-inline">
                <input type="hidden" name="action" value="eliminar_asociacion">
                <input type="hidden" name="asoc_id" value="<?= $a['id'] ?>">
                <button class="btn btn-sm btn-outline-danger py-0 px-1" onclick="return confirm('¿Desvincular?')" title="Desvincular"><i class="bi bi-x"></i></button>
              </form>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php foreach ($asociacionesInv as $a):
          $ab = $badgesEstado[$a['cot_estado']] ?? ['secondary','circle'];
        ?>
        <div class="border rounded p-2 mb-2 bg-light" style="font-size:12px;">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <a href="seguimiento_cot.php?id=<?= $a['cot_id_origen'] ?>" class="fw-bold text-decoration-none">N° <?= $a['numero'] ?></a>
              <span class="badge bg-<?= $ab[0] ?> ms-1" style="font-size:10px;"><?= ucfirst($a['cot_estado']) ?></span>
              <br><small class="text-muted"><?= htmlspecialchars($a['cliente_nombre']) ?> — <?= ucfirst($a['tipo']) ?></small>
            </div>
            <a href="ver_cotizacion.php?id=<?= $a['cot_id_origen'] ?>&from_seg=<?= $id ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0 px-1" title="Ver cotización"><i class="bi bi-eye"></i></a>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Documentos de la Obra -->
    <?php if (count($todosArchivos) > 0): ?>
    <div class="card shadow-sm border-0 mb-3">
      <div class="card-header bg-dark text-white py-2">
        <h6 class="mb-0"><i class="bi bi-folder2-open me-2"></i>Documentos de la Obra (<?= count($todosArchivos) ?>)</h6>
      </div>
      <div class="card-body p-2" style="max-height:300px;overflow-y:auto;">
        <?php foreach ($todosArchivos as $archDoc):
          $iconDoc = getIconoArchivo($archDoc['nombre_original'], $iconosArchivo);
        ?>
        <div class="d-flex align-items-center justify-content-between border rounded px-2 py-1 mb-1" style="font-size:12px;">
          <div class="d-flex align-items-center flex-grow-1 overflow-hidden">
            <i class="bi <?= $iconDoc[0] ?> <?= $iconDoc[1] ?> me-1 flex-shrink-0" style="font-size:16px;"></i>
            <div class="overflow-hidden">
              <a href="../descargar_archivo.php?id=<?= $archDoc['id'] ?>&accion=ver" target="_blank" class="text-decoration-none text-truncate d-block" title="<?= htmlspecialchars($archDoc['nombre_original']) ?>">
                <?= htmlspecialchars(mb_strimwidth($archDoc['nombre_original'], 0, 25, '...')) ?>
              </a>
              <small class="text-muted"><?= formatBytes($archDoc['tamano']) ?> — <?= date('d/m/Y', strtotime($archDoc['fecha'])) ?></small>
            </div>
          </div>
          <div class="d-flex gap-1 flex-shrink-0 ms-1">
            <a href="../descargar_archivo.php?id=<?= $archDoc['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-1" title="Descargar"><i class="bi bi-download"></i></a>
            <form method="POST" class="d-inline">
              <input type="hidden" name="action" value="eliminar_archivo">
              <input type="hidden" name="arch_id" value="<?= $archDoc['id'] ?>">
              <button class="btn btn-sm btn-outline-danger py-0 px-1" onclick="return confirm('¿Eliminar este archivo?')" title="Eliminar"><i class="bi bi-trash"></i></button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- COLUMNA DERECHA: Procesos -->
  <div class="col-lg-8">
    <!-- Agregar Proceso -->
    <div class="card shadow-sm border-0 mb-3<?= $bloqueado ? ' opacity-75' : '' ?>">
      <div class="card-header bg-danger text-white py-2"><h6 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Agregar Proceso / Seguimiento</h6></div>
      <div class="card-body p-3">
        <?php if ($bloqueado): ?>
        <div class="alert alert-warning py-2 px-2 mb-2 small"><i class="bi bi-lock me-1"></i>Disponible cuando la cotización esté <strong>Adjudicada</strong>.</div>
        <?php endif; ?>
        <fieldset <?= $bloqueado ? 'disabled' : '' ?>>
        <form method="POST" enctype="multipart/form-data" class="row g-2">
          <input type="hidden" name="action" value="agregar_proceso">
          <div class="col-md-2">
            <select name="tipo" class="form-select form-select-sm">
              <option value="seguimiento">Seguimiento</option>
              <option value="nota">Nota</option>
              <option value="reunion">Reunión</option>
              <option value="llamada">Llamada</option>
              <option value="email">Email</option>
              <option value="documento">Documento</option>
            </select>
          </div>
          <div class="col-md-3">
            <input type="text" name="titulo" class="form-control form-control-sm" placeholder="Título del proceso *" required>
          </div>
          <div class="col-md-3">
            <input type="text" name="descripcion" class="form-control form-control-sm" placeholder="Descripción (opcional)">
          </div>
          <div class="col-md-2">
            <select name="estado_proceso" class="form-select form-select-sm">
              <?php foreach ($estadosProceso as $epk => $epv): ?>
              <option value="<?= $epk ?>"><?= $epv[2] ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-1">
            <button class="btn btn-danger btn-sm w-100"><i class="bi bi-plus-lg"></i></button>
          </div>
          <div class="col-12">
            <div class="input-group input-group-sm">
              <span class="input-group-text bg-light"><i class="bi bi-paperclip"></i></span>
              <input type="file" name="archivos[]" class="form-control form-control-sm" multiple>
            </div>
            <small class="text-muted">PDF, Word, Excel, imágenes, planos, ZIP, etc. (máx. 20MB por archivo). Puede seleccionar varios.</small>
          </div>
        </form>
        </fieldset>
      </div>
    </div>

    <!-- Adjuntar Archivos Sueltos -->
    <div class="card shadow-sm border-0 mb-3<?= $bloqueado ? ' opacity-75' : '' ?>">
      <div class="card-header bg-dark text-white py-2"><h6 class="mb-0"><i class="bi bi-cloud-arrow-up me-2"></i>Adjuntar Archivos a la Obra</h6></div>
      <div class="card-body p-3">
        <?php if ($bloqueado): ?>
        <div class="alert alert-warning py-2 px-2 mb-2 small"><i class="bi bi-lock me-1"></i>Disponible cuando la cotización esté <strong>Adjudicada</strong>.</div>
        <?php endif; ?>
        <fieldset <?= $bloqueado ? 'disabled' : '' ?>>
        <form method="POST" enctype="multipart/form-data">
          <input type="hidden" name="action" value="adjuntar_archivo">
          <div class="mb-2">
            <input type="file" name="archivos_adj[]" class="form-control form-control-sm" multiple required>
            <small class="text-muted">Archivos generales asociados a esta cotización/obra.</small>
          </div>
          <button class="btn btn-dark btn-sm w-100"><i class="bi bi-upload me-1"></i>Subir Archivos</button>
        </form>
        </fieldset>
      </div>
    </div>

    <!-- Timeline de Procesos -->
    <div class="card shadow-sm border-0">
      <div class="card-header bg-dark text-white py-2">
        <h6 class="mb-0"><i class="bi bi-clock-history me-2"></i>Historial de Procesos (<?= count($procesos) ?>)</h6>
      </div>
      <div class="card-body p-3">
        <?php if (empty($procesos)): ?>
        <div class="text-center text-muted py-4">
          <i class="bi bi-inbox fs-2"></i>
          <p class="mt-2 mb-0">No hay procesos registrados</p>
        </div>
        <?php else: ?>
        <div class="timeline">
          <?php foreach ($procesos as $p):
            $tp = $tiposProceso[$p['tipo']] ?? ['secondary', 'circle'];
          ?>
          <div class="timeline-item mb-3">
            <div class="d-flex align-items-start">
              <div class="timeline-icon bg-<?= $tp[0] ?> text-white rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width:36px;height:36px;font-size:14px;">
                <i class="bi bi-<?= $tp[1] ?>"></i>
              </div>
              <div class="ms-3 flex-grow-1">
                <div class="d-flex justify-content-between align-items-start">
                  <div>
                    <strong style="font-size:13px;"><?= htmlspecialchars($p['titulo']) ?></strong>
                    <span class="badge bg-<?= $tp[0] ?> ms-1" style="font-size:10px;"><?= ucfirst(str_replace('_', ' ', $p['tipo'])) ?></span>
                    <?php
                      $epActual = $p['estado_proceso'] ?? 'pendiente';
                      $epInfo = $estadosProceso[$epActual] ?? ['secondary', 'circle', ucfirst($epActual)];
                    ?>
                    <span class="badge bg-<?= $epInfo[0] ?> ms-1" style="font-size:10px;"><i class="bi bi-<?= $epInfo[1] ?> me-1"></i><?= $epInfo[2] ?></span>
                    <?php if ($p['estado_anterior'] && $p['estado_nuevo']): ?>
                    <span class="ms-1" style="font-size:11px;"><span class="badge bg-secondary"><?= ucfirst($p['estado_anterior']) ?></span> → <span class="badge bg-warning text-dark"><?= ucfirst($p['estado_nuevo']) ?></span></span>
                    <?php endif; ?>
                  </div>
                  <div class="d-flex gap-1 align-items-center">
                    <form method="POST" class="d-inline">
                      <input type="hidden" name="action" value="cambiar_estado_proceso">
                      <input type="hidden" name="proc_id" value="<?= $p['id'] ?>">
                      <select name="estado_proceso" class="form-select form-select-sm py-0" style="font-size:11px;width:auto;min-width:120px;" onchange="this.form.submit()">
                        <?php foreach ($estadosProceso as $epk => $epv): ?>
                        <option value="<?= $epk ?>" <?= $epActual === $epk ? 'selected' : '' ?>><?= $epv[2] ?></option>
                        <?php endforeach; ?>
                      </select>
                    </form>
                    <button class="btn btn-sm btn-outline-warning py-0 px-1" data-bs-toggle="modal" data-bs-target="#editProc<?= $p['id'] ?>" title="Editar"><i class="bi bi-pencil"></i></button>
                    <form method="POST" class="d-inline">
                      <input type="hidden" name="action" value="eliminar_proceso">
                      <input type="hidden" name="proc_id" value="<?= $p['id'] ?>">
                      <button class="btn btn-sm btn-outline-danger py-0 px-1" onclick="return confirm('¿Eliminar este proceso?')"><i class="bi bi-trash"></i></button>
                    </form>
                  </div>
                </div>
                <?php if ($p['descripcion']): ?>
                <p class="mb-1 text-muted" style="font-size:12px;"><?= nl2br(htmlspecialchars($p['descripcion'])) ?></p>
                <?php endif; ?>
                <?php if (!empty($archivosPorProceso[$p['id']])): ?>
                <div class="mt-1 mb-1">
                  <?php foreach ($archivosPorProceso[$p['id']] as $archP):
                    $iconArch = getIconoArchivo($archP['nombre_original'], $iconosArchivo);
                    $esImagen = in_array($archP['tipo_mime'], ['image/jpeg','image/png','image/gif','image/webp']);
                  ?>
                  <div class="d-inline-flex align-items-center border rounded px-2 py-1 me-1 mb-1 bg-light" style="font-size:11px;">
                    <i class="bi <?= $iconArch[0] ?> <?= $iconArch[1] ?> me-1"></i>
                    <a href="../descargar_archivo.php?id=<?= $archP['id'] ?>&accion=ver" target="_blank" class="text-decoration-none me-1" title="<?= htmlspecialchars($archP['nombre_original']) ?>">
                      <?= htmlspecialchars(mb_strimwidth($archP['nombre_original'], 0, 30, '...')) ?>
                    </a>
                    <small class="text-muted me-1">(<?= formatBytes($archP['tamano']) ?>)</small>
                    <a href="../descargar_archivo.php?id=<?= $archP['id'] ?>" class="text-primary me-1" title="Descargar"><i class="bi bi-download"></i></a>
                    <form method="POST" class="d-inline">
                      <input type="hidden" name="action" value="eliminar_archivo">
                      <input type="hidden" name="arch_id" value="<?= $archP['id'] ?>">
                      <button class="btn btn-link btn-sm text-danger p-0" style="font-size:11px;" onclick="return confirm('¿Eliminar este archivo?')" title="Eliminar"><i class="bi bi-x-circle"></i></button>
                    </form>
                  </div>
                  <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <small class="text-muted"><i class="bi bi-person me-1"></i><?= htmlspecialchars($p['usuario']) ?> — <i class="bi bi-clock me-1"></i><?= date('d/m/Y H:i', strtotime($p['fecha'])) ?></small>
              </div>
            </div>
          </div>

          <!-- Modal Editar Proceso -->
          <div class="modal fade" id="editProc<?= $p['id'] ?>" tabindex="-1">
            <div class="modal-dialog">
              <div class="modal-content">
                <form method="POST">
                  <input type="hidden" name="action" value="editar_proceso">
                  <input type="hidden" name="proc_id" value="<?= $p['id'] ?>">
                  <div class="modal-header"><h6 class="modal-title">Editar Proceso</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                  <div class="modal-body">
                    <div class="mb-2">
                      <label class="form-label small fw-semibold">Tipo</label>
                      <select name="tipo" class="form-select form-select-sm">
                        <option value="seguimiento" <?= $p['tipo']==='seguimiento'?'selected':'' ?>>Seguimiento</option>
                        <option value="nota" <?= $p['tipo']==='nota'?'selected':'' ?>>Nota</option>
                        <option value="reunion" <?= $p['tipo']==='reunion'?'selected':'' ?>>Reunión</option>
                        <option value="llamada" <?= $p['tipo']==='llamada'?'selected':'' ?>>Llamada</option>
                        <option value="email" <?= $p['tipo']==='email'?'selected':'' ?>>Email</option>
                        <option value="documento" <?= $p['tipo']==='documento'?'selected':'' ?>>Documento</option>
                      </select>
                    </div>
                    <div class="mb-2">
                      <label class="form-label small fw-semibold">Estado del Proceso</label>
                      <select name="estado_proceso" class="form-select form-select-sm">
                        <?php foreach ($estadosProceso as $epk => $epv): ?>
                        <option value="<?= $epk ?>" <?= ($p['estado_proceso'] ?? 'pendiente') === $epk ? 'selected' : '' ?>><?= $epv[2] ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="mb-2">
                      <label class="form-label small fw-semibold">Título *</label>
                      <input type="text" name="titulo" class="form-control form-control-sm" value="<?= htmlspecialchars($p['titulo']) ?>" required>
                    </div>
                    <div class="mb-2">
                      <label class="form-label small fw-semibold">Descripción</label>
                      <textarea name="descripcion" class="form-control form-control-sm" rows="3"><?= htmlspecialchars($p['descripcion'] ?? '') ?></textarea>
                    </div>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-warning btn-sm"><i class="bi bi-save me-1"></i>Guardar</button>
                  </div>
                </form>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
function switchTabOC(tab, el) {
    event.preventDefault();
    document.getElementById('tabOCSistema').style.display = tab==='sistema' ? '' : 'none';
    document.getElementById('tabOCExterna').style.display  = tab==='externa' ? '' : 'none';
    document.querySelectorAll('#tabsOC .nav-link').forEach(function(a){ a.classList.remove('active'); });
    el.classList.add('active');
}
function prevOCExt(input) {
    var f=input.files[0];
    var img=document.getElementById('prevOCExtImg');
    if(!img) return;
    if(f && f.type.startsWith('image/')) {
        var r=new FileReader();
        r.onload=function(e){ img.src=e.target.result; img.style.display='block'; };
        r.readAsDataURL(f);
    } else { img.style.display='none'; }
}
</script>
<?php $mostrarModalAdj = !empty($_GET['adjudicada']) && $cot['estado'] === 'adjudicada'; ?>
<?php if ($mostrarModalAdj): ?>
<div class="modal fade" id="modalAdjudicada" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title"><i class="bi bi-trophy-fill me-2"></i>¡Enhorabuena!</h5>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="asignar_obra_cot">
        <div class="modal-body">
          <p class="mb-2 fw-semibold">Otro proyecto cerrado <i class="bi bi-emoji-smile"></i></p>
          <p class="text-muted small mb-3">Cotización N° <?= $cot['numero'] ?> — <?= htmlspecialchars($cot['cliente_nombre']) ?></p>
          <label class="form-label fw-semibold">¿A qué obra asignamos esta cotización? *</label>
          <select name="obra_id" class="form-select" required>
            <option value="">— Seleccionar obra registrada —</option>
            <?php foreach ($obrasRegistradas as $o): ?>
            <option value="<?= (int)$o['id'] ?>" <?= ((int)($cot['obra_id'] ?? 0) === (int)$o['id']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($o['codigo']) ?> — <?= htmlspecialchars($o['nombre']) ?><?= !empty($o['mandante']) ? ' · '.htmlspecialchars($o['mandante']) : '' ?>
            </option>
            <?php endforeach; ?>
          </select>
          <?php if (empty($obrasRegistradas)): ?>
          <div class="alert alert-warning mt-2 mb-0 py-2 small"><i class="bi bi-exclamation-triangle me-1"></i>No hay obras activas registradas.</div>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <a href="seguimiento_cot.php?id=<?= $id ?>" class="btn btn-outline-secondary">Más tarde</a>
          <button type="submit" class="btn btn-success" <?= empty($obrasRegistradas) ? 'disabled' : '' ?>><i class="bi bi-check-lg me-1"></i>Asignar obra</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
(function(){
  function show(){
    var el = document.getElementById('modalAdjudicada');
    if (!el) return;
    if (typeof bootstrap === 'undefined') { setTimeout(show, 60); return; }
    bootstrap.Modal.getOrCreateInstance(el, {backdrop:'static'}).show();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', show);
  else show();
})();
</script>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
