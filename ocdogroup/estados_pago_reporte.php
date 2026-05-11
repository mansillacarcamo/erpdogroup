<?php
require_once 'config.php';
requireAuth();

$tiposLabels = [
  'cobro_dogroup'    => ['Cobros DOGroup',    'info',    'cobro'],
  'cobro_empresa'    => ['Cobro Empresas',    'success', 'cobro'],
  'cobro_cliente'    => ['Cobro Clientes',    'primary', 'cobro'],
  'pago_maquinaria'  => ['Pago Maquinarias',  'warning', 'pago'],
  'pago_proveedor'   => ['Pago Proveedores',  'danger',  'pago'],
];

$mesActual = $_GET['mes']  ?? date('m');
$anioAct   = $_GET['anio'] ?? date('Y');
$obraFiltro = trim($_GET['obra'] ?? '');

/* ========== EXPORT CSV CONSOLIDADO ========== */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
  $where = ["strftime('%m', fecha) = ?", "strftime('%Y', fecha) = ?"];
  $params = [str_pad($mesActual, 2, '0', STR_PAD_LEFT), $anioAct];
  if ($obraFiltro !== '') { $where[] = "(obra_codigo = ? OR obra_nombre LIKE ?)"; $params[] = $obraFiltro; $params[] = "%$obraFiltro%"; }
  $st = $pdo->prepare("SELECT * FROM estados_pago WHERE ".implode(' AND ', $where)." ORDER BY tipo, fecha");
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="reporte_edp_'.$anioAct.'-'.str_pad($mesActual,2,'0',STR_PAD_LEFT).'.csv"');
  echo "\xEF\xBB\xBF";
  $out = fopen('php://output', 'w');
  fputcsv($out, ['Tipo','Fecha','Documento','Cód.Obra','Obra','Contraparte','Descripción','Total','Estado','Vencimiento','Fecha Pago'], ';');
  foreach ($rows as $r) {
    fputcsv($out, [
      $tiposLabels[$r['tipo']][0] ?? $r['tipo'],
      $r['fecha'], $r['numero_documento'], $r['obra_codigo'], $r['obra_nombre'],
      $r['contraparte'], $r['descripcion'],
      number_format((float)$r['monto_total'], 0, ',', '.'),
      $r['estado'], $r['fecha_vencimiento'], $r['fecha_pago']
    ], ';');
  }
  fclose($out);
  exit;
}

/* ========== AGREGADOS ========== */
$where = ["strftime('%m', fecha) = ?", "strftime('%Y', fecha) = ?"];
$params = [str_pad($mesActual, 2, '0', STR_PAD_LEFT), $anioAct];
if ($obraFiltro !== '') { $where[] = "(obra_codigo = ? OR obra_nombre LIKE ?)"; $params[] = $obraFiltro; $params[] = "%$obraFiltro%"; }
$whereSQL = implode(' AND ', $where);

/* Totales por tipo y estado */
$resumen = [];
foreach ($tiposLabels as $k => $_) {
  $resumen[$k] = ['total'=>0, 'pendiente'=>0, 'listo'=>0, 'vencido'=>0, 'cantidad'=>0];
}
$st = $pdo->prepare("SELECT tipo, estado, COUNT(*) c, COALESCE(SUM(monto_total),0) s FROM estados_pago WHERE $whereSQL GROUP BY tipo, estado");
$st->execute($params);
foreach ($st as $row) {
  if (!isset($resumen[$row['tipo']])) continue;
  $resumen[$row['tipo']]['cantidad'] += (int)$row['c'];
  $resumen[$row['tipo']]['total']    += (float)$row['s'];
  if ($row['estado'] === 'pendiente' || $row['estado'] === 'parcial') $resumen[$row['tipo']]['pendiente'] += (float)$row['s'];
  elseif (in_array($row['estado'], ['pagado','cobrado']))             $resumen[$row['tipo']]['listo']     += (float)$row['s'];
  elseif ($row['estado'] === 'vencido')                                $resumen[$row['tipo']]['vencido']   += (float)$row['s'];
}

/* Por obra */
$porObra = $pdo->prepare("
  SELECT
    COALESCE(NULLIF(obra_codigo,''),'-') codigo,
    COALESCE(NULLIF(obra_nombre,''),'(sin obra)') obra,
    SUM(CASE WHEN tipo IN ('cobro_dogroup','cobro_empresa','cobro_cliente') THEN monto_total ELSE 0 END) cobros,
    SUM(CASE WHEN tipo IN ('pago_maquinaria','pago_proveedor') THEN monto_total ELSE 0 END) pagos,
    SUM(CASE WHEN estado = 'pendiente' OR estado = 'parcial' OR estado = 'vencido' THEN monto_total ELSE 0 END) pendientes,
    COUNT(*) cantidad
  FROM estados_pago
  WHERE $whereSQL
  GROUP BY obra_codigo, obra_nombre
  ORDER BY pagos DESC, cobros DESC
");
$porObra->execute($params);
$obrasResumen = $porObra->fetchAll(PDO::FETCH_ASSOC);

/* Por contraparte */
$porContra = $pdo->prepare("
  SELECT tipo, contraparte,
         COUNT(*) cantidad,
         SUM(monto_total) total,
         SUM(CASE WHEN estado IN ('pendiente','parcial','vencido') THEN monto_total ELSE 0 END) pendiente
  FROM estados_pago
  WHERE $whereSQL
  GROUP BY tipo, contraparte
  HAVING contraparte != ''
  ORDER BY total DESC
  LIMIT 30
");
$porContra->execute($params);
$contraResumen = $porContra->fetchAll(PDO::FETCH_ASSOC);

/* Lista de obras para selector */
try {
  $obrasList = $pdo->query("SELECT codigo, nombre FROM obras ORDER BY codigo")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $obrasList = []; }

require_once 'includes/header.php';

function fmtMon($m) { return '$' . number_format((float)$m, 0, ',', '.'); }
$mesesNom = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$tituloPeriodo = ($mesesNom[(int)$mesActual] ?? '?').' '.$anioAct;

$totalCobros = $resumen['cobro_dogroup']['total'] + $resumen['cobro_empresa']['total'] + $resumen['cobro_cliente']['total'];
$totalPagos  = $resumen['pago_maquinaria']['total'] + $resumen['pago_proveedor']['total'];
$saldo = $totalCobros - $totalPagos;
?>

<div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
  <div>
    <h3 class="fw-bold mb-1"><i class="bi bi-graph-up text-success me-2"></i>Reporte Mensual — Estados de Pago</h3>
    <p class="text-muted mb-0">Período: <strong><?= htmlspecialchars($tituloPeriodo) ?></strong>
      <?php if ($obraFiltro): ?> — Obra: <strong><?= htmlspecialchars($obraFiltro) ?></strong><?php endif; ?>
    </p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <a href="<?= 'estados_pago_reporte.php?' . http_build_query(array_merge($_GET, ['export'=>'csv'])) ?>" class="btn btn-outline-success">
      <i class="bi bi-file-earmark-spreadsheet me-1"></i>Exportar Excel
    </a>
    <button type="button" class="btn btn-outline-danger" onclick="window.print()">
      <i class="bi bi-printer me-1"></i>PDF / Imprimir
    </button>
    <a href="estados_pago.php" class="btn btn-dark">
      <i class="bi bi-cash-coin me-1"></i>Ver registros
    </a>
  </div>
</div>

<!-- Filtros -->
<form method="GET" class="card shadow-sm mb-3 no-print">
  <div class="card-body py-2">
    <div class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label small mb-1">Mes</label>
        <select name="mes" class="form-select form-select-sm">
          <?php for ($i=1;$i<=12;$i++):
            $sel = (int)$mesActual === $i ? 'selected' : '';
          ?>
          <option value="<?= $i ?>" <?= $sel ?>><?= $mesesNom[$i] ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-1">Año</label>
        <select name="anio" class="form-select form-select-sm">
          <?php for ($a=date('Y')+1; $a>=2023; $a--):
            $sel = (string)$anioAct === (string)$a ? 'selected' : '';
          ?>
          <option value="<?= $a ?>" <?= $sel ?>><?= $a ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label small mb-1">Obra (código o nombre)</label>
        <input type="text" name="obra" class="form-control form-control-sm" list="obrasList" value="<?= htmlspecialchars($obraFiltro) ?>" placeholder="Todas">
        <datalist id="obrasList">
          <?php foreach ($obrasList as $o): ?>
          <option value="<?= htmlspecialchars($o['codigo']) ?>"><?= htmlspecialchars($o['nombre']) ?></option>
          <?php endforeach; ?>
        </datalist>
      </div>
      <div class="col-md-3 d-flex gap-2">
        <button class="btn btn-sm btn-dark flex-grow-1"><i class="bi bi-funnel me-1"></i>Aplicar</button>
        <a href="estados_pago_reporte.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle"></i></a>
      </div>
    </div>
  </div>
</form>

<!-- KPIs -->
<div class="row g-3 mb-3">
  <div class="col-md-3 col-6">
    <div class="card border-0 shadow-sm bg-success text-white">
      <div class="card-body py-3">
        <small><i class="bi bi-arrow-down-circle me-1"></i>Total Cobros</small>
        <h4 class="mb-0 fw-bold"><?= fmtMon($totalCobros) ?></h4>
      </div>
    </div>
  </div>
  <div class="col-md-3 col-6">
    <div class="card border-0 shadow-sm bg-danger text-white">
      <div class="card-body py-3">
        <small><i class="bi bi-arrow-up-circle me-1"></i>Total Pagos</small>
        <h4 class="mb-0 fw-bold"><?= fmtMon($totalPagos) ?></h4>
      </div>
    </div>
  </div>
  <div class="col-md-3 col-6">
    <div class="card border-0 shadow-sm <?= $saldo >= 0 ? 'bg-primary' : 'bg-warning text-dark' ?>">
      <div class="card-body py-3 <?= $saldo >= 0 ? 'text-white' : '' ?>">
        <small><i class="bi bi-wallet2 me-1"></i>Saldo (Cobros − Pagos)</small>
        <h4 class="mb-0 fw-bold"><?= fmtMon($saldo) ?></h4>
      </div>
    </div>
  </div>
  <div class="col-md-3 col-6">
    <div class="card border-0 shadow-sm bg-dark text-white">
      <div class="card-body py-3">
        <small><i class="bi bi-list-check me-1"></i>Registros del período</small>
        <h4 class="mb-0 fw-bold"><?= array_sum(array_column($resumen,'cantidad')) ?></h4>
      </div>
    </div>
  </div>
</div>

<!-- Resumen por tipo -->
<div class="card shadow-sm mb-3">
  <div class="card-header bg-light"><strong><i class="bi bi-collection me-1"></i>Resumen por tipo</strong></div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>Tipo</th>
            <th class="text-end">Registros</th>
            <th class="text-end">Pendiente</th>
            <th class="text-end">Listo</th>
            <th class="text-end">Vencido</th>
            <th class="text-end">Total</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tiposLabels as $k => $info):
            $r = $resumen[$k];
          ?>
          <tr>
            <td><span class="badge bg-<?= $info[1] ?>"><?= $info[0] ?></span></td>
            <td class="text-end"><?= $r['cantidad'] ?></td>
            <td class="text-end text-warning fw-semibold"><?= fmtMon($r['pendiente']) ?></td>
            <td class="text-end text-success fw-semibold"><?= fmtMon($r['listo']) ?></td>
            <td class="text-end text-danger fw-semibold"><?= fmtMon($r['vencido']) ?></td>
            <td class="text-end fw-bold"><?= fmtMon($r['total']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="row g-3">
  <!-- Por obra -->
  <div class="col-lg-6">
    <div class="card shadow-sm h-100">
      <div class="card-header bg-light"><strong><i class="bi bi-building me-1"></i>Resumen por obra</strong></div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>Cód</th>
                <th>Obra</th>
                <th class="text-end">Cobros</th>
                <th class="text-end">Pagos</th>
                <th class="text-end">Pendiente</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($obrasResumen)): ?>
              <tr><td colspan="5" class="text-center text-muted py-3">Sin movimientos en este período</td></tr>
              <?php else: foreach ($obrasResumen as $o): ?>
              <tr>
                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($o['codigo']) ?></span></td>
                <td><small><?= htmlspecialchars($o['obra']) ?></small></td>
                <td class="text-end text-success"><?= fmtMon($o['cobros']) ?></td>
                <td class="text-end text-danger"><?= fmtMon($o['pagos']) ?></td>
                <td class="text-end text-warning fw-semibold"><?= fmtMon($o['pendientes']) ?></td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Por contraparte -->
  <div class="col-lg-6">
    <div class="card shadow-sm h-100">
      <div class="card-header bg-light"><strong><i class="bi bi-person-vcard me-1"></i>Top 30 contrapartes</strong></div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>Tipo</th>
                <th>Contraparte</th>
                <th class="text-end">N°</th>
                <th class="text-end">Total</th>
                <th class="text-end">Pendiente</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($contraResumen)): ?>
              <tr><td colspan="5" class="text-center text-muted py-3">Sin movimientos en este período</td></tr>
              <?php else: foreach ($contraResumen as $c):
                $info = $tiposLabels[$c['tipo']] ?? ['?','secondary',''];
              ?>
              <tr>
                <td><span class="badge bg-<?= $info[1] ?> small"><?= $info[0] ?></span></td>
                <td><small><?= htmlspecialchars($c['contraparte']) ?></small></td>
                <td class="text-end"><?= $c['cantidad'] ?></td>
                <td class="text-end fw-semibold"><?= fmtMon($c['total']) ?></td>
                <td class="text-end text-warning"><?= fmtMon($c['pendiente']) ?></td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once 'includes/footer.php'; ?>
