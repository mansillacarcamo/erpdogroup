<?php
require_once 'config.php';
requireAuth();

/* ===========================================================
   MÓDULO ESTADOS DE PAGO
   Tipos: cobro_empresa | cobro_cliente | pago_maquinaria | pago_proveedor
   =========================================================== */

$tiposValidos = [
  'cobro_dogroup'    => ['titulo' => 'Cobros DOGroup',     'icono' => 'bi-cash-stack',         'color' => 'info',    'verbo' => 'Cobrar', 'contraparte_label' => 'Cliente',    'sentido' => 'cobro'],
  'cobro_empresa'    => ['titulo' => 'Cobro Empresas',     'icono' => 'bi-building-fill-up',   'color' => 'success', 'verbo' => 'Cobrar', 'contraparte_label' => 'Empresa',    'sentido' => 'cobro'],
  'cobro_cliente'    => ['titulo' => 'Cobro Clientes',     'icono' => 'bi-person-check-fill',  'color' => 'primary', 'verbo' => 'Cobrar', 'contraparte_label' => 'Cliente',    'sentido' => 'cobro'],
  'pago_maquinaria'  => ['titulo' => 'Pago Maquinarias',   'icono' => 'bi-truck-flatbed',      'color' => 'warning', 'verbo' => 'Pagar',  'contraparte_label' => 'Maquinaria', 'sentido' => 'pago'],
  'pago_proveedor'   => ['titulo' => 'Pago Proveedores',   'icono' => 'bi-bank',               'color' => 'danger',  'verbo' => 'Pagar',  'contraparte_label' => 'Proveedor',  'sentido' => 'pago'],
];

$tipoActivo = $_GET['tipo'] ?? 'cobro_dogroup';
if (!isset($tiposValidos[$tipoActivo])) $tipoActivo = 'cobro_dogroup';
$cfg = $tiposValidos[$tipoActivo];

$mensaje = null;
$mensajeTipo = 'success';

/* ============= EXPORTAR CSV (Excel) ============= */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
  $filtroEstado = $_GET['estado'] ?? '';
  $filtroMes    = $_GET['mes']    ?? '';
  $filtroAnio   = $_GET['anio']   ?? '';
  $filtroBusca  = trim($_GET['q'] ?? '');
  $where = ['tipo = ?']; $params = [$tipoActivo];
  if ($filtroEstado !== '') { $where[] = 'estado = ?'; $params[] = $filtroEstado; }
  if ($filtroMes !== '')    { $where[] = "strftime('%m', fecha) = ?"; $params[] = str_pad($filtroMes, 2, '0', STR_PAD_LEFT); }
  if ($filtroAnio !== '')   { $where[] = "strftime('%Y', fecha) = ?"; $params[] = $filtroAnio; }
  if ($filtroBusca !== '')  { $where[] = '(contraparte LIKE ? OR obra_nombre LIKE ? OR numero_documento LIKE ? OR descripcion LIKE ?)';
                              $bq = "%$filtroBusca%"; array_push($params, $bq, $bq, $bq, $bq); }
  $st = $pdo->prepare("SELECT * FROM estados_pago WHERE ".implode(' AND ', $where)." ORDER BY fecha DESC, id DESC");
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $filename = 'estados_pago_'.$tipoActivo.'_'.date('Ymd_His').'.csv';
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="'.$filename.'"');
  echo "\xEF\xBB\xBF"; // BOM UTF-8 para Excel
  $out = fopen('php://output', 'w');
  fputcsv($out, ['Fecha','Documento','Cód.Obra','Obra','Contraparte','RUT','Descripción','Neto','IVA','Total','Vencimiento','Fecha Pago','Estado','Forma Pago','Observaciones','OC','Cotización','Creado por'], ';');
  foreach ($rows as $r) {
    fputcsv($out, [
      $r['fecha'], $r['numero_documento'], $r['obra_codigo'], $r['obra_nombre'],
      $r['contraparte'], $r['contraparte_rut'], $r['descripcion'],
      number_format((float)$r['monto_neto'], 0, ',', '.'),
      number_format((float)$r['iva'], 0, ',', '.'),
      number_format((float)$r['monto_total'], 0, ',', '.'),
      $r['fecha_vencimiento'], $r['fecha_pago'],
      $r['estado'], $r['forma_pago'], $r['observaciones'],
      $r['oc_id']  ? '#'.$r['oc_id']  : '',
      $r['cot_id'] ? '#'.$r['cot_id'] : '',
      $r['creado_por']
    ], ';');
  }
  fclose($out);
  exit;
}

/* ============= PRECARGA DESDE OC (?from_oc=ID) o COTIZACIÓN (?from_cot=ID) ============= */
$precarga = null;
if (!empty($_GET['from_oc'])) {
  $stOC = $pdo->prepare("SELECT * FROM ordenes_compra WHERE id = ?");
  $stOC->execute([(int)$_GET['from_oc']]);
  $ocOrigen = $stOC->fetch(PDO::FETCH_ASSOC);
  if ($ocOrigen) {
    $precarga = [
      'fecha'             => date('Y-m-d'),
      'obra_codigo'       => $ocOrigen['obra_codigo'] ?? '',
      'obra_nombre'       => $ocOrigen['obra'] ?? '',
      'contraparte'       => $ocOrigen['proveedor_nombre'] ?? '',
      'contraparte_rut'   => $ocOrigen['proveedor_rut'] ?? '',
      'numero_documento'  => 'OC '.$ocOrigen['numero'],
      'descripcion'       => 'Pago de OC N° '.$ocOrigen['numero'],
      'monto_neto'        => $ocOrigen['neto'] ?? 0,
      'iva'               => $ocOrigen['iva'] ?? 0,
      'monto_total'       => $ocOrigen['total'] ?? 0,
      'oc_id'             => $ocOrigen['id'],
      'cot_id'            => null,
      'estado'            => 'pendiente',
      'fecha_vencimiento' => '',
      'fecha_pago'        => '',
      'forma_pago'        => '',
      'observaciones'     => '',
    ];
  }
} elseif (!empty($_GET['from_cot'])) {
  $stCot = $pdo->prepare("SELECT * FROM cotizaciones WHERE id = ?");
  $stCot->execute([(int)$_GET['from_cot']]);
  $cotOrigen = $stCot->fetch(PDO::FETCH_ASSOC);
  if ($cotOrigen) {
    $netoCot = $cotOrigen['subtotal'] ?? 0;
    $ivaCot  = $cotOrigen['iva'] ?? 0;
    $totCot  = $cotOrigen['total'] ?? 0;
    $precarga = [
      'fecha'             => date('Y-m-d'),
      'obra_codigo'       => '',
      'obra_nombre'       => $cotOrigen['cliente_obra'] ?? '',
      'contraparte'       => $cotOrigen['cliente_nombre'] ?? '',
      'contraparte_rut'   => $cotOrigen['cliente_rut'] ?? '',
      'numero_documento'  => 'COT '.$cotOrigen['numero'],
      'descripcion'       => 'Cobro de Cotización N° '.$cotOrigen['numero'],
      'monto_neto'        => $netoCot,
      'iva'               => $ivaCot,
      'monto_total'       => $totCot,
      'oc_id'             => null,
      'cot_id'            => $cotOrigen['id'],
      'estado'            => 'pendiente',
      'fecha_vencimiento' => '',
      'fecha_pago'        => '',
      'forma_pago'        => '',
      'observaciones'     => '',
    ];
  }
}

/* ============= ACCIONES (CRUD) ============= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $accion = $_POST['accion'] ?? '';
  try {
    if ($accion === 'crear' || $accion === 'editar') {
      $tipo = $_POST['tipo'] ?? $tipoActivo;
      if (!isset($tiposValidos[$tipo])) throw new Exception('Tipo inválido');

      $datos = [
        'fecha'             => $_POST['fecha'] ?? date('Y-m-d'),
        'obra_codigo'       => trim($_POST['obra_codigo'] ?? ''),
        'obra_nombre'       => trim($_POST['obra_nombre'] ?? ''),
        'contraparte'       => trim($_POST['contraparte'] ?? ''),
        'contraparte_rut'   => trim($_POST['contraparte_rut'] ?? ''),
        'numero_documento'  => trim($_POST['numero_documento'] ?? ''),
        'descripcion'       => trim($_POST['descripcion'] ?? ''),
        'monto_neto'        => (float)str_replace(['.', ','], ['', '.'], $_POST['monto_neto'] ?? '0'),
        'iva'               => (float)str_replace(['.', ','], ['', '.'], $_POST['iva'] ?? '0'),
        'monto_total'       => (float)str_replace(['.', ','], ['', '.'], $_POST['monto_total'] ?? '0'),
        'fecha_vencimiento' => $_POST['fecha_vencimiento'] ?? null,
        'fecha_pago'        => $_POST['fecha_pago'] ?? null,
        'estado'            => $_POST['estado'] ?? 'pendiente',
        'forma_pago'        => trim($_POST['forma_pago'] ?? ''),
        'observaciones'     => trim($_POST['observaciones'] ?? ''),
      ];
      if ($datos['monto_total'] <= 0 && $datos['monto_neto'] > 0) {
        $datos['iva'] = round($datos['monto_neto'] * 0.19);
        $datos['monto_total'] = $datos['monto_neto'] + $datos['iva'];
      }

      $datos['oc_id']  = !empty($_POST['oc_id'])  ? (int)$_POST['oc_id']  : null;
      $datos['cot_id'] = !empty($_POST['cot_id']) ? (int)$_POST['cot_id'] : null;

      if ($accion === 'crear') {
        $pdo->prepare("INSERT INTO estados_pago
          (tipo, fecha, obra_codigo, obra_nombre, contraparte, contraparte_rut, numero_documento,
           descripcion, monto_neto, iva, monto_total, fecha_vencimiento, fecha_pago,
           estado, forma_pago, observaciones, oc_id, cot_id, creado_por)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
          ->execute([
            $tipo, $datos['fecha'], $datos['obra_codigo'], $datos['obra_nombre'], $datos['contraparte'],
            $datos['contraparte_rut'], $datos['numero_documento'], $datos['descripcion'],
            $datos['monto_neto'], $datos['iva'], $datos['monto_total'],
            $datos['fecha_vencimiento'] ?: null, $datos['fecha_pago'] ?: null,
            $datos['estado'], $datos['forma_pago'], $datos['observaciones'],
            $datos['oc_id'], $datos['cot_id'], $usuario['nombre']
          ]);
        $mensaje = 'Registro creado exitosamente';
      } else {
        $idEdit = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE estados_pago SET
          fecha=?, obra_codigo=?, obra_nombre=?, contraparte=?, contraparte_rut=?, numero_documento=?,
          descripcion=?, monto_neto=?, iva=?, monto_total=?, fecha_vencimiento=?, fecha_pago=?,
          estado=?, forma_pago=?, observaciones=?, oc_id=?, cot_id=? WHERE id=?")
          ->execute([
            $datos['fecha'], $datos['obra_codigo'], $datos['obra_nombre'], $datos['contraparte'],
            $datos['contraparte_rut'], $datos['numero_documento'], $datos['descripcion'],
            $datos['monto_neto'], $datos['iva'], $datos['monto_total'],
            $datos['fecha_vencimiento'] ?: null, $datos['fecha_pago'] ?: null,
            $datos['estado'], $datos['forma_pago'], $datos['observaciones'],
            $datos['oc_id'], $datos['cot_id'], $idEdit
          ]);
        $mensaje = 'Registro actualizado exitosamente';
      }
    } elseif ($accion === 'eliminar') {
      $idDel = (int)($_POST['id'] ?? 0);
      $pdo->prepare("DELETE FROM estados_pago WHERE id = ?")->execute([$idDel]);
      $mensaje = 'Registro eliminado';
    } elseif ($accion === 'marcar_pagado') {
      $idM = (int)($_POST['id'] ?? 0);
      $pdo->prepare("UPDATE estados_pago SET estado=?, fecha_pago=? WHERE id=?")
        ->execute([$cfg['sentido'] === 'cobro' ? 'cobrado' : 'pagado', date('Y-m-d'), $idM]);
      $mensaje = $cfg['sentido'] === 'cobro' ? 'Marcado como cobrado' : 'Marcado como pagado';
    }
  } catch (Exception $e) {
    $mensaje = 'Error: ' . $e->getMessage();
    $mensajeTipo = 'danger';
  }
  header("Location: estados_pago.php?tipo=$tipoActivo&msg=" . urlencode($mensaje) . "&mt=$mensajeTipo");
  exit;
}

if (!empty($_GET['msg'])) {
  $mensaje = $_GET['msg'];
  $mensajeTipo = $_GET['mt'] ?? 'success';
}

/* ============= FILTROS ============= */
$filtroEstado = $_GET['estado'] ?? '';
$filtroMes    = $_GET['mes']    ?? '';
$filtroAnio   = $_GET['anio']   ?? '';
$filtroBusca  = trim($_GET['q'] ?? '');

$where = ['tipo = ?'];
$params = [$tipoActivo];
if ($filtroEstado !== '') { $where[] = 'estado = ?'; $params[] = $filtroEstado; }
if ($filtroMes !== '')    { $where[] = "strftime('%m', fecha) = ?"; $params[] = str_pad($filtroMes, 2, '0', STR_PAD_LEFT); }
if ($filtroAnio !== '')   { $where[] = "strftime('%Y', fecha) = ?"; $params[] = $filtroAnio; }
if ($filtroBusca !== '')  { $where[] = '(contraparte LIKE ? OR obra_nombre LIKE ? OR numero_documento LIKE ? OR descripcion LIKE ?)';
                            $bq = "%$filtroBusca%"; array_push($params, $bq, $bq, $bq, $bq); }

$sql = "SELECT * FROM estados_pago WHERE ".implode(' AND ', $where)." ORDER BY fecha DESC, id DESC";
$st = $pdo->prepare($sql);
$st->execute($params);
$registros = $st->fetchAll(PDO::FETCH_ASSOC);

/* Totales */
$totPendiente = 0; $totListo = 0; $totVencido = 0; $cantidad = count($registros);
foreach ($registros as $r) {
  if ($r['estado'] === 'pendiente') $totPendiente += $r['monto_total'];
  elseif (in_array($r['estado'], ['pagado','cobrado'])) $totListo += $r['monto_total'];
  elseif ($r['estado'] === 'vencido') $totVencido += $r['monto_total'];
}

/* Para selector de obras y contrapartes */
try {
  $obrasList = $pdo->query("SELECT codigo, nombre FROM obras WHERE estado='activa' ORDER BY codigo")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $obrasList = []; }

try {
  if ($cfg['sentido'] === 'pago') {
    $contraList = $pdo->query("SELECT nombre, rut FROM proveedores ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
  } else {
    $contraList = $pdo->query("SELECT nombre, rut FROM clientes ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
  }
} catch (Exception $e) { $contraList = []; }

/* Lista de OCs y Cotizaciones disponibles para vincular (opcional, en todos los tipos) */
$ocsDisponibles = [];
try {
  $ocsDisponibles = $pdo->query("SELECT id, numero, proveedor_nombre, obra, total, fecha
                                 FROM ordenes_compra
                                 ORDER BY fecha DESC, id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $ocsDisponibles = []; }

$cotsDisponibles = [];
try {
  $cotsDisponibles = $pdo->query("SELECT id, numero, cliente_nombre, cliente_obra, cliente_rut, total, fecha
                                  FROM cotizaciones
                                  ORDER BY fecha DESC, id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $cotsDisponibles = []; }

/* Editar */
$registroEditar = null;
if (!empty($_GET['edit'])) {
  $stE = $pdo->prepare("SELECT * FROM estados_pago WHERE id = ?");
  $stE->execute([(int)$_GET['edit']]);
  $registroEditar = $stE->fetch(PDO::FETCH_ASSOC);
}

if ($precarga && !$registroEditar) {
  $registroEditar = $precarga;
}

$mostrarForm = $registroEditar || isset($_GET['nuevo']) || $precarga;

require_once 'includes/header.php';

function fmtMon($m) { return '$' . number_format((float)$m, 0, ',', '.'); }
function badgeEstado($e) {
  $map = [
    'pendiente' => ['warning', 'Pendiente', 'hourglass-split'],
    'pagado'    => ['success', 'Pagado',    'check-circle-fill'],
    'cobrado'   => ['success', 'Cobrado',   'check-circle-fill'],
    'vencido'   => ['danger',  'Vencido',   'exclamation-triangle-fill'],
    'anulado'   => ['secondary','Anulado',  'x-circle'],
    'parcial'   => ['info',    'Parcial',   'pie-chart-fill'],
  ];
  $m = $map[$e] ?? ['secondary', ucfirst($e), 'circle'];
  return '<span class="badge bg-'.$m[0].'"><i class="bi bi-'.$m[2].' me-1"></i>'.$m[1].'</span>';
}
?>

<div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
  <div>
    <h3 class="fw-bold mb-1"><i class="bi bi-cash-coin text-success me-2"></i>Estados de Pago</h3>
    <p class="text-muted mb-0">Gestión de cobros y pagos por obra y mes</p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <a href="estados_pago_reporte.php" class="btn btn-outline-dark">
      <i class="bi bi-graph-up me-1"></i>Reporte mensual
    </a>
    <?php
      $exportParams = $_GET;
      $exportParams['export'] = 'csv';
      $exportUrl = 'estados_pago.php?' . http_build_query($exportParams);
    ?>
    <a href="<?= htmlspecialchars($exportUrl) ?>" class="btn btn-outline-success">
      <i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel
    </a>
    <button type="button" class="btn btn-outline-danger" onclick="window.print()">
      <i class="bi bi-printer me-1"></i>PDF / Imprimir
    </button>
    <a href="estados_pago.php?tipo=<?= $tipoActivo ?>&nuevo=1" class="btn btn-success">
      <i class="bi bi-plus-circle me-1"></i>Nuevo registro
    </a>
  </div>
</div>

<?php if ($mensaje): ?>
<div class="alert alert-<?= htmlspecialchars($mensajeTipo) ?> alert-dismissible fade show" role="alert">
  <?= htmlspecialchars($mensaje) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Sub-menú de tipos -->
<ul class="nav nav-pills estado-pago-nav mb-3 flex-wrap gap-1">
  <?php foreach ($tiposValidos as $k => $v):
    $active = $k === $tipoActivo ? 'active' : '';
  ?>
  <li class="nav-item">
    <a class="nav-link <?= $active ?> ep-tab ep-tab-<?= $v['color'] ?>"
       href="estados_pago.php?tipo=<?= $k ?>">
      <i class="bi <?= $v['icono'] ?> me-1"></i><?= $v['titulo'] ?>
    </a>
  </li>
  <?php endforeach; ?>
</ul>

<!-- Resumen -->
<div class="row g-3 mb-3">
  <div class="col-md-3 col-6">
    <div class="card border-0 shadow-sm">
      <div class="card-body py-3">
        <small class="text-muted">Registros</small>
        <h4 class="mb-0 fw-bold"><?= $cantidad ?></h4>
      </div>
    </div>
  </div>
  <div class="col-md-3 col-6">
    <div class="card border-warning border-2 shadow-sm">
      <div class="card-body py-3">
        <small class="text-warning">Pendiente</small>
        <h4 class="mb-0 fw-bold"><?= fmtMon($totPendiente) ?></h4>
      </div>
    </div>
  </div>
  <div class="col-md-3 col-6">
    <div class="card border-success border-2 shadow-sm">
      <div class="card-body py-3">
        <small class="text-success"><?= $cfg['sentido'] === 'cobro' ? 'Cobrado' : 'Pagado' ?></small>
        <h4 class="mb-0 fw-bold"><?= fmtMon($totListo) ?></h4>
      </div>
    </div>
  </div>
  <div class="col-md-3 col-6">
    <div class="card border-danger border-2 shadow-sm">
      <div class="card-body py-3">
        <small class="text-danger">Vencido</small>
        <h4 class="mb-0 fw-bold"><?= fmtMon($totVencido) ?></h4>
      </div>
    </div>
  </div>
</div>

<?php if ($mostrarForm): ?>
<!-- FORMULARIO -->
<div class="card shadow-sm mb-4">
  <div class="card-header bg-<?= $cfg['color'] ?> text-white">
    <h5 class="mb-0"><i class="bi bi-pencil-square me-2"></i><?= $registroEditar ? 'Editar' : 'Nuevo' ?> — <?= $cfg['titulo'] ?></h5>
  </div>
  <div class="card-body">
    <form method="POST" action="estados_pago.php?tipo=<?= $tipoActivo ?>" class="row g-3">
      <input type="hidden" name="accion" value="<?= $registroEditar ? 'editar' : 'crear' ?>">
      <input type="hidden" name="tipo" value="<?= $tipoActivo ?>">
      <?php if ($registroEditar): ?>
      <input type="hidden" name="id" value="<?= $registroEditar['id'] ?>">
      <?php endif; ?>

      <div class="col-md-3">
        <label class="form-label fw-semibold">Fecha *</label>
        <input type="date" name="fecha" class="form-control" required value="<?= htmlspecialchars($registroEditar['fecha'] ?? date('Y-m-d')) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Documento N°</label>
        <input type="text" name="numero_documento" class="form-control" placeholder="Factura / EDP / Boleta" value="<?= htmlspecialchars($registroEditar['numero_documento'] ?? '') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Vencimiento</label>
        <input type="date" name="fecha_vencimiento" class="form-control" value="<?= htmlspecialchars($registroEditar['fecha_vencimiento'] ?? '') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Estado</label>
        <select name="estado" class="form-select">
          <?php
            $estadoActual = $registroEditar['estado'] ?? 'pendiente';
            $opcionesEstado = $cfg['sentido'] === 'cobro'
              ? ['pendiente'=>'Pendiente','cobrado'=>'Cobrado','parcial'=>'Parcial','vencido'=>'Vencido','anulado'=>'Anulado']
              : ['pendiente'=>'Pendiente','pagado'=>'Pagado','parcial'=>'Parcial','vencido'=>'Vencido','anulado'=>'Anulado'];
            foreach ($opcionesEstado as $k=>$lab) {
              $sel = $k === $estadoActual ? 'selected' : '';
              echo '<option value="'.$k.'" '.$sel.'>'.$lab.'</option>';
            }
          ?>
        </select>
      </div>

      <div class="col-12">
        <div class="alert alert-light border mb-0 py-2 px-3">
          <small class="text-muted d-block mb-2">
            <i class="bi bi-link-45deg me-1"></i>
            <strong>Asociar documento (opcional)</strong> — Selecciona una OC <em>o</em> una Cotización existente para autocompletar los datos. Puedes dejar ambos vacíos.
          </small>
          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label small fw-semibold mb-1 text-primary">
                <i class="bi bi-receipt me-1"></i>Orden de Compra
              </label>
              <select name="oc_id" id="ep_oc_id" class="form-select form-select-sm">
                <option value="">— Sin vincular —</option>
                <?php foreach ($ocsDisponibles as $o):
                  $sel = (!empty($registroEditar['oc_id']) && (int)$registroEditar['oc_id'] === (int)$o['id']) ? 'selected' : '';
                ?>
                <option value="<?= $o['id'] ?>"
                        data-numero="<?= htmlspecialchars($o['numero']) ?>"
                        data-prov="<?= htmlspecialchars($o['proveedor_nombre']) ?>"
                        data-obra="<?= htmlspecialchars($o['obra']) ?>"
                        data-total="<?= (float)$o['total'] ?>"
                        <?= $sel ?>>
                  OC N° <?= htmlspecialchars($o['numero']) ?> — <?= htmlspecialchars($o['proveedor_nombre'] ?: 's/proveedor') ?> — $<?= number_format((float)$o['total'], 0, ',', '.') ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold mb-1 text-success">
                <i class="bi bi-file-earmark-text me-1"></i>Cotización
              </label>
              <select name="cot_id" id="ep_cot_id" class="form-select form-select-sm">
                <option value="">— Sin vincular —</option>
                <?php foreach ($cotsDisponibles as $c):
                  $sel = (!empty($registroEditar['cot_id']) && (int)$registroEditar['cot_id'] === (int)$c['id']) ? 'selected' : '';
                ?>
                <option value="<?= $c['id'] ?>"
                        data-numero="<?= htmlspecialchars($c['numero']) ?>"
                        data-cliente="<?= htmlspecialchars($c['cliente_nombre']) ?>"
                        data-rut="<?= htmlspecialchars($c['cliente_rut'] ?? '') ?>"
                        data-obra="<?= htmlspecialchars($c['cliente_obra']) ?>"
                        data-total="<?= (float)$c['total'] ?>"
                        <?= $sel ?>>
                  COT N° <?= htmlspecialchars($c['numero']) ?> — <?= htmlspecialchars($c['cliente_nombre']) ?> — $<?= number_format((float)$c['total'], 0, ',', '.') ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
      </div>

      <div class="col-md-2">
        <label class="form-label fw-semibold">Cód. Obra</label>
        <input type="text" name="obra_codigo" id="ep_obra_codigo" class="form-control" value="<?= htmlspecialchars($registroEditar['obra_codigo'] ?? '') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Obra</label>
        <input type="text" name="obra_nombre" id="ep_obra_nombre" class="form-control" list="ep_listaObras" value="<?= htmlspecialchars($registroEditar['obra_nombre'] ?? '') ?>">
        <datalist id="ep_listaObras">
          <?php foreach ($obrasList as $o): ?>
          <option data-codigo="<?= htmlspecialchars($o['codigo']) ?>" value="<?= htmlspecialchars($o['nombre']) ?>"><?= htmlspecialchars($o['codigo']) ?></option>
          <?php endforeach; ?>
        </datalist>
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold"><?= $cfg['contraparte_label'] ?> *</label>
        <input type="text" name="contraparte" id="ep_contraparte" class="form-control" required list="ep_listaContra" value="<?= htmlspecialchars($registroEditar['contraparte'] ?? '') ?>">
        <datalist id="ep_listaContra">
          <?php foreach ($contraList as $c): ?>
          <option data-rut="<?= htmlspecialchars($c['rut'] ?? '') ?>" value="<?= htmlspecialchars($c['nombre']) ?>"></option>
          <?php endforeach; ?>
        </datalist>
      </div>
      <div class="col-md-2">
        <label class="form-label fw-semibold">RUT</label>
        <input type="text" name="contraparte_rut" id="ep_contraparte_rut" class="form-control" value="<?= htmlspecialchars($registroEditar['contraparte_rut'] ?? '') ?>">
      </div>

      <div class="col-12">
        <label class="form-label fw-semibold">Descripción</label>
        <input type="text" name="descripcion" class="form-control" value="<?= htmlspecialchars($registroEditar['descripcion'] ?? '') ?>">
      </div>

      <div class="col-md-3">
        <label class="form-label fw-semibold">Monto Neto</label>
        <input type="text" name="monto_neto" id="ep_neto" class="form-control text-end" value="<?= number_format((float)($registroEditar['monto_neto'] ?? 0), 0, ',', '.') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">IVA</label>
        <input type="text" name="iva" id="ep_iva" class="form-control text-end" value="<?= number_format((float)($registroEditar['iva'] ?? 0), 0, ',', '.') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Total</label>
        <input type="text" name="monto_total" id="ep_total" class="form-control text-end fw-bold" value="<?= number_format((float)($registroEditar['monto_total'] ?? 0), 0, ',', '.') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Fecha de <?= $cfg['sentido'] === 'cobro' ? 'cobro' : 'pago' ?></label>
        <input type="date" name="fecha_pago" class="form-control" value="<?= htmlspecialchars($registroEditar['fecha_pago'] ?? '') ?>">
      </div>

      <div class="col-md-4">
        <label class="form-label fw-semibold">Forma de pago</label>
        <select name="forma_pago" class="form-select">
          <?php
            $fp = $registroEditar['forma_pago'] ?? '';
            foreach (['','Transferencia','Cheque','Efectivo','Tarjeta','Otro'] as $opt) {
              $sel = $opt === $fp ? 'selected' : '';
              $lab = $opt === '' ? '— Seleccionar —' : $opt;
              echo '<option value="'.htmlspecialchars($opt).'" '.$sel.'>'.htmlspecialchars($lab).'</option>';
            }
          ?>
        </select>
      </div>
      <div class="col-md-8">
        <label class="form-label fw-semibold">Observaciones</label>
        <input type="text" name="observaciones" class="form-control" value="<?= htmlspecialchars($registroEditar['observaciones'] ?? '') ?>">
      </div>

      <div class="col-12 d-flex gap-2">
        <button type="submit" class="btn btn-<?= $cfg['color'] ?>"><i class="bi bi-save me-1"></i><?= $registroEditar ? 'Guardar cambios' : 'Crear registro' ?></button>
        <a href="estados_pago.php?tipo=<?= $tipoActivo ?>" class="btn btn-outline-secondary"><i class="bi bi-x-circle me-1"></i>Cancelar</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- FILTROS -->
<form method="GET" class="card shadow-sm mb-3">
  <input type="hidden" name="tipo" value="<?= $tipoActivo ?>">
  <div class="card-body py-2">
    <div class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label small mb-1">Buscar</label>
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Nombre, documento, descripción..." value="<?= htmlspecialchars($filtroBusca) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-1">Estado</label>
        <select name="estado" class="form-select form-select-sm">
          <option value="">— Todos —</option>
          <?php foreach (['pendiente','pagado','cobrado','parcial','vencido','anulado'] as $e):
            $sel = $e === $filtroEstado ? 'selected' : '';
          ?>
          <option value="<?= $e ?>" <?= $sel ?>><?= ucfirst($e) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-1">Mes</label>
        <select name="mes" class="form-select form-select-sm">
          <option value="">Todos</option>
          <?php
            $mesesNom = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
            for ($i=1;$i<=12;$i++) {
              $sel = (int)$filtroMes === $i ? 'selected' : '';
              echo '<option value="'.$i.'" '.$sel.'>'.$mesesNom[$i].'</option>';
            }
          ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-1">Año</label>
        <select name="anio" class="form-select form-select-sm">
          <option value="">Todos</option>
          <?php for ($a=date('Y')+1; $a>=2023; $a--):
            $sel = (string)$filtroAnio === (string)$a ? 'selected' : '';
          ?>
          <option value="<?= $a ?>" <?= $sel ?>><?= $a ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-md-3 d-flex gap-2">
        <button class="btn btn-sm btn-dark flex-grow-1"><i class="bi bi-funnel me-1"></i>Filtrar</button>
        <a href="estados_pago.php?tipo=<?= $tipoActivo ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle"></i></a>
      </div>
    </div>
  </div>
</form>

<!-- TABLA -->
<div class="card shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 table-estados-pago align-middle">
        <thead class="table-light">
          <tr>
            <th>Fecha</th>
            <th>Doc N°</th>
            <th><?= $cfg['contraparte_label'] ?></th>
            <th>Obra</th>
            <th>Doc. asoc.</th>
            <th>Descripción</th>
            <th class="text-end">Total</th>
            <th>Vence</th>
            <th>Estado</th>
            <th class="text-end no-print">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($registros)): ?>
          <tr><td colspan="10" class="text-center text-muted py-4">
            <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
            No hay registros. Crea el primero con el botón <em>Nuevo registro</em>.
          </td></tr>
          <?php else: foreach ($registros as $r): ?>
          <tr>
            <td><small><?= htmlspecialchars(date('d-m-Y', strtotime($r['fecha']))) ?></small></td>
            <td><strong><?= htmlspecialchars($r['numero_documento'] ?: '—') ?></strong></td>
            <td>
              <?= htmlspecialchars($r['contraparte']) ?>
              <?php if (!empty($r['contraparte_rut'])): ?><br><small class="text-muted"><?= htmlspecialchars($r['contraparte_rut']) ?></small><?php endif; ?>
            </td>
            <td>
              <?php if ($r['obra_codigo']): ?>
                <span class="badge bg-light text-dark border"><?= htmlspecialchars($r['obra_codigo']) ?></span>
              <?php endif; ?>
              <small><?= htmlspecialchars($r['obra_nombre']) ?></small>
            </td>
            <td>
              <?php if (!empty($r['oc_id'])): ?>
                <a href="ver.php?id=<?= (int)$r['oc_id'] ?>" target="_blank" class="badge bg-primary text-decoration-none mb-1" title="Abrir OC">
                  <i class="bi bi-receipt"></i> OC
                </a><br>
              <?php endif; ?>
              <?php if (!empty($r['cot_id'])): ?>
                <a href="cotizaciones/ver.php?id=<?= (int)$r['cot_id'] ?>" target="_blank" class="badge bg-success text-decoration-none" title="Abrir Cotización">
                  <i class="bi bi-file-earmark-text"></i> COT
                </a>
              <?php endif; ?>
              <?php if (empty($r['oc_id']) && empty($r['cot_id'])): ?><small class="text-muted">—</small><?php endif; ?>
            </td>
            <td><small><?= htmlspecialchars($r['descripcion']) ?></small></td>
            <td class="text-end fw-bold"><?= fmtMon($r['monto_total']) ?></td>
            <td><small class="text-muted"><?= $r['fecha_vencimiento'] ? htmlspecialchars(date('d-m-Y', strtotime($r['fecha_vencimiento']))) : '—' ?></small></td>
            <td><?= badgeEstado($r['estado']) ?></td>
            <td class="text-end no-print">
              <?php if (in_array($r['estado'], ['pendiente','parcial','vencido'])): ?>
              <form method="POST" action="estados_pago.php?tipo=<?= $tipoActivo ?>" class="d-inline">
                <input type="hidden" name="accion" value="marcar_pagado">
                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                <button class="btn btn-sm btn-outline-success" title="Marcar como <?= $cfg['sentido'] === 'cobro' ? 'cobrado' : 'pagado' ?>"
                        onclick="return confirm('¿Marcar este registro como <?= $cfg['sentido'] === 'cobro' ? 'cobrado' : 'pagado' ?>?')">
                  <i class="bi bi-check2-circle"></i>
                </button>
              </form>
              <?php endif; ?>
              <a href="estados_pago.php?tipo=<?= $tipoActivo ?>&edit=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary" title="Editar"><i class="bi bi-pencil"></i></a>
              <form method="POST" action="estados_pago.php?tipo=<?= $tipoActivo ?>" class="d-inline" onsubmit="return confirm('¿Eliminar este registro? Esta acción no se puede deshacer.')">
                <input type="hidden" name="accion" value="eliminar">
                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" title="Eliminar"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
(function(){
  // Auto-cálculo de IVA y Total
  var neto = document.getElementById('ep_neto');
  var iva = document.getElementById('ep_iva');
  var total = document.getElementById('ep_total');
  function parseMon(s){ return parseFloat(String(s||'').replace(/\./g,'').replace(',', '.')) || 0; }
  function fmt(n){ return Math.round(n).toLocaleString('es-CL'); }
  if (neto) {
    neto.addEventListener('input', function(){
      var n = parseMon(neto.value);
      var ivaVal = Math.round(n * 0.19);
      iva.value = fmt(ivaVal);
      total.value = fmt(n + ivaVal);
    });
  }
  // Auto-rellenar código obra al elegir
  var inObra = document.getElementById('ep_obra_nombre');
  var inCod = document.getElementById('ep_obra_codigo');
  var dlObra = document.getElementById('ep_listaObras');
  if (inObra && dlObra) {
    inObra.addEventListener('change', function(){
      var v = inObra.value.trim().toLowerCase();
      Array.prototype.forEach.call(dlObra.options, function(opt){
        if (opt.value.trim().toLowerCase() === v) inCod.value = opt.getAttribute('data-codigo') || '';
      });
    });
  }
  // Auto-rellenar RUT al elegir contraparte
  var inCon = document.getElementById('ep_contraparte');
  var inRut = document.getElementById('ep_contraparte_rut');
  var dlCon = document.getElementById('ep_listaContra');
  if (inCon && dlCon) {
    inCon.addEventListener('change', function(){
      var v = inCon.value.trim().toLowerCase();
      Array.prototype.forEach.call(dlCon.options, function(opt){
        if (opt.value.trim().toLowerCase() === v) inRut.value = opt.getAttribute('data-rut') || '';
      });
    });
  }
  function setVal(id, val){ var el = document.getElementById(id); if (el) el.value = val; }
  function setIfEmpty(id, val){ var el = document.getElementById(id); if (el && !el.value) el.value = val; }
  function applyMontos(totalNum){
    var ivaNum = Math.round(totalNum / 1.19 * 0.19);
    var netoNum = totalNum - ivaNum;
    setVal('ep_neto', netoNum.toLocaleString('es-CL'));
    setVal('ep_iva', ivaNum.toLocaleString('es-CL'));
    setVal('ep_total', totalNum.toLocaleString('es-CL'));
  }

  var selOC  = document.getElementById('ep_oc_id');
  var selCOT = document.getElementById('ep_cot_id');

  if (selOC) {
    selOC.addEventListener('change', function(){
      var opt = selOC.options[selOC.selectedIndex];
      if (!opt || !opt.value) return;
      if (selCOT) selCOT.value = '';
      applyMontos(parseFloat(opt.getAttribute('data-total')) || 0);
      setIfEmpty('ep_contraparte', opt.getAttribute('data-prov') || '');
      setIfEmpty('ep_obra_nombre', opt.getAttribute('data-obra') || '');
      var docInput = document.querySelector('input[name="numero_documento"]');
      if (docInput && !docInput.value) docInput.value = 'OC ' + (opt.getAttribute('data-numero') || '');
    });
  }

  if (selCOT) {
    selCOT.addEventListener('change', function(){
      var opt = selCOT.options[selCOT.selectedIndex];
      if (!opt || !opt.value) return;
      if (selOC) selOC.value = '';
      applyMontos(parseFloat(opt.getAttribute('data-total')) || 0);
      setIfEmpty('ep_contraparte', opt.getAttribute('data-cliente') || '');
      setIfEmpty('ep_contraparte_rut', opt.getAttribute('data-rut') || '');
      setIfEmpty('ep_obra_nombre', opt.getAttribute('data-obra') || '');
      var docInput = document.querySelector('input[name="numero_documento"]');
      if (docInput && !docInput.value) docInput.value = 'COT ' + (opt.getAttribute('data-numero') || '');
    });
  }
})();
</script>

<?php require_once 'includes/footer.php'; ?>
