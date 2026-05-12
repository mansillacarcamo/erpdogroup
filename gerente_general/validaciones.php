<?php
// ============================================================
//  GERENTE GENERAL — Validaciones (cotizaciones y OC)
//  Permite aprobar / rechazar / observar desde esta misma app.
// ============================================================
require_once __DIR__ . '/../config.php';

if (empty($_SESSION['gerente_general'])) {
    header('Location: ../login.php'); exit;
}
$gg = $_SESSION['gerente_general'];
$ggId = (int)$gg['id'];

function h_($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function fmtCLP_($n) { return '$' . number_format((float)$n, 0, ',', '.'); }

$msg = null;

// ---- POST handlers: aprobar/rechazar/corregir ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'validar_cot') {
        $aprobId    = (int)($_POST['aprob_id'] ?? 0);
        $decision   = $_POST['decision'] ?? '';
        $comentario = trim($_POST['comentario'] ?? '');

        if ($aprobId && in_array($decision, ['aprobada','rechazada','corregir'], true)) {
            $stCheck = $pdo->prepare("SELECT * FROM cot_aprobaciones WHERE id = ? AND usuario_id = ? AND estado = 'pendiente'");
            $stCheck->execute([$aprobId, $ggId]);
            $aprob = $stCheck->fetch(PDO::FETCH_ASSOC);

            if ($aprob) {
                // Verificar que esté en su turno (anteriores ya respondieron)
                $stOrden = $pdo->prepare("SELECT COALESCE(oa.orden, 99) FROM oc_aprobadores oa WHERE oa.usuario_id = ?");
                $stOrden->execute([$ggId]);
                $miOrden = (int)($stOrden->fetchColumn() ?: 99);

                $stPrev = $pdo->prepare("
                    SELECT COUNT(*) FROM cot_aprobaciones a2
                    LEFT JOIN oc_aprobadores oa2 ON oa2.usuario_id = a2.usuario_id
                    WHERE a2.cot_id = ? AND a2.estado = 'pendiente'
                      AND COALESCE(oa2.orden, 99) < ?
                ");
                $stPrev->execute([$aprob['cot_id'], $miOrden]);
                $previosPendientes = (int)$stPrev->fetchColumn();

                if ($previosPendientes > 0) {
                    $msg = ['warning', 'Esta cotización aún espera la respuesta de un validador previo.'];
                } else {
                    $pdo->prepare("UPDATE cot_aprobaciones SET estado = ?, comentario = ?, fecha_respuesta = datetime('now') WHERE id = ?")
                        ->execute([$decision, $comentario ?: null, $aprobId]);

                    $stAprob = $pdo->prepare("SELECT COUNT(*) FROM cot_aprobaciones WHERE cot_id = ? AND estado = 'aprobada'");
                    $stAprob->execute([$aprob['cot_id']]);
                    $aprobadas = (int)$stAprob->fetchColumn();

                    $stTotal = $pdo->prepare("SELECT COUNT(*) FROM cot_aprobaciones WHERE cot_id = ?");
                    $stTotal->execute([$aprob['cot_id']]);
                    $total = (int)$stTotal->fetchColumn();

                    if ($decision === 'aprobada' && $aprobadas === $total) {
                        $pdo->prepare("UPDATE cotizaciones SET estado = 'aprobada' WHERE id = ?")->execute([$aprob['cot_id']]);
                        $msg = ['success', 'Cotización aprobada por todos los validadores.'];
                    } elseif ($decision === 'aprobada') {
                        $msg = ['info', 'Aprobación registrada. Falta otro validador.'];
                    } elseif ($decision === 'rechazada') {
                        $pdo->prepare("UPDATE cotizaciones SET estado = 'rechazada' WHERE id = ?")->execute([$aprob['cot_id']]);
                        $msg = ['danger', 'Cotización rechazada.'];
                    } elseif ($decision === 'corregir') {
                        $pdo->prepare("UPDATE cotizaciones SET estado = 'corregir' WHERE id = ?")->execute([$aprob['cot_id']]);
                        $msg = ['warning', 'Se solicitaron correcciones a la cotización.'];
                    }
                }
            } else {
                $msg = ['danger', 'No se encontró esta validación pendiente.'];
            }
        }
    }

    if ($action === 'validar_oc') {
        $aprobId    = (int)($_POST['aprob_id'] ?? 0);
        $decision   = $_POST['decision'] ?? '';
        $comentario = trim($_POST['comentario'] ?? '');

        if ($aprobId && in_array($decision, ['aprobada','rechazada','corregir'], true)) {
            $stCheck = $pdo->prepare("SELECT * FROM oc_aprobaciones WHERE id = ? AND usuario_id = ? AND estado = 'pendiente'");
            $stCheck->execute([$aprobId, $ggId]);
            $aprob = $stCheck->fetch(PDO::FETCH_ASSOC);

            if ($aprob) {
                $stOrden = $pdo->prepare("SELECT COALESCE(oa.orden, 99) FROM oc_aprobadores oa WHERE oa.usuario_id = ?");
                $stOrden->execute([$ggId]);
                $miOrden = (int)($stOrden->fetchColumn() ?: 99);

                $stPrev = $pdo->prepare("
                    SELECT COUNT(*) FROM oc_aprobaciones a2
                    LEFT JOIN oc_aprobadores oa2 ON oa2.usuario_id = a2.usuario_id
                    WHERE a2.oc_id = ? AND a2.estado = 'pendiente'
                      AND COALESCE(oa2.orden, 99) < ?
                ");
                $stPrev->execute([$aprob['oc_id'], $miOrden]);
                $previosPendientes = (int)$stPrev->fetchColumn();

                if ($previosPendientes > 0) {
                    $msg = ['warning', 'Esta OC aún espera la respuesta de un validador previo.'];
                } else {
                    $pdo->prepare("UPDATE oc_aprobaciones SET estado = ?, comentario = ?, fecha_respuesta = datetime('now') WHERE id = ?")
                        ->execute([$decision, $comentario ?: null, $aprobId]);

                    $stAprob = $pdo->prepare("SELECT COUNT(*) FROM oc_aprobaciones WHERE oc_id = ? AND estado = 'aprobada'");
                    $stAprob->execute([$aprob['oc_id']]);
                    $aprobadas = (int)$stAprob->fetchColumn();

                    $stTotal = $pdo->prepare("SELECT COUNT(*) FROM oc_aprobaciones WHERE oc_id = ?");
                    $stTotal->execute([$aprob['oc_id']]);
                    $total = (int)$stTotal->fetchColumn();

                    if ($decision === 'aprobada' && $aprobadas === $total) {
                        $pdo->prepare("UPDATE ordenes_compra SET estado = 'aprobada' WHERE id = ?")->execute([$aprob['oc_id']]);
                        $msg = ['success', 'OC aprobada por todos los validadores.'];
                    } elseif ($decision === 'aprobada') {
                        $msg = ['info', 'Aprobación registrada. Falta otro validador.'];
                    } elseif ($decision === 'rechazada') {
                        $pdo->prepare("UPDATE ordenes_compra SET estado = 'rechazada' WHERE id = ?")->execute([$aprob['oc_id']]);
                        $msg = ['danger', 'OC rechazada.'];
                    } elseif ($decision === 'corregir') {
                        $pdo->prepare("UPDATE ordenes_compra SET estado = 'corregir' WHERE id = ?")->execute([$aprob['oc_id']]);
                        $msg = ['warning', 'Se solicitaron correcciones a la OC.'];
                    }
                }
            } else {
                $msg = ['danger', 'No se encontró esta validación pendiente.'];
            }
        }
    }
}

// ---- Mi orden como aprobador ----
$stOrden = $pdo->prepare("SELECT COALESCE(oa.orden, 99) FROM oc_aprobadores oa WHERE oa.usuario_id = ?");
$stOrden->execute([$ggId]);
$miOrden = (int)($stOrden->fetchColumn() ?: 99);

// ---- Cargar pendientes (con filtro secuencial) ----
$cotPendientes = [];
$ocPendientes  = [];
try {
    $stCotPend = $pdo->prepare("
        SELECT a.*, c.numero, c.fecha, c.cliente_nombre, c.cliente_obra, c.subtotal, c.iva, c.total, c.creada_por
        FROM cot_aprobaciones a
        JOIN cotizaciones c ON c.id = a.cot_id
        LEFT JOIN oc_aprobadores oa ON oa.usuario_id = a.usuario_id
        WHERE a.usuario_id = ? AND a.estado = 'pendiente'
          AND NOT EXISTS (
            SELECT 1 FROM cot_aprobaciones a2
            LEFT JOIN oc_aprobadores oa2 ON oa2.usuario_id = a2.usuario_id
            WHERE a2.cot_id = a.cot_id
              AND a2.estado = 'pendiente'
              AND COALESCE(oa2.orden, 99) < COALESCE(oa.orden, 99)
          )
        ORDER BY a.creado_en DESC
    ");
    $stCotPend->execute([$ggId]);
    $cotPendientes = $stCotPend->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

try {
    $stOcPend = $pdo->prepare("
        SELECT a.*, o.numero, o.fecha, o.proveedor_nombre, o.obra, o.neto, o.iva, o.total, o.moneda, o.preparada_por
        FROM oc_aprobaciones a
        JOIN ordenes_compra o ON o.id = a.oc_id
        LEFT JOIN oc_aprobadores oa ON oa.usuario_id = a.usuario_id
        WHERE a.usuario_id = ? AND a.estado = 'pendiente'
          AND NOT EXISTS (
            SELECT 1 FROM oc_aprobaciones a2
            LEFT JOIN oc_aprobadores oa2 ON oa2.usuario_id = a2.usuario_id
            WHERE a2.oc_id = a.oc_id
              AND a2.estado = 'pendiente'
              AND COALESCE(oa2.orden, 99) < COALESCE(oa.orden, 99)
          )
        ORDER BY a.creado_en DESC
    ");
    $stOcPend->execute([$ggId]);
    $ocPendientes = $stOcPend->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ---- Para cada pendiente, cargar datos completos + items + archivos del documento ----
$cotDetalles = [];
$cotItems    = [];
$cotArchivos = [];
foreach ($cotPendientes as $cp) {
    $cid = (int)$cp['cot_id'];
    if (!isset($cotDetalles[$cid])) {
        $st = $pdo->prepare("SELECT * FROM cotizaciones WHERE id = ?");
        $st->execute([$cid]);
        $cotDetalles[$cid] = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        try {
            $stIt = $pdo->prepare("SELECT * FROM cot_items WHERE cot_id = ? ORDER BY id");
            $stIt->execute([$cid]);
            $cotItems[$cid] = $stIt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $cotItems[$cid] = []; }
        try {
            $stAr = $pdo->prepare("SELECT * FROM cot_archivos WHERE cot_id = ? ORDER BY fecha DESC");
            $stAr->execute([$cid]);
            $cotArchivos[$cid] = $stAr->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $cotArchivos[$cid] = []; }
    }
}
$ocDetalles = [];
$ocItems    = [];
foreach ($ocPendientes as $op) {
    $oid = (int)$op['oc_id'];
    if (!isset($ocDetalles[$oid])) {
        $st = $pdo->prepare("SELECT * FROM ordenes_compra WHERE id = ?");
        $st->execute([$oid]);
        $ocDetalles[$oid] = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        try {
            $stIt = $pdo->prepare("SELECT * FROM oc_items WHERE oc_id = ? ORDER BY id");
            $stIt->execute([$oid]);
            $ocItems[$oid] = $stIt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $ocItems[$oid] = []; }
    }
}

// ---- Para cada pendiente, traer detalle de las respuestas previas ----
$cotPrev = [];
foreach ($cotPendientes as $cp) {
    $st = $pdo->prepare("
        SELECT a.nombre, a.estado, a.comentario, a.fecha_respuesta, COALESCE(oa.orden, 99) AS orden, u.cargo
        FROM cot_aprobaciones a
        LEFT JOIN oc_aprobadores oa ON oa.usuario_id = a.usuario_id
        LEFT JOIN usuarios u ON u.id = a.usuario_id
        WHERE a.cot_id = ? AND a.usuario_id != ?
        ORDER BY orden, a.id
    ");
    $st->execute([$cp['cot_id'], $ggId]);
    $cotPrev[$cp['id']] = $st->fetchAll(PDO::FETCH_ASSOC);
}
$ocPrev = [];
foreach ($ocPendientes as $op) {
    $st = $pdo->prepare("
        SELECT a.nombre, a.estado, a.comentario, a.fecha_respuesta, COALESCE(oa.orden, 99) AS orden, u.cargo
        FROM oc_aprobaciones a
        LEFT JOIN oc_aprobadores oa ON oa.usuario_id = a.usuario_id
        LEFT JOIN usuarios u ON u.id = a.usuario_id
        WHERE a.oc_id = ? AND a.usuario_id != ?
        ORDER BY orden, a.id
    ");
    $st->execute([$op['oc_id'], $ggId]);
    $ocPrev[$op['id']] = $st->fetchAll(PDO::FETCH_ASSOC);
}

// ---- Historial reciente (lo que ya respondí) ----
$cotHist = [];
$ocHist  = [];
try {
    $st = $pdo->prepare("
        SELECT a.*, c.numero, c.cliente_nombre, c.cliente_obra, c.total, c.estado AS cot_estado, c.creada_por
        FROM cot_aprobaciones a
        JOIN cotizaciones c ON c.id = a.cot_id
        WHERE a.usuario_id = ? AND a.estado != 'pendiente'
        ORDER BY a.fecha_respuesta DESC
        LIMIT 20
    ");
    $st->execute([$ggId]);
    $cotHist = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
try {
    $st = $pdo->prepare("
        SELECT a.*, o.numero, o.proveedor_nombre, o.obra, o.total, o.moneda, o.estado AS oc_estado, o.preparada_por
        FROM oc_aprobaciones a
        JOIN ordenes_compra o ON o.id = a.oc_id
        WHERE a.usuario_id = ? AND a.estado != 'pendiente'
        ORDER BY a.fecha_respuesta DESC
        LIMIT 20
    ");
    $st->execute([$ggId]);
    $ocHist = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ---- Cotizaciones en proceso comercial (adjudicada / negociación) — solo lectura ----
$cotEnProceso = [];
$cotEnProcesoItems = [];
$cotEnProcesoArchivos = [];
try {
    $stEnProc = $pdo->query("
        SELECT * FROM cotizaciones
        WHERE estado IN ('adjudicada','negociacion','propuesta')
        ORDER BY (estado='adjudicada') DESC, fecha DESC
        LIMIT 50
    ");
    $cotEnProceso = $stEnProc->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cotEnProceso as $cp) {
        $stIt = $pdo->prepare("SELECT * FROM cot_items WHERE cot_id = ? ORDER BY id");
        $stIt->execute([(int)$cp['id']]);
        $cotEnProcesoItems[(int)$cp['id']] = $stIt->fetchAll(PDO::FETCH_ASSOC);
        try {
            $stAr = $pdo->prepare("SELECT * FROM cot_archivos WHERE cot_id = ? ORDER BY fecha DESC");
            $stAr->execute([(int)$cp['id']]);
            $cotEnProcesoArchivos[(int)$cp['id']] = $stAr->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $cotEnProcesoArchivos[(int)$cp['id']] = []; }
    }
} catch (Exception $e) {}

function formatBytesGG($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}
function iconArchivoGG($nombre) {
    $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
    $map = [
        'pdf'=>['bi-file-earmark-pdf-fill','text-danger'],
        'doc'=>['bi-file-earmark-word-fill','text-primary'],'docx'=>['bi-file-earmark-word-fill','text-primary'],
        'xls'=>['bi-file-earmark-excel-fill','text-success'],'xlsx'=>['bi-file-earmark-excel-fill','text-success'],
        'jpg'=>['bi-file-earmark-image-fill','text-info'],'jpeg'=>['bi-file-earmark-image-fill','text-info'],
        'png'=>['bi-file-earmark-image-fill','text-info'],'gif'=>['bi-file-earmark-image-fill','text-info'],
        'webp'=>['bi-file-earmark-image-fill','text-info'],
        'zip'=>['bi-file-earmark-zip-fill','text-secondary'],'rar'=>['bi-file-earmark-zip-fill','text-secondary'],
        'dwg'=>['bi-rulers','text-dark'],'dxf'=>['bi-rulers','text-dark'],
    ];
    return $map[$ext] ?? ['bi-file-earmark-fill','text-secondary'];
}
function renderArchivosGG($archivos) {
    if (empty($archivos)) {
        echo '<div class="text-muted small fst-italic"><i class="bi bi-info-circle me-1"></i>Sin archivos adjuntos.</div>';
        return;
    }
    foreach ($archivos as $arch) {
        $ico = iconArchivoGG($arch['nombre_original']);
        $mime = $arch['tipo_mime'] ?? '';
        $ext = strtolower(pathinfo($arch['nombre_original'], PATHINFO_EXTENSION));
        $esImagen = in_array($mime, ['image/jpeg','image/png','image/gif','image/webp']) || in_array($ext, ['jpg','jpeg','png','gif','webp']);
        $esPdf = $mime === 'application/pdf' || $ext === 'pdf';
        $puedePrev = $esImagen || $esPdf;
        $urlVer = 'descargar_archivo.php?id=' . (int)$arch['id'] . '&accion=ver';
        $urlDl  = 'descargar_archivo.php?id=' . (int)$arch['id'];
        $tipo = $esImagen ? 'imagen' : ($esPdf ? 'pdf' : 'otro');
        ?>
        <div class="d-flex align-items-center justify-content-between border rounded px-2 py-1 mb-1" style="font-size:12px;">
          <div class="d-flex align-items-center flex-grow-1 overflow-hidden">
            <?php if ($esImagen): ?>
              <a href="#" class="me-2 flex-shrink-0 gg-preview-trigger" data-preview-url="<?= htmlspecialchars($urlVer) ?>" data-preview-tipo="imagen" data-preview-nombre="<?= htmlspecialchars($arch['nombre_original']) ?>" title="Vista previa">
                <img src="<?= htmlspecialchars($urlVer) ?>" alt="" style="width:36px;height:36px;object-fit:cover;border-radius:4px;border:1px solid #dee2e6;">
              </a>
            <?php else: ?>
              <i class="bi <?= $ico[0] ?> <?= $ico[1] ?> me-1 flex-shrink-0" style="font-size:18px;"></i>
            <?php endif; ?>
            <div class="overflow-hidden">
              <?php if ($puedePrev): ?>
              <a href="#" class="text-decoration-none text-truncate d-block gg-preview-trigger"
                 data-preview-url="<?= htmlspecialchars($urlVer) ?>" data-preview-tipo="<?= $tipo ?>"
                 data-preview-nombre="<?= htmlspecialchars($arch['nombre_original']) ?>"
                 title="<?= htmlspecialchars($arch['nombre_original']) ?>"><?= htmlspecialchars(mb_strimwidth($arch['nombre_original'], 0, 40, '...')) ?></a>
              <?php else: ?>
              <a href="<?= htmlspecialchars($urlDl) ?>" class="text-decoration-none text-truncate d-block" title="<?= htmlspecialchars($arch['nombre_original']) ?>"><?= htmlspecialchars(mb_strimwidth($arch['nombre_original'], 0, 40, '...')) ?></a>
              <?php endif; ?>
              <small class="text-muted"><?= formatBytesGG((int)$arch['tamano']) ?> · <?= date('d/m/Y', strtotime($arch['fecha'])) ?> · <?= htmlspecialchars($arch['usuario']) ?></small>
            </div>
          </div>
          <div class="d-flex gap-1 flex-shrink-0 ms-1">
            <?php if ($puedePrev): ?>
            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 gg-preview-trigger"
                    data-preview-url="<?= htmlspecialchars($urlVer) ?>" data-preview-tipo="<?= $tipo ?>"
                    data-preview-nombre="<?= htmlspecialchars($arch['nombre_original']) ?>"
                    title="Vista previa"><i class="bi bi-eye"></i></button>
            <?php endif; ?>
            <a href="<?= htmlspecialchars($urlDl) ?>" class="btn btn-sm btn-outline-primary py-0 px-1" title="Descargar"><i class="bi bi-download"></i></a>
          </div>
        </div>
        <?php
    }
}

$soloFaltaUsted = 0;
foreach ($cotPrev as $arr) {
    $aprobAnt = false; $pendAnt = false;
    foreach ($arr as $p) {
        if ((int)$p['orden'] < $miOrden) {
            if ($p['estado'] === 'aprobada') $aprobAnt = true;
            elseif ($p['estado'] === 'pendiente') $pendAnt = true;
        }
    }
    if ($aprobAnt && !$pendAnt) $soloFaltaUsted++;
}
foreach ($ocPrev as $arr) {
    $aprobAnt = false; $pendAnt = false;
    foreach ($arr as $p) {
        if ((int)$p['orden'] < $miOrden) {
            if ($p['estado'] === 'aprobada') $aprobAnt = true;
            elseif ($p['estado'] === 'pendiente') $pendAnt = true;
        }
    }
    if ($aprobAnt && !$pendAnt) $soloFaltaUsted++;
}

$titulo = 'Validaciones · Gerencia General';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h_($titulo) ?> · DOGroup</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  :root { --gg-accent: #6f42c1; --gg-accent-dark: #4c2a85; }
  body { font-family:'Montserrat', system-ui, sans-serif; background:#f7f6fb; min-height:100dvh; }
  .gg-header { background: linear-gradient(135deg, var(--gg-accent), var(--gg-accent-dark)); color:#fff; padding:18px 24px; border-bottom-left-radius:24px; border-bottom-right-radius:24px; box-shadow:0 8px 24px rgba(76,42,133,.25); }
  .gg-card { background:#fff; border:0; border-radius:16px; box-shadow:0 4px 14px rgba(0,0,0,.05); }
  .gg-card.solo-falta { border-left: 5px solid #16a34a; }
  .gg-card .card-header { background:transparent; border:0; font-weight:700; color:#3d2766; }
  .badge-solo-falta { background:#16a34a; color:#fff; font-weight:600; font-size:.7rem; }
  .timeline-prev { background:#fafaff; border-radius:10px; padding:8px 10px; font-size:.8rem; }
  .nav-tabs .nav-link.active { background:var(--gg-accent); color:#fff !important; border-color:var(--gg-accent); }
</style>
</head>
<body>

<div class="gg-header">
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div class="d-flex align-items-center gap-3">
      <i class="bi bi-shield-check" style="font-size:2rem;"></i>
      <div>
        <h3 class="mb-0 fw-bold">Validaciones</h3>
        <div class="small opacity-75"><?= h_($gg['nombre'] ?: $gg['usuario']) ?> · Pendientes: <strong><?= count($cotPendientes)+count($ocPendientes) ?></strong>
        <?php if ($soloFaltaUsted > 0): ?>
          · <span class="badge badge-solo-falta"><i class="bi bi-exclamation-triangle me-1"></i><?= $soloFaltaUsted ?> solo falta tu firma</span>
        <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <a href="dashboard.php" class="btn btn-sm btn-light"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
      <a href="logout.php" class="btn btn-sm btn-outline-light"><i class="bi bi-box-arrow-right me-1"></i>Salir</a>
    </div>
  </div>
</div>

<div class="container py-4">

  <?php if ($msg): ?>
  <div class="alert alert-<?= $msg[0] ?> alert-dismissible fade show"><i class="bi bi-info-circle me-1"></i><?= h_($msg[1]) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
  <?php endif; ?>

  <ul class="nav nav-tabs mb-3" id="tabs" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-cot"><i class="bi bi-file-earmark-text me-1"></i>Cotizaciones (<?= count($cotPendientes) ?>)</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-oc"><i class="bi bi-receipt me-1"></i>Órdenes de Compra (<?= count($ocPendientes) ?>)</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-enproc"><i class="bi bi-graph-up-arrow me-1"></i>En proceso (<?= count($cotEnProceso) ?>)</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-hist"><i class="bi bi-clock-history me-1"></i>Historial</button></li>
  </ul>

  <div class="tab-content">

    <!-- ===================== COTIZACIONES PENDIENTES ===================== -->
    <div class="tab-pane fade show active" id="tab-cot" role="tabpanel">
      <?php if (empty($cotPendientes)): ?>
        <div class="alert alert-success"><i class="bi bi-check2-circle me-1"></i>No hay cotizaciones pendientes de tu validación.</div>
      <?php else: ?>
        <?php foreach ($cotPendientes as $c):
          $previas = $cotPrev[$c['id']] ?? [];
          $aprobAnt = false; $pendAnt = false; $cualquierAnt = false;
          foreach ($previas as $p) {
            if ((int)$p['orden'] < $miOrden) {
              $cualquierAnt = true;
              if ($p['estado'] === 'aprobada') $aprobAnt = true;
              elseif ($p['estado'] === 'pendiente') $pendAnt = true;
            }
          }
          $esSoloFalta = $cualquierAnt && $aprobAnt && !$pendAnt;
        ?>
        <div class="card gg-card mb-3 <?= $esSoloFalta ? 'solo-falta' : '' ?>">
          <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
              <i class="bi bi-file-earmark-text me-1"></i>
              <strong>Cotización N° <?= h_($c['numero']) ?></strong>
              <span class="text-muted ms-2">· <?= h_($c['cliente_nombre']) ?></span>
              <?php if ($c['cliente_obra']): ?><div class="small text-muted mt-1"><i class="bi bi-geo-alt me-1"></i><?= h_($c['cliente_obra']) ?></div><?php endif; ?>
            </div>
            <div class="text-end">
              <div class="fw-bold fs-5 text-success"><?= fmtCLP_($c['total']) ?></div>
              <small class="text-muted">Creada por: <?= h_($c['creada_por'] ?: '—') ?></small>
              <?php if ($esSoloFalta): ?>
              <br><span class="badge badge-solo-falta mt-1"><i class="bi bi-exclamation-triangle me-1"></i>Solo falta tu validación</span>
              <?php endif; ?>
            </div>
          </div>
          <div class="card-body pt-2">
            <?php if (!empty($previas)): ?>
            <div class="mb-3">
              <div class="small fw-semibold text-muted mb-1">Validaciones previas:</div>
              <?php foreach ($previas as $p):
                $badgeMap = ['aprobada'=>'success','rechazada'=>'danger','corregir'=>'warning','pendiente'=>'secondary'];
                $iconMap  = ['aprobada'=>'check-circle','rechazada'=>'x-circle','corregir'=>'pencil','pendiente'=>'hourglass'];
                $bc = $badgeMap[$p['estado']] ?? 'secondary';
                $ic = $iconMap[$p['estado']] ?? 'circle';
              ?>
              <div class="timeline-prev mb-1">
                <strong><?= h_($p['nombre']) ?></strong>
                <?php if ($p['cargo']): ?><small class="text-muted">— <?= h_($p['cargo']) ?></small><?php endif; ?>
                <span class="badge bg-<?= $bc ?> ms-2"><i class="bi bi-<?= $ic ?> me-1"></i><?= ucfirst($p['estado']) ?></span>
                <?php if ($p['fecha_respuesta']): ?>
                <small class="text-muted ms-2"><i class="bi bi-calendar-check me-1"></i><?= date('d/m/Y H:i', strtotime($p['fecha_respuesta'])) ?></small>
                <?php endif; ?>
                <?php if ($p['comentario']): ?>
                <div class="mt-1 small fst-italic">"<?= h_($p['comentario']) ?>"</div>
                <?php endif; ?>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php $detalleCot = $cotDetalles[(int)$c['cot_id']] ?? []; $itemsCot = $cotItems[(int)$c['cot_id']] ?? []; ?>
            <div class="mb-3">
              <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#detCot<?= (int)$c['id'] ?>" aria-expanded="false">
                <i class="bi bi-eye me-1"></i>Ver detalles de la cotización
              </button>
            </div>

            <div class="collapse mb-3" id="detCot<?= (int)$c['id'] ?>">
              <div class="border rounded p-3 bg-light">
                <div class="row g-2 small mb-3">
                  <div class="col-md-6"><strong>Cliente:</strong> <?= h_($detalleCot['cliente_nombre'] ?? '') ?></div>
                  <?php if (!empty($detalleCot['cliente_rut'])): ?>
                  <div class="col-md-6"><strong>RUT:</strong> <?= h_($detalleCot['cliente_rut']) ?></div>
                  <?php endif; ?>
                  <?php if (!empty($detalleCot['contacto_nombre'])): ?>
                  <div class="col-md-6"><strong>Contacto:</strong> <?= h_($detalleCot['contacto_nombre']) ?>
                    <?php if (!empty($detalleCot['contacto_email'])): ?> · <?= h_($detalleCot['contacto_email']) ?><?php endif; ?>
                  </div>
                  <?php endif; ?>
                  <?php if (!empty($detalleCot['cliente_obra'])): ?>
                  <div class="col-md-6"><strong>Obra:</strong> <?= h_($detalleCot['cliente_obra']) ?></div>
                  <?php endif; ?>
                  <div class="col-md-6"><strong>Fecha:</strong> <?= !empty($detalleCot['fecha']) ? date('d/m/Y', strtotime($detalleCot['fecha'])) : '—' ?></div>
                  <div class="col-md-6"><strong>Creada por:</strong> <?= h_($detalleCot['creada_por'] ?? '—') ?></div>
                  <?php if (!empty($detalleCot['descripcion'])): ?>
                  <div class="col-12 mt-2"><strong>Descripción:</strong><br><span class="text-muted"><?= nl2br(h_($detalleCot['descripcion'])) ?></span></div>
                  <?php endif; ?>
                </div>

                <?php if (!empty($itemsCot)): ?>
                <div class="fw-semibold small mb-1"><i class="bi bi-list-ul me-1"></i>Detalle (<?= count($itemsCot) ?> ítem<?= count($itemsCot)==1?'':'s' ?>)</div>
                <div class="table-responsive">
                  <table class="table table-sm table-bordered mb-2" style="font-size:12px;">
                    <thead class="table-light">
                      <tr>
                        <th style="width:48%">Descripción</th>
                        <th class="text-end">Cant.</th>
                        <th>Unid.</th>
                        <th class="text-end">Precio unit.</th>
                        <th class="text-end">Total</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($itemsCot as $it): ?>
                      <tr>
                        <td><?= nl2br(h_($it['descripcion'])) ?></td>
                        <td class="text-end"><?= rtrim(rtrim(number_format((float)$it['cantidad'], 2, ',', '.'),'0'),',') ?></td>
                        <td><?= h_($it['unidad']) ?></td>
                        <td class="text-end"><?= fmtCLP_($it['precio_unit']) ?></td>
                        <td class="text-end fw-semibold"><?= fmtCLP_($it['total']) ?></td>
                      </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <?php endif; ?>

                <div class="row g-1 small justify-content-end">
                  <div class="col-md-4">
                    <div class="d-flex justify-content-between"><span>Subtotal:</span><strong><?= fmtCLP_($detalleCot['subtotal'] ?? 0) ?></strong></div>
                    <div class="d-flex justify-content-between"><span>IVA:</span><strong><?= fmtCLP_($detalleCot['iva'] ?? 0) ?></strong></div>
                    <div class="d-flex justify-content-between border-top pt-1 mt-1"><span class="fw-bold">Total:</span><span class="fw-bold text-success fs-6"><?= fmtCLP_($detalleCot['total'] ?? 0) ?></span></div>
                  </div>
                </div>

                <?php $archCot = $cotArchivos[(int)$c['cot_id']] ?? []; ?>
                <div class="mt-3">
                  <div class="fw-semibold small mb-2"><i class="bi bi-paperclip me-1"></i>Archivos adjuntos (<?= count($archCot) ?>)</div>
                  <?php renderArchivosGG($archCot); ?>
                </div>
              </div>
            </div>

            <form method="POST" onsubmit="return confirm('¿Confirmar decisión?')">
              <input type="hidden" name="action" value="validar_cot">
              <input type="hidden" name="aprob_id" value="<?= (int)$c['id'] ?>">
              <textarea name="comentario" class="form-control form-control-sm mb-2" rows="2" placeholder="Comentario (recomendado para rechazo o correcciones)"></textarea>
              <div class="d-flex gap-2 flex-wrap">
                <button type="submit" name="decision" value="aprobada" class="btn btn-success"><i class="bi bi-check-circle me-1"></i>Aprobar</button>
                <button type="submit" name="decision" value="corregir" class="btn btn-warning"><i class="bi bi-pencil me-1"></i>Solicitar correcciones</button>
                <button type="submit" name="decision" value="rechazada" class="btn btn-danger"><i class="bi bi-x-circle me-1"></i>Rechazar</button>
              </div>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- ===================== OC PENDIENTES ===================== -->
    <div class="tab-pane fade" id="tab-oc" role="tabpanel">
      <?php if (empty($ocPendientes)): ?>
        <div class="alert alert-success"><i class="bi bi-check2-circle me-1"></i>No hay OC pendientes de tu validación.</div>
      <?php else: ?>
        <?php foreach ($ocPendientes as $o):
          $previas = $ocPrev[$o['id']] ?? [];
          $aprobAnt = false; $pendAnt = false; $cualquierAnt = false;
          foreach ($previas as $p) {
            if ((int)$p['orden'] < $miOrden) {
              $cualquierAnt = true;
              if ($p['estado'] === 'aprobada') $aprobAnt = true;
              elseif ($p['estado'] === 'pendiente') $pendAnt = true;
            }
          }
          $esSoloFalta = $cualquierAnt && $aprobAnt && !$pendAnt;
          $mon = strtoupper($o['moneda'] ?: 'CLP');
          $totalFmt = $mon === 'CLP' ? fmtCLP_($o['total']) : ($mon.' '.number_format((float)$o['total'], 2, ',', '.'));
        ?>
        <div class="card gg-card mb-3 <?= $esSoloFalta ? 'solo-falta' : '' ?>">
          <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
              <i class="bi bi-receipt me-1"></i>
              <strong>OC N° <?= h_($o['numero']) ?></strong>
              <span class="text-muted ms-2">· <?= h_($o['proveedor_nombre']) ?></span>
              <?php if ($o['obra']): ?><div class="small text-muted mt-1"><i class="bi bi-geo-alt me-1"></i><?= h_($o['obra']) ?></div><?php endif; ?>
            </div>
            <div class="text-end">
              <div class="fw-bold fs-5 text-success"><?= $totalFmt ?></div>
              <small class="text-muted">Preparada por: <?= h_($o['preparada_por'] ?: '—') ?></small>
              <?php if ($esSoloFalta): ?>
              <br><span class="badge badge-solo-falta mt-1"><i class="bi bi-exclamation-triangle me-1"></i>Solo falta tu validación</span>
              <?php endif; ?>
            </div>
          </div>
          <div class="card-body pt-2">
            <?php if (!empty($previas)): ?>
            <div class="mb-3">
              <div class="small fw-semibold text-muted mb-1">Validaciones previas:</div>
              <?php foreach ($previas as $p):
                $badgeMap = ['aprobada'=>'success','rechazada'=>'danger','corregir'=>'warning','pendiente'=>'secondary'];
                $iconMap  = ['aprobada'=>'check-circle','rechazada'=>'x-circle','corregir'=>'pencil','pendiente'=>'hourglass'];
                $bc = $badgeMap[$p['estado']] ?? 'secondary';
                $ic = $iconMap[$p['estado']] ?? 'circle';
              ?>
              <div class="timeline-prev mb-1">
                <strong><?= h_($p['nombre']) ?></strong>
                <?php if ($p['cargo']): ?><small class="text-muted">— <?= h_($p['cargo']) ?></small><?php endif; ?>
                <span class="badge bg-<?= $bc ?> ms-2"><i class="bi bi-<?= $ic ?> me-1"></i><?= ucfirst($p['estado']) ?></span>
                <?php if ($p['fecha_respuesta']): ?>
                <small class="text-muted ms-2"><i class="bi bi-calendar-check me-1"></i><?= date('d/m/Y H:i', strtotime($p['fecha_respuesta'])) ?></small>
                <?php endif; ?>
                <?php if ($p['comentario']): ?>
                <div class="mt-1 small fst-italic">"<?= h_($p['comentario']) ?>"</div>
                <?php endif; ?>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php $detalleOc = $ocDetalles[(int)$o['oc_id']] ?? []; $itemsOc = $ocItems[(int)$o['oc_id']] ?? []; ?>
            <div class="mb-3">
              <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#detOc<?= (int)$o['id'] ?>" aria-expanded="false">
                <i class="bi bi-eye me-1"></i>Ver detalles de la OC
              </button>
            </div>

            <div class="collapse mb-3" id="detOc<?= (int)$o['id'] ?>">
              <div class="border rounded p-3 bg-light">
                <div class="row g-2 small mb-3">
                  <div class="col-md-6"><strong>Proveedor:</strong> <?= h_($detalleOc['proveedor_nombre'] ?? '') ?></div>
                  <?php if (!empty($detalleOc['proveedor_rut'])): ?>
                  <div class="col-md-6"><strong>RUT:</strong> <?= h_($detalleOc['proveedor_rut']) ?></div>
                  <?php endif; ?>
                  <?php if (!empty($detalleOc['obra'])): ?>
                  <div class="col-md-6"><strong>Obra:</strong> <?= h_($detalleOc['obra']) ?></div>
                  <?php endif; ?>
                  <div class="col-md-6"><strong>Fecha:</strong> <?= !empty($detalleOc['fecha']) ? date('d/m/Y', strtotime($detalleOc['fecha'])) : '—' ?></div>
                  <div class="col-md-6"><strong>Preparada por:</strong> <?= h_($detalleOc['preparada_por'] ?? '—') ?></div>
                  <div class="col-md-6"><strong>Moneda:</strong> <?= h_(strtoupper($detalleOc['moneda'] ?? 'CLP')) ?></div>
                  <?php if (!empty($detalleOc['observaciones'])): ?>
                  <div class="col-12 mt-2"><strong>Observaciones:</strong><br><span class="text-muted"><?= nl2br(h_($detalleOc['observaciones'])) ?></span></div>
                  <?php endif; ?>
                </div>

                <?php if (!empty($itemsOc)): ?>
                <div class="fw-semibold small mb-1"><i class="bi bi-list-ul me-1"></i>Detalle (<?= count($itemsOc) ?> ítem<?= count($itemsOc)==1?'':'s' ?>)</div>
                <div class="table-responsive">
                  <table class="table table-sm table-bordered mb-2" style="font-size:12px;">
                    <thead class="table-light">
                      <tr>
                        <th style="width:48%">Descripción</th>
                        <th class="text-end">Cant.</th>
                        <th>Unid.</th>
                        <th class="text-end">Precio unit.</th>
                        <th class="text-end">Total</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($itemsOc as $it): ?>
                      <tr>
                        <td><?= nl2br(h_($it['descripcion'])) ?></td>
                        <td class="text-end"><?= rtrim(rtrim(number_format((float)$it['cantidad'], 2, ',', '.'),'0'),',') ?></td>
                        <td><?= h_($it['unidad']) ?></td>
                        <td class="text-end"><?= fmtCLP_($it['precio_unit']) ?></td>
                        <td class="text-end fw-semibold"><?= fmtCLP_($it['total']) ?></td>
                      </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <?php endif; ?>

                <div class="row g-1 small justify-content-end">
                  <div class="col-md-4">
                    <div class="d-flex justify-content-between"><span>Neto:</span><strong><?= fmtCLP_($detalleOc['neto'] ?? 0) ?></strong></div>
                    <div class="d-flex justify-content-between"><span>IVA:</span><strong><?= fmtCLP_($detalleOc['iva'] ?? 0) ?></strong></div>
                    <div class="d-flex justify-content-between border-top pt-1 mt-1"><span class="fw-bold">Total:</span><span class="fw-bold text-success fs-6"><?= fmtCLP_($detalleOc['total'] ?? 0) ?></span></div>
                  </div>
                </div>
              </div>
            </div>

            <form method="POST" onsubmit="return confirm('¿Confirmar decisión?')">
              <input type="hidden" name="action" value="validar_oc">
              <input type="hidden" name="aprob_id" value="<?= (int)$o['id'] ?>">
              <textarea name="comentario" class="form-control form-control-sm mb-2" rows="2" placeholder="Comentario (recomendado para rechazo o correcciones)"></textarea>
              <div class="d-flex gap-2 flex-wrap">
                <button type="submit" name="decision" value="aprobada" class="btn btn-success"><i class="bi bi-check-circle me-1"></i>Aprobar</button>
                <button type="submit" name="decision" value="corregir" class="btn btn-warning"><i class="bi bi-pencil me-1"></i>Solicitar correcciones</button>
                <button type="submit" name="decision" value="rechazada" class="btn btn-danger"><i class="bi bi-x-circle me-1"></i>Rechazar</button>
              </div>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- ===================== EN PROCESO (Adjudicada / Negociación / Propuesta) ===================== -->
    <div class="tab-pane fade" id="tab-enproc" role="tabpanel">
      <div class="alert alert-info py-2 small mb-3"><i class="bi bi-info-circle me-1"></i>Cotizaciones ya aprobadas que están en proceso comercial. Solo lectura.</div>
      <?php if (empty($cotEnProceso)): ?>
        <div class="alert alert-secondary"><i class="bi bi-inbox me-1"></i>No hay cotizaciones en proceso comercial.</div>
      <?php else: ?>
        <?php foreach ($cotEnProceso as $cp):
          $itemsCp = $cotEnProcesoItems[(int)$cp['id']] ?? [];
          $estadoCp = $cp['estado'];
          $badgeMap = ['adjudicada'=>['success','trophy'],'negociacion'=>['primary','chat-dots'],'propuesta'=>['info','file-earmark-text']];
          $bi = $badgeMap[$estadoCp] ?? ['secondary','circle'];
        ?>
        <div class="card gg-card mb-3">
          <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
              <i class="bi bi-file-earmark-text me-1"></i>
              <strong>Cotización N° <?= h_($cp['numero']) ?></strong>
              <span class="badge bg-<?= $bi[0] ?> ms-2"><i class="bi bi-<?= $bi[1] ?> me-1"></i><?= ucfirst($estadoCp) ?></span>
              <span class="text-muted ms-2">· <?= h_($cp['cliente_nombre']) ?></span>
              <?php if (!empty($cp['cliente_obra'])): ?><div class="small text-muted mt-1"><i class="bi bi-geo-alt me-1"></i><?= h_($cp['cliente_obra']) ?></div><?php endif; ?>
            </div>
            <div class="text-end">
              <div class="fw-bold fs-5 text-success"><?= fmtCLP_($cp['total']) ?></div>
              <small class="text-muted">Creada por: <?= h_($cp['creada_por'] ?: '—') ?></small>
            </div>
          </div>
          <div class="card-body pt-2">
            <div class="mb-3">
              <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#detEnProc<?= (int)$cp['id'] ?>" aria-expanded="false">
                <i class="bi bi-eye me-1"></i>Ver vista previa de la cotización
              </button>
            </div>
            <div class="collapse" id="detEnProc<?= (int)$cp['id'] ?>">
              <div class="border rounded p-3 bg-light">
                <div class="row g-2 small mb-3">
                  <div class="col-md-6"><strong>Cliente:</strong> <?= h_($cp['cliente_nombre']) ?></div>
                  <?php if (!empty($cp['cliente_rut'])): ?>
                  <div class="col-md-6"><strong>RUT:</strong> <?= h_($cp['cliente_rut']) ?></div>
                  <?php endif; ?>
                  <?php if (!empty($cp['contacto_nombre'])): ?>
                  <div class="col-md-6"><strong>Contacto:</strong> <?= h_($cp['contacto_nombre']) ?>
                    <?php if (!empty($cp['contacto_email'])): ?> · <?= h_($cp['contacto_email']) ?><?php endif; ?>
                  </div>
                  <?php endif; ?>
                  <?php if (!empty($cp['cliente_obra'])): ?>
                  <div class="col-md-6"><strong>Obra:</strong> <?= h_($cp['cliente_obra']) ?></div>
                  <?php endif; ?>
                  <div class="col-md-6"><strong>Fecha:</strong> <?= !empty($cp['fecha']) ? date('d/m/Y', strtotime($cp['fecha'])) : '—' ?></div>
                  <div class="col-md-6"><strong>Creada por:</strong> <?= h_($cp['creada_por'] ?? '—') ?></div>
                  <?php if (!empty($cp['descripcion'])): ?>
                  <div class="col-12 mt-2"><strong>Descripción:</strong><br><span class="text-muted"><?= nl2br(h_($cp['descripcion'])) ?></span></div>
                  <?php endif; ?>
                </div>

                <?php if (!empty($itemsCp)): ?>
                <div class="fw-semibold small mb-1"><i class="bi bi-list-ul me-1"></i>Detalle (<?= count($itemsCp) ?> ítem<?= count($itemsCp)==1?'':'s' ?>)</div>
                <div class="table-responsive">
                  <table class="table table-sm table-bordered mb-2" style="font-size:12px;">
                    <thead class="table-light">
                      <tr>
                        <th style="width:48%">Descripción</th>
                        <th class="text-end">Cant.</th>
                        <th>Unid.</th>
                        <th class="text-end">Precio unit.</th>
                        <th class="text-end">Total</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($itemsCp as $it): ?>
                      <tr>
                        <td><?= nl2br(h_($it['descripcion'])) ?></td>
                        <td class="text-end"><?= rtrim(rtrim(number_format((float)$it['cantidad'], 2, ',', '.'),'0'),',') ?></td>
                        <td><?= h_($it['unidad']) ?></td>
                        <td class="text-end"><?= fmtCLP_($it['precio_unit']) ?></td>
                        <td class="text-end fw-semibold"><?= fmtCLP_($it['total']) ?></td>
                      </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <?php endif; ?>

                <div class="row g-1 small justify-content-end">
                  <div class="col-md-4">
                    <div class="d-flex justify-content-between"><span>Subtotal:</span><strong><?= fmtCLP_($cp['subtotal'] ?? 0) ?></strong></div>
                    <div class="d-flex justify-content-between"><span>IVA:</span><strong><?= fmtCLP_($cp['iva'] ?? 0) ?></strong></div>
                    <div class="d-flex justify-content-between border-top pt-1 mt-1"><span class="fw-bold">Total:</span><span class="fw-bold text-success fs-6"><?= fmtCLP_($cp['total'] ?? 0) ?></span></div>
                  </div>
                </div>

                <?php $archEnProc = $cotEnProcesoArchivos[(int)$cp['id']] ?? []; ?>
                <div class="mt-3">
                  <div class="fw-semibold small mb-2"><i class="bi bi-paperclip me-1"></i>Archivos adjuntos (<?= count($archEnProc) ?>)</div>
                  <?php renderArchivosGG($archEnProc); ?>
                </div>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- ===================== HISTORIAL ===================== -->
    <div class="tab-pane fade" id="tab-hist" role="tabpanel">
      <div class="card gg-card mb-3">
        <div class="card-header"><i class="bi bi-file-earmark-text me-1"></i>Cotizaciones — Últimas 20</div>
        <div class="card-body p-2">
          <?php if (empty($cotHist)): ?>
            <div class="text-muted text-center py-3">Sin historial.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead><tr><th>N°</th><th>Cliente</th><th class="text-end">Total</th><th>Tu decisión</th><th>Comentario</th><th>Cuándo</th></tr></thead>
                <tbody>
                <?php foreach ($cotHist as $h):
                  $badgeMap = ['aprobada'=>'success','rechazada'=>'danger','corregir'=>'warning'];
                  $bc = $badgeMap[$h['estado']] ?? 'secondary';
                ?>
                <tr>
                  <td><a href="../cotizaciones/ver_cotizacion.php?id=<?= (int)$h['cot_id'] ?>" target="_blank" class="fw-bold text-decoration-none"><?= h_($h['numero']) ?></a></td>
                  <td><?= h_($h['cliente_nombre']) ?><?php if ($h['cliente_obra']): ?><br><small class="text-muted"><?= h_($h['cliente_obra']) ?></small><?php endif; ?></td>
                  <td class="text-end fw-bold"><?= fmtCLP_($h['total']) ?></td>
                  <td><span class="badge bg-<?= $bc ?>"><?= ucfirst($h['estado']) ?></span></td>
                  <td><small><?= h_($h['comentario'] ?: '—') ?></small></td>
                  <td><small class="text-muted"><?= $h['fecha_respuesta'] ? date('d/m/Y H:i', strtotime($h['fecha_respuesta'])) : '—' ?></small></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card gg-card">
        <div class="card-header"><i class="bi bi-receipt me-1"></i>Órdenes de Compra — Últimas 20</div>
        <div class="card-body p-2">
          <?php if (empty($ocHist)): ?>
            <div class="text-muted text-center py-3">Sin historial.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead><tr><th>N°</th><th>Proveedor</th><th class="text-end">Total</th><th>Tu decisión</th><th>Comentario</th><th>Cuándo</th></tr></thead>
                <tbody>
                <?php foreach ($ocHist as $h):
                  $badgeMap = ['aprobada'=>'success','rechazada'=>'danger','corregir'=>'warning'];
                  $bc = $badgeMap[$h['estado']] ?? 'secondary';
                  $mon = strtoupper($h['moneda'] ?: 'CLP');
                  $totalFmt = $mon === 'CLP' ? fmtCLP_($h['total']) : ($mon.' '.number_format((float)$h['total'], 2, ',', '.'));
                ?>
                <tr>
                  <td><a href="../ver.php?id=<?= (int)$h['oc_id'] ?>" target="_blank" class="fw-bold text-decoration-none"><?= h_($h['numero']) ?></a></td>
                  <td><?= h_($h['proveedor_nombre']) ?><?php if ($h['obra']): ?><br><small class="text-muted"><?= h_($h['obra']) ?></small><?php endif; ?></td>
                  <td class="text-end fw-bold"><?= $totalFmt ?></td>
                  <td><span class="badge bg-<?= $bc ?>"><?= ucfirst($h['estado']) ?></span></td>
                  <td><small><?= h_($h['comentario'] ?: '—') ?></small></td>
                  <td><small class="text-muted"><?= $h['fecha_respuesta'] ? date('d/m/Y H:i', strtotime($h['fecha_respuesta'])) : '—' ?></small></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div>
</div>

<!-- Modal Vista Previa Archivos -->
<div class="modal fade" id="ggModalPreview" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title text-truncate"><i class="bi bi-eye me-2"></i><span id="ggPreviewNombre">Vista previa</span></h6>
        <div class="ms-auto d-flex align-items-center gap-2">
          <a id="ggPreviewDescargar" href="#" class="btn btn-sm btn-outline-primary"><i class="bi bi-download me-1"></i>Descargar</a>
          <a id="ggPreviewAbrir" href="#" class="btn btn-sm btn-outline-secondary" target="_blank"><i class="bi bi-box-arrow-up-right me-1"></i>Abrir</a>
          <button type="button" class="btn-close ms-2" data-bs-dismiss="modal"></button>
        </div>
      </div>
      <div class="modal-body p-0 bg-light" style="min-height:70vh;">
        <div id="ggPreviewContenido" class="d-flex align-items-center justify-content-center" style="min-height:70vh;">
          <div class="text-muted"><i class="bi bi-hourglass-split me-2"></i>Cargando…</div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  var modalEl = document.getElementById('ggModalPreview');
  if (!modalEl) return;
  var bsModal = null;
  function getModal(){
    if (!bsModal && typeof bootstrap !== 'undefined') bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
    return bsModal;
  }
  var cont   = document.getElementById('ggPreviewContenido');
  var nomEl  = document.getElementById('ggPreviewNombre');
  var dlBtn  = document.getElementById('ggPreviewDescargar');
  var abBtn  = document.getElementById('ggPreviewAbrir');

  function abrir(url, tipo, nombre){
    if (!url) return;
    nomEl.textContent = nombre || 'Vista previa';
    abBtn.href = url;
    dlBtn.href = url.replace(/(\?|&)accion=ver/, '$1accion=descargar');
    cont.innerHTML = '';
    if (tipo === 'imagen') {
      var img = document.createElement('img');
      img.src = url; img.alt = nombre || '';
      img.style.maxWidth='100%'; img.style.maxHeight='85vh'; img.style.objectFit='contain';
      img.style.display='block'; img.style.margin='0 auto';
      cont.appendChild(img);
    } else if (tipo === 'pdf') {
      var ifr = document.createElement('iframe');
      ifr.src = url; ifr.style.width='100%'; ifr.style.height='85vh'; ifr.style.border='0';
      cont.appendChild(ifr);
    } else {
      cont.innerHTML = '<div class="text-muted p-4">Sin vista previa. <a href="'+url+'" target="_blank">Abrir archivo</a></div>';
    }
    var m = getModal();
    if (m) m.show();
  }

  document.addEventListener('click', function(ev){
    var t = ev.target.closest('.gg-preview-trigger');
    if (!t) return;
    ev.preventDefault();
    abrir(t.getAttribute('data-preview-url'), t.getAttribute('data-preview-tipo'), t.getAttribute('data-preview-nombre'));
  });
  modalEl.addEventListener('hidden.bs.modal', function(){
    cont.innerHTML = '<div class="text-muted"><i class="bi bi-hourglass-split me-2"></i>Cargando…</div>';
  });
})();
</script>
</body>
</html>
