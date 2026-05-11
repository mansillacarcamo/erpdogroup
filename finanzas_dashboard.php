<?php
require_once 'config.php';
requireAuth();

/* Filtros de período */
$mesAct  = $_GET['mes']  ?? date('m');
$anioAct = $_GET['anio'] ?? date('Y');
$ini = sprintf('%04d-%02d-01', (int)$anioAct, (int)$mesAct);
$fin = date('Y-m-t', strtotime($ini));

/* Lista de obras (para selector y nombres) */
try { $obras = $pdo->query("SELECT id, codigo, nombre, mandante FROM obras ORDER BY codigo")->fetchAll(PDO::FETCH_ASSOC); }
catch(Exception $e) { $obras = []; }

/* ════════════ INGRESOS — facturas emitidas (cobro_*) ════════════ */
function tot($pdo, $sql, $p = []) {
  $s = $pdo->prepare($sql); $s->execute($p);
  return (float)$s->fetchColumn();
}

$ingMes = tot($pdo, "SELECT COALESCE(SUM(monto_total),0) FROM estados_pago WHERE tipo LIKE 'cobro%' AND estado != 'anulado' AND fecha BETWEEN ? AND ?", [$ini,$fin]);
$ingAnio = tot($pdo, "SELECT COALESCE(SUM(monto_total),0) FROM estados_pago WHERE tipo LIKE 'cobro%' AND estado != 'anulado' AND strftime('%Y',fecha)=?", [(string)$anioAct]);
$ingTotal = tot($pdo, "SELECT COALESCE(SUM(monto_total),0) FROM estados_pago WHERE tipo LIKE 'cobro%' AND estado != 'anulado'");
$ingPendiente = tot($pdo, "SELECT COALESCE(SUM(monto_total),0) FROM estados_pago WHERE tipo LIKE 'cobro%' AND estado='pendiente'");
$ingCobrado = tot($pdo, "SELECT COALESCE(SUM(monto_total),0) FROM estados_pago WHERE tipo LIKE 'cobro%' AND estado='cobrado'");

/* ════════════ EGRESOS ════════════ */
// OC del sistema (servicios, materiales, arriendos)
$egOcMes = tot($pdo, "SELECT COALESCE(SUM(total),0) FROM ordenes_compra WHERE fecha BETWEEN ? AND ?", [$ini,$fin]);
$egOcAnio = tot($pdo, "SELECT COALESCE(SUM(total),0) FROM ordenes_compra WHERE strftime('%Y',fecha)=?", [(string)$anioAct]);
$egOcTotal = tot($pdo, "SELECT COALESCE(SUM(total),0) FROM ordenes_compra");

// Pagos a proveedores via estados_pago (tipo pago_*)
$egPagosMes = tot($pdo, "SELECT COALESCE(SUM(monto_total),0) FROM estados_pago WHERE tipo LIKE 'pago%' AND estado != 'anulado' AND fecha BETWEEN ? AND ?", [$ini,$fin]);
$egPagosAnio = tot($pdo, "SELECT COALESCE(SUM(monto_total),0) FROM estados_pago WHERE tipo LIKE 'pago%' AND estado != 'anulado' AND strftime('%Y',fecha)=?", [(string)$anioAct]);

// Gastos por obra (ex-gastos_faena)
$egGastosMes = tot($pdo, "SELECT COALESCE(SUM(monto + COALESCE(monto_peaje,0)),0) FROM gastos_faena WHERE fecha BETWEEN ? AND ?", [$ini,$fin]);
$egGastosAnio = tot($pdo, "SELECT COALESCE(SUM(monto + COALESCE(monto_peaje,0)),0) FROM gastos_faena WHERE strftime('%Y',fecha)=?", [(string)$anioAct]);
$egGastosTotal = tot($pdo, "SELECT COALESCE(SUM(monto + COALESCE(monto_peaje,0)),0) FROM gastos_faena");

// Combustible (litros * precio default)
$preciosLts = ['diesel'=>1100, 'gasolina'=>1300, 'petroleo'=>1100];
function combMonto($pdo, $where='', $p=[], $preciosLts=['diesel'=>1100,'gasolina'=>1300,'petroleo'=>1100]) {
  $sql = "SELECT h.tipo_combustible AS tipo, COALESCE(SUM(v.cantidad_litros),0) lts
          FROM combustible_hojas h LEFT JOIN combustible_vales v ON v.hoja_id=h.id";
  if ($where) $sql .= " WHERE $where";
  $sql .= " GROUP BY h.tipo_combustible";
  $st = $pdo->prepare($sql); $st->execute($p);
  $tot = 0;
  foreach ($st as $r) {
    $tk = strtolower(trim($r['tipo'] ?? '')) ?: 'diesel';
    $tot += (float)$r['lts'] * ($preciosLts[$tk] ?? 1100);
  }
  return $tot;
}
$egCombMes  = combMonto($pdo, "h.fecha BETWEEN ? AND ?", [$ini,$fin]);
$egCombAnio = combMonto($pdo, "strftime('%Y',h.fecha)=?", [(string)$anioAct]);

$egTotalMes  = $egOcMes  + $egGastosMes + $egCombMes; // pagos no se suman porque ya están dentro de OCs
$egTotalAnio = $egOcAnio + $egGastosAnio + $egCombAnio;

$margenMes  = $ingMes  - $egTotalMes;
$margenAnio = $ingAnio - $egTotalAnio;

/* ════════════ POR OBRA (top 10) ════════════ */
$porObra = $pdo->query("
  SELECT o.id, o.codigo, o.nombre, o.mandante,
    (SELECT COALESCE(SUM(monto_total),0) FROM estados_pago e WHERE e.obra_id = o.id AND e.tipo LIKE 'cobro%' AND e.estado != 'anulado') AS ingresos,
    (SELECT COALESCE(SUM(total),0) FROM ordenes_compra oc WHERE oc.obra_id = o.id) AS oc_servicios,
    (SELECT COALESCE(SUM(monto + COALESCE(monto_peaje,0)),0) FROM gastos_faena gf WHERE gf.obra_id = o.id) AS gastos_obra,
    (SELECT COALESCE(SUM(a.oc_ext_monto),0) FROM cot_oc_asociaciones a JOIN cotizaciones c ON c.id=a.cot_id WHERE a.es_externa=1 AND c.obra_id=o.id) AS monto_proyecto
  FROM obras o
  ORDER BY ingresos DESC
  LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);

/* ════════════ TOP PROVEEDORES (gasto + OC) ════════════ */
$topProv = $pdo->prepare("
  SELECT proveedor_nombre AS prov, SUM(total) AS total, COUNT(*) AS oc
  FROM ordenes_compra
  WHERE fecha BETWEEN ? AND ? AND proveedor_nombre != ''
  GROUP BY proveedor_nombre ORDER BY total DESC LIMIT 10
");
$topProv->execute([$ini,$fin]);
$topProvList = $topProv->fetchAll(PDO::FETCH_ASSOC);

/* ════════════ GASTOS POR CATEGORÍA (mes) ════════════ */
$porCat = $pdo->prepare("
  SELECT COALESCE(NULLIF(categoria_nombre,''),'Sin categoría') cat,
         COUNT(*) qty, COALESCE(SUM(monto + COALESCE(monto_peaje,0)),0) total
  FROM gastos_faena
  WHERE fecha BETWEEN ? AND ?
  GROUP BY categoria_nombre ORDER BY total DESC
");
$porCat->execute([$ini,$fin]);
$porCatList = $porCat->fetchAll(PDO::FETCH_ASSOC);

/* ════════════ TENDENCIA MENSUAL (12 últimos meses) ════════════ */
$tendencia = [];
for ($i = 11; $i >= 0; $i--) {
  $ts = strtotime("-$i month");
  $m = date('Y-m', $ts);
  $tendencia[$m] = ['ing'=>0, 'eg'=>0];
}
$stT = $pdo->query("SELECT strftime('%Y-%m',fecha) m, COALESCE(SUM(monto_total),0) s
                    FROM estados_pago WHERE tipo LIKE 'cobro%' AND estado != 'anulado'
                    GROUP BY m");
foreach ($stT as $r) if (isset($tendencia[$r['m']])) $tendencia[$r['m']]['ing'] = (float)$r['s'];
$stE = $pdo->query("SELECT strftime('%Y-%m',fecha) m, COALESCE(SUM(total),0) s
                    FROM ordenes_compra GROUP BY m");
foreach ($stE as $r) if (isset($tendencia[$r['m']])) $tendencia[$r['m']]['eg'] += (float)$r['s'];
$stEG = $pdo->query("SELECT strftime('%Y-%m',fecha) m, COALESCE(SUM(monto + COALESCE(monto_peaje,0)),0) s
                     FROM gastos_faena GROUP BY m");
foreach ($stEG as $r) if (isset($tendencia[$r['m']])) $tendencia[$r['m']]['eg'] += (float)$r['s'];

/* ════════════ # PROYECTOS ACTIVOS ════════════ */
$obrasActivas = (int)$pdo->query("SELECT COUNT(*) FROM obras WHERE estado='activa'")->fetchColumn();
$cotsAdj = (int)$pdo->query("SELECT COUNT(*) FROM cotizaciones WHERE estado='adjudicada'")->fetchColumn();
$ocActivas = (int)$pdo->query("SELECT COUNT(*) FROM ordenes_compra")->fetchColumn();

require_once 'includes/header.php';

function fm($n) { return '$' . number_format((float)$n, 0, ',', '.'); }
$mesNom = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
?>
<a href="/inicio.php" class="btn btn-outline-secondary btn-sm mb-3"><i class="bi bi-arrow-left me-1"></i>Volver al inicio</a>

<div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
  <div>
    <h3 class="fw-bold mb-1"><i class="bi bi-graph-up-arrow text-success me-2"></i>Dashboard Financiero — DOGroup</h3>
    <p class="text-muted mb-0">Vista consolidada de ingresos, egresos y rentabilidad de toda la empresa</p>
  </div>
  <form method="GET" class="d-flex gap-2 align-items-end">
    <div>
      <label class="form-label small mb-1">Mes</label>
      <select name="mes" class="form-select form-select-sm">
        <?php for ($i=1;$i<=12;$i++): $sel = (int)$mesAct === $i ? 'selected' : ''; ?>
        <option value="<?= str_pad($i,2,'0',STR_PAD_LEFT) ?>" <?= $sel ?>><?= $mesNom[$i] ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div>
      <label class="form-label small mb-1">Año</label>
      <select name="anio" class="form-select form-select-sm">
        <?php for ($a=date('Y')+1;$a>=2023;$a--): $sel = (string)$anioAct === (string)$a ? 'selected' : ''; ?>
        <option value="<?= $a ?>" <?= $sel ?>><?= $a ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <button class="btn btn-sm btn-dark"><i class="bi bi-funnel me-1"></i>Aplicar</button>
  </form>
</div>

<!-- KPIs principales del MES -->
<div class="row g-3 mb-3">
  <div class="col-md-3 col-6">
    <div class="card border-success border-2 shadow-sm h-100">
      <div class="card-body">
        <small class="text-success"><i class="bi bi-arrow-down-circle me-1"></i>Ingresos (<?= $mesNom[(int)$mesAct] ?> <?= $anioAct ?>)</small>
        <h4 class="fw-bold mb-1"><?= fm($ingMes) ?></h4>
        <small class="text-muted">Año: <strong><?= fm($ingAnio) ?></strong></small>
      </div>
    </div>
  </div>
  <div class="col-md-3 col-6">
    <div class="card border-danger border-2 shadow-sm h-100">
      <div class="card-body">
        <small class="text-danger"><i class="bi bi-arrow-up-circle me-1"></i>Egresos (mes)</small>
        <h4 class="fw-bold mb-1"><?= fm($egTotalMes) ?></h4>
        <small class="text-muted">Año: <strong><?= fm($egTotalAnio) ?></strong></small>
      </div>
    </div>
  </div>
  <div class="col-md-3 col-6">
    <div class="card border-<?= $margenMes >= 0 ? 'primary' : 'danger' ?> border-2 shadow-sm h-100">
      <div class="card-body">
        <small class="text-<?= $margenMes >= 0 ? 'primary' : 'danger' ?>"><i class="bi bi-graph-up me-1"></i>Margen (mes)</small>
        <h4 class="fw-bold mb-1"><?= fm($margenMes) ?></h4>
        <small class="text-muted">Año: <strong><?= fm($margenAnio) ?></strong></small>
      </div>
    </div>
  </div>
  <div class="col-md-3 col-6">
    <div class="card border-info border-2 shadow-sm h-100">
      <div class="card-body">
        <small class="text-info"><i class="bi bi-building-fill me-1"></i>Proyectos activos</small>
        <h4 class="fw-bold mb-1"><?= $obrasActivas ?></h4>
        <small class="text-muted"><?= $cotsAdj ?> cot. adjud · <?= $ocActivas ?> OC</small>
      </div>
    </div>
  </div>
</div>

<!-- Desglose Ingresos / Egresos -->
<div class="row g-3 mb-3">
  <div class="col-md-6">
    <div class="card shadow-sm h-100">
      <div class="card-header bg-success text-white py-2"><strong><i class="bi bi-arrow-down-circle me-1"></i>Ingresos del mes</strong></div>
      <div class="card-body">
        <table class="table table-sm mb-0">
          <tr><td>Total facturado</td><td class="text-end fw-bold"><?= fm($ingMes) ?></td></tr>
          <tr><td><small class="text-muted">— pendiente de cobro</small></td><td class="text-end text-warning"><?= fm($ingPendiente) ?></td></tr>
          <tr><td><small class="text-muted">— cobrado</small></td><td class="text-end text-success"><?= fm($ingCobrado) ?></td></tr>
          <tr class="border-top"><td>Ingresos acumulados <?= $anioAct ?></td><td class="text-end fw-bold"><?= fm($ingAnio) ?></td></tr>
          <tr><td>Ingresos histórico</td><td class="text-end fw-bold"><?= fm($ingTotal) ?></td></tr>
        </table>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card shadow-sm h-100">
      <div class="card-header bg-danger text-white py-2"><strong><i class="bi bi-arrow-up-circle me-1"></i>Egresos del mes</strong></div>
      <div class="card-body">
        <table class="table table-sm mb-0">
          <tr><td><i class="bi bi-receipt me-1 text-secondary"></i>OC del sistema (servicios)</td><td class="text-end fw-semibold"><?= fm($egOcMes) ?></td></tr>
          <tr><td><i class="bi bi-receipt-cutoff me-1 text-info"></i>Gastos por obra</td><td class="text-end fw-semibold"><?= fm($egGastosMes) ?></td></tr>
          <tr><td><i class="bi bi-fuel-pump-fill me-1" style="color:#d97706"></i>Combustible</td><td class="text-end fw-semibold"><?= fm($egCombMes) ?></td></tr>
          <tr class="border-top"><th>Total egresos del mes</th><th class="text-end"><?= fm($egTotalMes) ?></th></tr>
          <tr><td><small class="text-muted">Pagos a proveedores ejecutados</small></td><td class="text-end small text-muted"><?= fm($egPagosMes) ?></td></tr>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Tendencia 12 meses -->
<div class="card shadow-sm mb-3">
  <div class="card-header bg-primary text-white py-2"><strong><i class="bi bi-bar-chart-line me-1"></i>Tendencia últimos 12 meses</strong></div>
  <div class="card-body p-3" style="overflow-x:auto">
    <?php $maxVal = 0; foreach ($tendencia as $r) { $maxVal = max($maxVal, $r['ing'], $r['eg']); } ?>
    <table class="table table-sm align-middle mb-0" style="min-width:900px">
      <thead class="table-light"><tr><th>Mes</th><th>Ingresos</th><th>Egresos</th><th>Margen</th><th style="min-width:300px">Visualización</th></tr></thead>
      <tbody>
        <?php foreach ($tendencia as $m => $r): $mar = $r['ing'] - $r['eg']; $pInIn = $maxVal > 0 ? ($r['ing']/$maxVal)*100 : 0; $pInEg = $maxVal > 0 ? ($r['eg']/$maxVal)*100 : 0; ?>
        <tr>
          <td><strong><?= htmlspecialchars($m) ?></strong></td>
          <td class="text-end text-success"><?= fm($r['ing']) ?></td>
          <td class="text-end text-danger"><?= fm($r['eg']) ?></td>
          <td class="text-end fw-semibold <?= $mar >= 0 ? 'text-primary' : 'text-danger' ?>"><?= fm($mar) ?></td>
          <td>
            <div class="progress mb-1" style="height:8px"><div class="progress-bar bg-success" style="width:<?= $pInIn ?>%"></div></div>
            <div class="progress" style="height:8px"><div class="progress-bar bg-danger" style="width:<?= $pInEg ?>%"></div></div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Por Obra (top 30) -->
<div class="row g-3 mb-3">
  <div class="col-lg-7">
    <div class="card shadow-sm h-100">
      <div class="card-header bg-dark text-white py-2"><strong><i class="bi bi-building-fill me-1"></i>Resumen por proyecto (Top 30)</strong></div>
      <div class="card-body p-0">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th>ID</th><th>Obra</th><th class="text-end">Monto Proy.</th><th class="text-end">Facturado</th><th class="text-end">Egresos</th><th class="text-end">Margen</th></tr></thead>
          <tbody>
            <?php foreach ($porObra as $o): $mar = (float)$o['ingresos'] - ((float)$o['oc_servicios'] + (float)$o['gastos_obra']); ?>
            <tr>
              <td><span class="badge bg-primary"><i class="bi bi-hash"></i><?= (int)$o['id'] ?></span></td>
              <td>
                <strong><?= htmlspecialchars($o['codigo']) ?></strong>
                <small class="d-block text-muted"><?= htmlspecialchars($o['nombre']) ?></small>
              </td>
              <td class="text-end small"><?= fm($o['monto_proyecto']) ?></td>
              <td class="text-end text-success"><?= fm($o['ingresos']) ?></td>
              <td class="text-end text-danger small"><?= fm((float)$o['oc_servicios'] + (float)$o['gastos_obra']) ?></td>
              <td class="text-end fw-bold <?= $mar >= 0 ? 'text-primary' : 'text-danger' ?>"><?= fm($mar) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card shadow-sm mb-3">
      <div class="card-header bg-secondary text-white py-2"><strong><i class="bi bi-truck me-1"></i>Top 10 proveedores (mes)</strong></div>
      <div class="card-body p-0">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th>Proveedor</th><th class="text-end">OC</th><th class="text-end">Total</th></tr></thead>
          <tbody>
            <?php if (empty($topProvList)): ?>
            <tr><td colspan="3" class="text-center text-muted py-3">Sin OC en el período</td></tr>
            <?php else: foreach ($topProvList as $p): ?>
            <tr>
              <td><small><?= htmlspecialchars($p['prov']) ?></small></td>
              <td class="text-end"><?= (int)$p['oc'] ?></td>
              <td class="text-end fw-semibold"><?= fm($p['total']) ?></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card shadow-sm">
      <div class="card-header bg-info text-white py-2"><strong><i class="bi bi-pie-chart-fill me-1"></i>Gastos por categoría (mes)</strong></div>
      <div class="card-body p-0">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th>Categoría</th><th class="text-end">Cant.</th><th class="text-end">Total</th></tr></thead>
          <tbody>
            <?php if (empty($porCatList)): ?>
            <tr><td colspan="3" class="text-center text-muted py-3">Sin gastos en el período</td></tr>
            <?php else: foreach ($porCatList as $c): ?>
            <tr>
              <td><small><?= htmlspecialchars($c['cat']) ?></small></td>
              <td class="text-end"><?= (int)$c['qty'] ?></td>
              <td class="text-end fw-semibold"><?= fm($c['total']) ?></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php require_once 'includes/footer.php'; ?>
