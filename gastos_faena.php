<?php
require_once 'config.php';
requireAuth();

$CATEGORIAS = [
    1  => ['nombre'=>'Combustible',   'icono'=>'bi-fuel-pump-fill'],
    2  => ['nombre'=>'Alimentación',  'icono'=>'bi-cup-hot-fill'],
    3  => ['nombre'=>'Herramientas',  'icono'=>'bi-tools'],
    4  => ['nombre'=>'Mano de obra',  'icono'=>'bi-person-badge-fill'],
    5  => ['nombre'=>'Materiales',    'icono'=>'bi-box-seam-fill'],
    6  => ['nombre'=>'Transporte',    'icono'=>'bi-truck-fill'],
    7  => ['nombre'=>'Equipos',       'icono'=>'bi-gear-fill'],
    8  => ['nombre'=>'Arriendo',      'icono'=>'bi-building-fill'],
    9  => ['nombre'=>'Servicios',     'icono'=>'bi-lightning-fill'],
    10 => ['nombre'=>'Peaje',         'icono'=>'bi-signpost-2-fill'],
    11 => ['nombre'=>'Hospedaje',     'icono'=>'bi-house-fill'],
    12 => ['nombre'=>'Otros',         'icono'=>'bi-three-dots'],
];

$esAdmin = ($usuario['rol'] === 'admin') || isDespachoAdmin($usuario, $pdo);

$msg = null; $msgTipo = 'success';

/* ══ POST: Crear ══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'crear') {
    try {
        $fecha     = $_POST['fecha']      ?? date('Y-m-d');
        $obraId    = (int)($_POST['obra_id'] ?? 0) ?: null;
        $catId     = (int)($_POST['categoria_id'] ?? 0) ?: null;
        $catNom    = $catId && isset($CATEGORIAS[$catId]) ? $CATEGORIAS[$catId]['nombre'] : trim($_POST['categoria_libre'] ?? '');
        $desc      = trim($_POST['descripcion'] ?? '');
        $monto     = (float)str_replace(['.','$',' '],['','',''], $_POST['monto'] ?? '0');
        $peaje     = (float)str_replace(['.','$',' '],['','',''], $_POST['monto_peaje'] ?? '0');
        $proveedor = trim($_POST['proveedor'] ?? '');
        $nroDoc    = trim($_POST['nro_documento'] ?? '');
        $tipoDoc   = $_POST['tipo_documento'] ?? 'boleta';

        if ($monto <= 0 && $peaje <= 0) throw new Exception('Ingresa al menos un monto mayor a 0.');
        if (!$desc) throw new Exception('La descripción es obligatoria.');

        $obraNom = '';
        if ($obraId) {
            $stO = $pdo->prepare("SELECT codigo, nombre FROM obras WHERE id=?");
            $stO->execute([$obraId]);
            $oRow = $stO->fetch(PDO::FETCH_ASSOC);
            $obraNom = $oRow ? $oRow['codigo'].' — '.$oRow['nombre'] : '';
        }

        $pdo->prepare("INSERT INTO gastos_faena
            (fecha,obra_id,obra_nombre,categoria_id,categoria_nombre,descripcion,
             monto,monto_peaje,proveedor,nro_documento,tipo_documento,
             estado,registrado_por,registrado_por_nombre)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,'pendiente',?,?)")
            ->execute([$fecha,$obraId,$obraNom,$catId,$catNom,$desc,
                       $monto,$peaje,$proveedor,$nroDoc,$tipoDoc,
                       $usuario['id'],$usuario['nombre']]);

        $gid = (int)$pdo->lastInsertId();

        // Subir archivo (boleta/foto)
        if (!empty($_FILES['archivo']['name']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
            $uploadsDir = __DIR__ . '/uploads/faena';
            if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0775, true);
            $orig = $_FILES['archivo']['name'];
            $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','webp','pdf'])) {
                $nuevo = 'gf'.$gid.'_'.time().'.'.$ext;
                if (move_uploaded_file($_FILES['archivo']['tmp_name'], $uploadsDir.'/'.$nuevo)) {
                    $pdo->prepare("INSERT INTO gastos_faena_archivos (gasto_id,nombre,ruta,tipo_mime) VALUES (?,?,?,?)")
                        ->execute([$gid, $orig, 'faena/'.$nuevo, $_FILES['archivo']['type']]);
                    $pdo->prepare("UPDATE gastos_faena SET tiene_archivo=1 WHERE id=?")->execute([$gid]);
                }
            }
        }

        header("Location: gastos_faena.php?ok=1"); exit;
    } catch(Exception $e) { $msg = $e->getMessage(); $msgTipo = 'danger'; }
}

/* ══ POST: Aprobar/Rechazar ══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['accion'] ?? '', ['aprobar','rechazar'])) {
    if ($esAdmin) {
        $gid  = (int)($_POST['gasto_id'] ?? 0);
        $obs  = trim($_POST['observacion'] ?? '');
        $est  = ($_POST['accion'] === 'aprobar') ? 'aprobado' : 'rechazado';
        $pdo->prepare("UPDATE gastos_faena SET estado=?,aprobado_por=?,aprobado_por_nombre=?,aprobado_en=datetime('now','localtime'),observacion_aprobacion=? WHERE id=?")
            ->execute([$est,$usuario['id'],$usuario['nombre'],$obs,$gid]);
        header("Location: gastos_faena.php?ok=2"); exit;
    }
}

// Filtros
$filtroObra = (int)($_GET['obra_id'] ?? 0);
$filtroMes  = $_GET['mes'] ?? date('Y-m');
$filtroEst  = $_GET['estado'] ?? '';

$where  = "WHERE strftime('%Y-%m', g.fecha) = ?";
$params = [$filtroMes];
if (!$esAdmin) { $where .= " AND g.registrado_por=?"; $params[] = $usuario['id']; }
if ($filtroObra) { $where .= " AND g.obra_id=?"; $params[] = $filtroObra; }
if ($filtroEst)  { $where .= " AND g.estado=?";   $params[] = $filtroEst; }

$stG = $pdo->prepare("
    SELECT g.*,
           a.ruta AS archivo_ruta, a.nombre AS archivo_nombre,
           o.codigo AS obra_codigo
    FROM gastos_faena g
    LEFT JOIN gastos_faena_archivos a ON a.id=(SELECT MIN(id) FROM gastos_faena_archivos WHERE gasto_id=g.id)
    LEFT JOIN obras o ON o.id=g.obra_id
    $where
    ORDER BY g.fecha DESC, g.creado_en DESC
");
$stG->execute($params);
$gastos = $stG->fetchAll(PDO::FETCH_ASSOC);

$totalMonto  = array_sum(array_column($gastos,'monto'));
$totalPeaje  = array_sum(array_column($gastos,'monto_peaje'));
$totalGral   = $totalMonto + $totalPeaje;
$cntAprobados = count(array_filter($gastos, fn($g)=>$g['estado']==='aprobado'));
$cntPendientes= count(array_filter($gastos, fn($g)=>$g['estado']==='pendiente'));

$obras = $pdo->query("SELECT id, codigo, nombre FROM obras ORDER BY codigo")->fetchAll(PDO::FETCH_ASSOC);

require_once 'includes/header.php';
?>
<style>
.gf-card{background:#fff;border:1px solid #dee2e6;border-radius:14px;padding:0;margin-bottom:10px;box-shadow:0 1px 5px rgba(0,0,0,.06);overflow:hidden;border-left:4px solid #dee2e6}
.gf-card.pendiente{border-left-color:#ffc107}
.gf-card.aprobado{border-left-color:#198754}
.gf-card.rechazado{border-left-color:#dc3545}
.gf-thumb{width:56px;height:56px;object-fit:cover;border-radius:8px;flex-shrink:0;cursor:pointer}
.gf-thumb-ph{width:56px;height:56px;background:#f8f9fa;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#adb5bd;font-size:1.4rem;flex-shrink:0}
.stat-gf{border-radius:12px;padding:12px 14px;text-align:center;flex:1}
.stat-gf .n{font-size:1.5rem;font-weight:900;line-height:1}
.stat-gf .l{font-size:.68rem;text-transform:uppercase;letter-spacing:.5px;font-weight:700;margin-top:3px;opacity:.75}
</style>

<a href="inicio.php" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left me-1"></i>Volver</a>

<!-- Cabecera -->
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h3 class="fw-bold mb-1"><i class="bi bi-receipt-cutoff text-warning me-2"></i>Gastos por Obra</h3>
    <p class="text-muted small mb-0">Registro de gastos por obra con peaje y comprobante</p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="faena_admin.php" class="btn btn-outline-dark"><i class="bi bi-gear-fill me-1"></i>Administrar</a>
    <button class="btn btn-warning fw-bold" data-bs-toggle="modal" data-bs-target="#modalNuevoGF">
      <i class="bi bi-plus-circle-fill me-1"></i>Nuevo gasto
    </button>
  </div>
</div>

<?php if (!empty($_GET['ok'])): ?>
<div class="alert alert-success alert-dismissible fade show">
  <i class="bi bi-check-circle-fill me-2"></i>
  <?= $_GET['ok']==1 ? 'Gasto registrado correctamente.' : 'Estado actualizado.' ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($msg): ?>
<div class="alert alert-<?= $msgTipo ?> alert-dismissible fade show">
  <i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($msg) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="d-flex gap-2 flex-wrap mb-3">
  <div class="stat-gf" style="background:#fff3cd;border:1px solid #fde68a">
    <div class="n text-warning">$<?= number_format($totalMonto,0,',','.') ?></div>
    <div class="l text-warning">Gastos</div>
  </div>
  <div class="stat-gf" style="background:#dbeafe;border:1px solid #bfdbfe">
    <div class="n text-primary">$<?= number_format($totalPeaje,0,',','.') ?></div>
    <div class="l text-primary">Peajes</div>
  </div>
  <div class="stat-gf" style="background:#f8f9fa;border:1px solid #dee2e6">
    <div class="n">$<?= number_format($totalGral,0,',','.') ?></div>
    <div class="l">Total</div>
  </div>
  <div class="stat-gf" style="background:#d1fae5;border:1px solid #a7f3d0">
    <div class="n text-success"><?= $cntAprobados ?></div>
    <div class="l text-success">Aprobados</div>
  </div>
  <div class="stat-gf" style="background:#fff8e1;border:1px solid #fde68a">
    <div class="n text-warning"><?= $cntPendientes ?></div>
    <div class="l text-warning">Pendientes</div>
  </div>
</div>

<!-- Gráficos -->
<?php
$porCategoria = [];
$porObra = [];
foreach ($gastos as $g) {
  $c = $g['categoria_nombre'] ?: 'Sin categoría';
  $porCategoria[$c] = ($porCategoria[$c] ?? 0) + (float)$g['monto'] + (float)$g['monto_peaje'];
  $o = $g['obra_codigo'] ?: ($g['obra_nombre'] ?: 'Sin obra');
  $porObra[$o] = ($porObra[$o] ?? 0) + (float)$g['monto'] + (float)$g['monto_peaje'];
}
arsort($porCategoria); arsort($porObra);
?>
<?php if (!empty($porCategoria)): ?>
<div class="row g-2 mb-3">
  <div class="col-md-6">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-warning text-dark fw-bold py-2"><i class="bi bi-pie-chart-fill me-1"></i>Gastos por categoría</div>
      <div class="card-body" style="position:relative;height:280px"><canvas id="chartCategoria"></canvas></div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-info text-white fw-bold py-2"><i class="bi bi-bar-chart-fill me-1"></i>Gastos por obra</div>
      <div class="card-body" style="position:relative;height:280px"><canvas id="chartObra"></canvas></div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function(){
  var dataCat = <?= json_encode($porCategoria, JSON_UNESCAPED_UNICODE) ?>;
  var dataObra= <?= json_encode($porObra, JSON_UNESCAPED_UNICODE) ?>;
  var paleta = ['#d97706','#dc2626','#16a34a','#2563eb','#a855f7','#0ea5e9','#f59e0b','#ec4899','#14b8a6','#6366f1'];
  function fmt(v) { return '$' + Number(v).toLocaleString('es-CL'); }

  if (window.Chart) {
    new Chart(document.getElementById('chartCategoria'), {
      type: 'doughnut',
      data: {
        labels: Object.keys(dataCat),
        datasets: [{ data: Object.values(dataCat), backgroundColor: paleta, borderWidth: 2, borderColor: '#fff' }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom', labels: { font: { size: 11 } } },
          tooltip: { callbacks: { label: function(ctx) { return ctx.label + ': ' + fmt(ctx.parsed); } } }
        }
      }
    });
    new Chart(document.getElementById('chartObra'), {
      type: 'bar',
      data: {
        labels: Object.keys(dataObra),
        datasets: [{ label: 'Gasto total', data: Object.values(dataObra), backgroundColor: '#2563eb' }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: function(ctx) { return fmt(ctx.parsed.y); } } }
        },
        scales: { y: { ticks: { callback: function(v) { return fmt(v); } } } }
      }
    });
  }
})();
</script>
<?php endif; ?>

<!-- Filtros -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2">
    <form method="GET" class="d-flex gap-2 flex-wrap align-items-end">
      <div>
        <label class="form-label fw-semibold mb-1 small">Mes</label>
        <input type="month" name="mes" class="form-control form-control-sm" value="<?= $filtroMes ?>">
      </div>
      <?php if (!empty($obras)): ?>
      <div>
        <label class="form-label fw-semibold mb-1 small">Obra</label>
        <select name="obra_id" class="form-select form-select-sm">
          <option value="0">Todas las obras</option>
          <?php foreach ($obras as $o): ?>
          <option value="<?= $o['id'] ?>" <?= $o['id']==$filtroObra?'selected':'' ?>><?= htmlspecialchars($o['codigo'].' — '.$o['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div>
        <label class="form-label fw-semibold mb-1 small">Estado</label>
        <select name="estado" class="form-select form-select-sm">
          <option value="">Todos</option>
          <option value="pendiente" <?= $filtroEst==='pendiente'?'selected':'' ?>>Pendiente</option>
          <option value="aprobado"  <?= $filtroEst==='aprobado'?'selected':'' ?>>Aprobado</option>
          <option value="rechazado" <?= $filtroEst==='rechazado'?'selected':'' ?>>Rechazado</option>
        </select>
      </div>
      <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Filtrar</button>
      <a href="gastos_faena_export.php?mes=<?= $filtroMes ?>&obra_id=<?= $filtroObra ?>" target="_blank"
         class="btn btn-sm btn-outline-danger"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
    </form>
  </div>
</div>

<!-- Lista -->
<?php if (empty($gastos)): ?>
<div class="text-center py-5 text-muted">
  <i class="bi bi-receipt-cutoff" style="font-size:3rem;opacity:.25;display:block;margin-bottom:12px"></i>
  <strong>Sin gastos de faena</strong> para este período.
  <div class="mt-2">
    <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#modalNuevoGF">
      <i class="bi bi-plus-circle me-1"></i>Registrar el primero
    </button>
  </div>
</div>
<?php else:
  /* Agrupar gastos por obra (obra_id + obra_nombre) */
  $gruposObra = [];
  foreach ($gastos as $g) {
    $oid = (int)($g['obra_id'] ?? 0);
    $oNom = $g['obra_nombre'] ?: 'Sin obra asignada';
    $key = $oid > 0 ? 'O'.$oid : 'X_'.$oNom;
    if (!isset($gruposObra[$key])) {
      $gruposObra[$key] = ['obra_id'=>$oid, 'obra_nombre'=>$oNom, 'total'=>0, 'qty'=>0, 'gastos'=>[]];
    }
    $gruposObra[$key]['gastos'][] = $g;
    $gruposObra[$key]['qty']++;
    $gruposObra[$key]['total'] += (float)$g['monto'] + (float)($g['monto_peaje'] ?? 0);
  }
  // Ordenar grupos por monto desc
  uasort($gruposObra, function($a,$b){ return $b['total'] <=> $a['total']; });
  $gObra_idx = 0;
  foreach ($gruposObra as $gObraKey => $gObra): $gObra_idx++;
?>
<div class="card border-0 shadow-sm mb-2">
  <button type="button" class="btn btn-light w-100 text-start d-flex align-items-center justify-content-between flex-wrap gap-2 py-2 px-3"
          data-bs-toggle="collapse" data-bs-target="#gObraBody<?= $gObra_idx ?>"
          aria-expanded="<?= $gObra_idx === 1 ? 'true' : 'false' ?>" style="font-weight:600">
    <span>
      <?php if ($gObra['obra_id']): ?>
      <span class="badge bg-primary me-2" title="ID de obra"><i class="bi bi-hash"></i><?= $gObra['obra_id'] ?></span>
      <?php endif; ?>
      <i class="bi bi-building text-warning me-1"></i><?= htmlspecialchars($gObra['obra_nombre']) ?>
    </span>
    <span class="d-flex gap-2 align-items-center">
      <span class="badge bg-secondary"><?= $gObra['qty'] ?> gasto(s)</span>
      <span class="badge bg-success">$<?= number_format($gObra['total'],0,',','.') ?></span>
      <i class="bi bi-chevron-down text-muted"></i>
    </span>
  </button>
  <div class="collapse <?= $gObra_idx === 1 ? 'show' : '' ?>" id="gObraBody<?= $gObra_idx ?>">
    <div class="p-2">
<?php foreach ($gObra['gastos'] as $g):
  $est    = $g['estado'];
  $bdgCls = ['aprobado'=>'success','rechazado'=>'danger','pendiente'=>'warning'][$est] ?? 'secondary';
  $icoEst = ['aprobado'=>'check-circle-fill','rechazado'=>'x-circle-fill','pendiente'=>'hourglass-split'][$est] ?? 'circle';
  $totalG = (float)$g['monto'] + (float)$g['monto_peaje'];
?>
<div class="gf-card <?= $est ?>">
  <div class="d-flex align-items-start gap-3 p-3">

    <!-- Miniatura archivo -->
    <?php if ($g['archivo_ruta']): ?>
      <?php $ext = strtolower(pathinfo($g['archivo_ruta'], PATHINFO_EXTENSION)); ?>
      <?php if ($ext === 'pdf'): ?>
      <a href="uploads/<?= htmlspecialchars($g['archivo_ruta']) ?>" target="_blank">
        <div class="gf-thumb-ph" style="background:#fee2e2"><i class="bi bi-file-earmark-pdf-fill text-danger"></i></div>
      </a>
      <?php else: ?>
      <img src="uploads/<?= htmlspecialchars($g['archivo_ruta']) ?>" class="gf-thumb"
           onclick="verImagen('<?= htmlspecialchars(addslashes($g['archivo_ruta'])) ?>')" alt="boleta">
      <?php endif; ?>
    <?php else: ?>
    <div class="gf-thumb-ph"><i class="bi bi-receipt"></i></div>
    <?php endif; ?>

    <!-- Contenido -->
    <div class="flex-grow-1 min-width-0">
      <div class="d-flex align-items-start justify-content-between flex-wrap gap-1 mb-1">
        <div>
          <strong><?= htmlspecialchars($g['descripcion']) ?></strong>
          <?php if ($g['categoria_nombre']): ?>
          <span class="badge bg-secondary ms-1" style="font-size:.65rem">
            <?= htmlspecialchars($g['categoria_nombre']) ?>
          </span>
          <?php endif; ?>
        </div>
        <span class="badge bg-<?= $bdgCls ?>">
          <i class="bi bi-<?= $icoEst ?> me-1"></i><?= ucfirst($est) ?>
        </span>
      </div>

      <div class="d-flex flex-wrap gap-x-3 gap-1 small text-muted mb-2">
        <span><i class="bi bi-calendar3 me-1"></i><?= date('d/m/Y', strtotime($g['fecha'])) ?></span>
        <?php if ($g['obra_nombre']): ?>
        <span><i class="bi bi-building me-1"></i><?= htmlspecialchars($g['obra_nombre']) ?></span>
        <?php endif; ?>
        <?php if ($g['proveedor']): ?>
        <span><i class="bi bi-shop me-1"></i><?= htmlspecialchars($g['proveedor']) ?></span>
        <?php endif; ?>
        <?php if ($g['nro_documento']): ?>
        <span><i class="bi bi-hash me-1"></i><?= htmlspecialchars($g['nro_documento']) ?></span>
        <?php endif; ?>
        <span><i class="bi bi-person me-1"></i><?= htmlspecialchars($g['registrado_por_nombre']) ?></span>
      </div>

      <!-- Montos -->
      <div class="d-flex align-items-center flex-wrap gap-2">
        <span class="fw-bold fs-5">$<?= number_format((float)$g['monto'],0,',','.') ?></span>
        <?php if ((float)$g['monto_peaje'] > 0): ?>
        <span class="badge bg-primary">
          <i class="bi bi-signpost-2-fill me-1"></i>Peaje $<?= number_format((float)$g['monto_peaje'],0,',','.') ?>
        </span>
        <span class="text-muted small fw-semibold">
          Total $<?= number_format($totalG,0,',','.') ?>
        </span>
        <?php endif; ?>
        <span class="badge bg-light text-dark border"><?= htmlspecialchars($g['tipo_documento'] ?? 'boleta') ?></span>
      </div>

      <?php if ($g['observacion_aprobacion']): ?>
      <div class="mt-1 small text-muted fst-italic">
        <i class="bi bi-chat-left-text me-1"></i><?= htmlspecialchars($g['observacion_aprobacion']) ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Acciones admin -->
    <?php if ($esAdmin && $est === 'pendiente'): ?>
    <div class="d-flex flex-column gap-1 flex-shrink-0">
      <button class="btn btn-sm btn-success" title="Aprobar"
        onclick="accionGasto(<?= $g['id'] ?>,'aprobar')">
        <i class="bi bi-check-lg"></i>
      </button>
      <button class="btn btn-sm btn-outline-danger" title="Rechazar"
        onclick="accionGasto(<?= $g['id'] ?>,'rechazar')">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
    </div><!-- /collapse body -->
  </div><!-- /collapse -->
</div><!-- /card grupo obra -->
<?php endforeach; /* fin grupos por obra */ ?>

<!-- Resumen total -->
<div class="card border-0 mt-2 mb-4" style="background:#1a1a2e;color:#fff;border-radius:12px">
  <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-3 py-3">
    <div>
      <div style="font-size:.72rem;opacity:.65;text-transform:uppercase;letter-spacing:.5px">
        Total período <?= $filtroMes ?>
      </div>
      <div style="font-size:1.5rem;font-weight:900">$<?= number_format($totalGral,0,',','.') ?></div>
    </div>
    <div class="d-flex gap-4 text-center">
      <div><div style="font-size:.72rem;opacity:.65">Gastos</div><strong>$<?= number_format($totalMonto,0,',','.') ?></strong></div>
      <div><div style="font-size:.72rem;opacity:.65">Peajes</div><strong>$<?= number_format($totalPeaje,0,',','.') ?></strong></div>
      <div><div style="font-size:.72rem;opacity:.65"><?= count($gastos) ?> registros</div><strong><?= $cntAprobados ?> aprobados</strong></div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ══ MODAL NUEVO GASTO ══ -->
<div class="modal fade" id="modalNuevoGF" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header text-white fw-bold" style="background:#d97706">
        <h5 class="modal-title"><i class="bi bi-receipt-cutoff me-2"></i>Nuevo Gasto de Faena</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="accion" value="crear">
        <div class="modal-body">
          <div class="row g-3">

            <div class="col-md-6">
              <label class="form-label fw-semibold">Fecha *</label>
              <input type="date" name="fecha" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold"><i class="bi bi-building me-1"></i>Obra</label>
              <select name="obra_id" class="form-select">
                <option value="">— Sin obra asignada —</option>
                <?php foreach ($obras as $o): ?>
                <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['codigo'].' — '.$o['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold">Monto gasto (CLP) *</label>
              <div class="input-group">
                <span class="input-group-text">$</span>
                <input type="text" name="monto" id="gfMonto" class="form-control"
                       inputmode="numeric" placeholder="0" required>
              </div>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold">
                <i class="bi bi-signpost-2-fill me-1 text-primary"></i>Peaje (CLP)
              </label>
              <div class="input-group">
                <span class="input-group-text">$</span>
                <input type="text" name="monto_peaje" id="gfPeaje" class="form-control"
                       inputmode="numeric" placeholder="0" value="0">
              </div>
            </div>

            <div class="col-12" id="gfTotalWrap" style="display:none">
              <div class="alert alert-info py-2 mb-0">
                <i class="bi bi-calculator me-1"></i>
                Total (gasto + peaje): <strong id="gfTotal">$0</strong>
              </div>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold">Categoría</label>
              <select name="categoria_id" class="form-select">
                <option value="">— Seleccionar —</option>
                <?php foreach ($CATEGORIAS as $id => $cat): ?>
                <option value="<?= $id ?>">
                  <?= htmlspecialchars($cat['nombre']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold">Tipo documento</label>
              <select name="tipo_documento" class="form-select">
                <option value="boleta">Boleta</option>
                <option value="factura">Factura</option>
                <option value="ticket">Ticket</option>
                <option value="peaje">Peaje (voucher)</option>
                <option value="otro">Otro</option>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold">Proveedor / Comercio</label>
              <input type="text" name="proveedor" class="form-control"
                     placeholder="Ej. Copec, Líder, Autopista...">
            </div>

            <div class="col-md-6">
              <label class="form-label fw-semibold">N° Documento</label>
              <input type="text" name="nro_documento" class="form-control"
                     placeholder="N° boleta o factura">
            </div>

            <div class="col-12">
              <label class="form-label fw-semibold">Descripción *</label>
              <textarea name="descripcion" class="form-control" rows="2"
                placeholder="¿En qué se gastó? ¿Para qué obra o actividad?" required></textarea>
            </div>

            <div class="col-12">
              <label class="form-label fw-semibold">
                <i class="bi bi-camera me-1"></i>Foto boleta / comprobante
              </label>
              <input type="file" name="archivo" class="form-control"
                     accept="image/*,application/pdf" capture="environment" id="gfArchivo">
              <div id="gfPreview" class="mt-2"></div>
            </div>

          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-warning fw-bold px-4">
            <i class="bi bi-save-fill me-1"></i>Guardar gasto
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal aprobar/rechazar -->
<div class="modal fade" id="modalAccion" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header fw-bold" id="accionHeader"></div>
      <form method="POST">
        <input type="hidden" name="accion" id="accionTipo">
        <input type="hidden" name="gasto_id" id="accionId">
        <div class="modal-body">
          <label class="form-label fw-semibold">Observación <small class="text-muted fw-normal">(opcional)</small></label>
          <textarea name="observacion" class="form-control" rows="2"
                    placeholder="Motivo, nota adicional..."></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn fw-bold" id="accionBtn">Confirmar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Lightbox imagen -->
<div class="modal fade" id="modalImg" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content bg-transparent border-0">
      <button type="button" class="btn-close btn-close-white ms-auto mb-2" data-bs-dismiss="modal"></button>
      <img id="modalImgSrc" src="" class="img-fluid rounded" style="max-height:80vh;object-fit:contain">
    </div>
  </div>
</div>

<script>
// Total en tiempo real
function parseCLP(v){ return parseFloat((v||'0').replace(/\./g,'').replace(',','.')) || 0; }
function fmtCLP(n){ return '$'+Math.round(n).toLocaleString('es-CL'); }
function calcTotal(){
  var m=parseCLP(document.getElementById('gfMonto')?.value);
  var p=parseCLP(document.getElementById('gfPeaje')?.value);
  var w=document.getElementById('gfTotalWrap');
  var v=document.getElementById('gfTotal');
  if(w) w.style.display=p>0?'block':'none';
  if(v) v.textContent=fmtCLP(m+p);
}
document.getElementById('gfMonto')?.addEventListener('input',calcTotal);
document.getElementById('gfPeaje')?.addEventListener('input',calcTotal);

// Preview foto
document.getElementById('gfArchivo')?.addEventListener('change',function(){
  var pv=document.getElementById('gfPreview'); pv.innerHTML='';
  var f=this.files[0]; if(!f||!f.type.startsWith('image/')) return;
  var r=new FileReader();
  r.onload=function(e){
    var img=document.createElement('img');
    img.src=e.target.result;
    img.style='width:100%;max-height:200px;object-fit:contain;border-radius:8px;border:1px solid #dee2e6;margin-top:4px';
    pv.appendChild(img);
  };
  r.readAsDataURL(f);
});

// Aprobar/Rechazar
function accionGasto(id, accion){
  document.getElementById('accionTipo').value=accion;
  document.getElementById('accionId').value=id;
  var h=document.getElementById('accionHeader');
  var b=document.getElementById('accionBtn');
  if(accion==='aprobar'){
    h.innerHTML='<i class="bi bi-check-circle-fill text-success me-2"></i>Aprobar gasto';
    b.className='btn btn-success fw-bold'; b.textContent='Confirmar aprobación';
  } else {
    h.innerHTML='<i class="bi bi-x-circle-fill text-danger me-2"></i>Rechazar gasto';
    b.className='btn btn-danger fw-bold'; b.textContent='Confirmar rechazo';
  }
  new bootstrap.Modal(document.getElementById('modalAccion')).show();
}

// Ver imagen boleta
function verImagen(ruta){
  document.getElementById('modalImgSrc').src='uploads/'+ruta;
  new bootstrap.Modal(document.getElementById('modalImg')).show();
}
</script>

<?php require_once 'includes/footer.php'; ?>
