<?php
require_once 'config.php';
requireAuth();
requireModulo('despacho', $usuario, $pdo);

$MATERIALES = [
  'tierra'=>'Tierra','integral'=>'Integral','bajo_6'=>'Bajo 6','bajo_4'=>'Bajo 4','bajo_3'=>'Bajo 3',
  'bajo_2'=>'Bajo 2','base_chancada'=>'Base Chancada','grava'=>'Grava','gravilla'=>'Gravilla',
  'arena'=>'Arena','arena_tubo'=>'Arena Tubo','escombros'=>'Escombros','bolones'=>'Bolones',
];

/* ── Ver un día específico (para impresión / detalle) ── */
$verFecha = $_GET['fecha'] ?? null;
if ($verFecha) {
  /* Cargar el cierre y los tickets */
  $stC = $pdo->prepare("SELECT * FROM despacho_cierre_dia WHERE fecha=?");
  $stC->execute([$verFecha]);
  $cierre = $stC->fetch(PDO::FETCH_ASSOC);

  $stT = $pdo->prepare("SELECT * FROM tickets_despacho WHERE fecha=? AND estado='validado' ORDER BY hora");
  $stT->execute([$verFecha]);
  $ticketsDia = $stT->fetchAll(PDO::FETCH_ASSOC);

  $totalM3 = array_sum(array_column($ticketsDia, 'metros_cubicos'));
  $resPPU  = []; $resMat = []; $resObra = [];
  foreach ($ticketsDia as $t) {
    $ppu = $t['ppu'] ?: 'SIN PPU';
    $mat = $MATERIALES[$t['tipo_material']] ?? $t['tipo_material'];
    $ob  = $t['obra_nombre'] ?: 'Sin obra';
    $m3  = (float)$t['metros_cubicos'];
    $resPPU[$ppu]['viajes']  = ($resPPU[$ppu]['viajes']  ?? 0) + 1;
    $resPPU[$ppu]['m3']      = ($resPPU[$ppu]['m3']      ?? 0) + $m3;
    $resMat[$mat]['viajes']  = ($resMat[$mat]['viajes']  ?? 0) + 1;
    $resMat[$mat]['m3']      = ($resMat[$mat]['m3']      ?? 0) + $m3;
    $resObra[$ob]['viajes']  = ($resObra[$ob]['viajes']  ?? 0) + 1;
    $resObra[$ob]['m3']      = ($resObra[$ob]['m3']      ?? 0) + $m3;
  }

  require_once 'includes/header.php';
  ?>
  <style>
  @media print {
    .sidebar,.sidebar-toggle,.topbar,.no-print,.nav-volver-wrap { display:none !important; }
    .main-content { margin-left:0 !important; padding:0 !important; }
    body { background:#fff !important; font-size:10pt; }
    .print-header { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .stat-box     { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  }
  .stat-box { border-radius:8px; padding:.9rem 1.1rem; text-align:center; }
  .stat-box .sv { font-size:2rem; font-weight:800; line-height:1; }
  .stat-box .sl { font-size:.75rem; margin-top:3px; }
  </style>

  <div class="no-print d-flex align-items-center justify-content-between mb-3">
    <h4 class="fw-bold mb-0"><i class="bi bi-file-earmark-bar-graph text-secondary me-2"></i>Reporte Diario — <?= date('d/m/Y', strtotime($verFecha)) ?></h4>
    <div class="d-flex gap-2">
      <a href="tickets_despacho_cierre.php?fecha=<?= urlencode($verFecha) ?>&csv=1" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i>Excel / CSV</a>
      <button onclick="window.print()" class="btn btn-outline-secondary"><i class="bi bi-printer me-1"></i>Imprimir / PDF</button>
      <a href="tickets_despacho_reporte_diario.php" class="btn btn-secondary"><i class="bi bi-arrow-left me-1"></i>Historial</a>
    </div>
  </div>

  <!-- Encabezado imprimible -->
  <div class="print-header d-flex align-items-center justify-content-between mb-3" style="background:#212529;color:#fff;padding:1rem 1.5rem;border-radius:10px;">
    <div class="d-flex align-items-center gap-3">
      <img src="img/logo.png" alt="DOGROUP" style="height:52px;filter:brightness(0) invert(1);">
      <div>
        <div style="font-size:1.2rem;font-weight:800;letter-spacing:1px;">DOGROUP</div>
        <div style="font-size:.75rem;opacity:.7;">Reporte Diario de Despacho de Material</div>
      </div>
    </div>
    <div style="text-align:right;">
      <div style="font-size:1.6rem;font-weight:800;"><?= date('d/m/Y', strtotime($verFecha)) ?></div>
      <?php if ($cierre): ?>
      <div style="font-size:.7rem;opacity:.75;">Cerrado por: <?= htmlspecialchars($cierre['cerrado_por_nombre']) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($cierre && $cierre['enviado']): ?>
  <div class="alert alert-success py-2 no-print"><i class="bi bi-envelope-check-fill me-1"></i>Reporte enviado al encargado de costos el <?= htmlspecialchars($cierre['enviado_en']) ?></div>
  <?php endif; ?>

  <!-- Estadísticas -->
  <div class="row g-3 mb-4">
    <div class="col-sm-4">
      <div class="stat-box bg-dark text-white">
        <div class="sv"><?= count($ticketsDia) ?></div>
        <div class="sl"><i class="bi bi-ticket-perforated me-1"></i>Viajes validados</div>
      </div>
    </div>
    <div class="col-sm-4">
      <div class="stat-box bg-info text-dark">
        <div class="sv"><?= number_format($totalM3,1,',','.') ?> m³</div>
        <div class="sl"><i class="bi bi-box-seam me-1"></i>Metros cúbicos totales</div>
      </div>
    </div>
    <div class="col-sm-4">
      <div class="stat-box bg-secondary text-white">
        <div class="sv"><?= count($resPPU) ?></div>
        <div class="sl"><i class="bi bi-truck-front-fill me-1"></i>Camiones distintos</div>
      </div>
    </div>
  </div>

  <!-- Detalle -->
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-dark text-white fw-bold"><i class="bi bi-list-ul me-1"></i>Detalle de Tickets</div>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" style="font-size:.86rem;">
        <thead class="table-light">
          <tr><th>#</th><th>Hora</th><th>Salida</th><th>Obra</th><th>PPU</th><th>m³</th><th>Destino</th><th>Material</th><th>Cambio</th><th>Creado por</th><th>Validado por</th></tr>
        </thead>
        <tbody>
        <?php foreach ($ticketsDia as $t): ?>
        <tr>
          <td><small class="text-muted">#<?= str_pad($t['id'],4,'0',STR_PAD_LEFT) ?></small></td>
          <td><strong><?= htmlspecialchars($t['hora']) ?></strong></td>
          <td><small><?= htmlspecialchars($t['salida_nombre'] ?? '' ?: '—') ?></small></td>
          <td><small><?= htmlspecialchars($t['obra_nombre'] ?: '—') ?></small></td>
          <td><span class="badge bg-dark"><?= htmlspecialchars($t['ppu'] ?: '—') ?></span></td>
          <td><?= $t['metros_cubicos'] > 0 ? number_format($t['metros_cubicos'],1,',','.').' m³' : '—' ?></td>
          <td><small><?= htmlspecialchars($t['destino'] ?: '—') ?></small></td>
          <td><span class="badge bg-secondary"><?= htmlspecialchars($MATERIALES[$t['tipo_material']] ?? $t['tipo_material']) ?></span></td>
          <td><small><?= htmlspecialchars($t['cambio'] ?: '—') ?></small></td>
          <td><small><?= htmlspecialchars($t['creado_por_nombre']) ?></small></td>
          <td><small><?= htmlspecialchars($t['validado_por_nombre']) ?></small></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($ticketsDia)): ?>
        <tr><td colspan="11" class="text-center text-muted py-3">Sin tickets validados este día.</td></tr>
        <?php endif; ?>
        </tbody>
        <tfoot class="table-dark">
          <tr>
            <td colspan="4" class="text-end fw-bold">TOTAL</td>
            <td class="fw-bold"><?= number_format($totalM3,1,',','.') ?> m³</td>
            <td colspan="5"></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

  <!-- Resúmenes -->
  <div class="row g-3 mb-4">
    <div class="col-md-4">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-secondary text-white fw-bold py-2"><i class="bi bi-truck-front-fill me-1"></i>Resumen por PPU</div>
        <div class="card-body p-0">
          <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>PPU</th><th class="text-center">Viajes</th><th class="text-center">m³</th></tr></thead>
            <tbody>
            <?php foreach ($resPPU as $p => $d): ?>
            <tr><td><strong><?= htmlspecialchars($p) ?></strong></td><td class="text-center"><?= $d['viajes'] ?></td><td class="text-center"><?= number_format($d['m3'],1,',','.') ?></td></tr>
            <?php endforeach; ?>
            <tr class="table-dark fw-bold"><td>Total</td><td class="text-center"><?= count($ticketsDia) ?></td><td class="text-center"><?= number_format($totalM3,1,',','.') ?></td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-warning text-dark fw-bold py-2"><i class="bi bi-building me-1"></i>Resumen por Obra</div>
        <div class="card-body p-0">
          <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Obra</th><th class="text-center">Viajes</th><th class="text-center">m³</th></tr></thead>
            <tbody>
            <?php foreach ($resObra as $o => $d): ?>
            <tr><td><small><?= htmlspecialchars($o) ?></small></td><td class="text-center"><?= $d['viajes'] ?></td><td class="text-center"><?= number_format($d['m3'],1,',','.') ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-info text-dark fw-bold py-2"><i class="bi bi-box-seam me-1"></i>Resumen por Material</div>
        <div class="card-body p-0">
          <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Material</th><th class="text-center">Viajes</th><th class="text-center">m³</th></tr></thead>
            <tbody>
            <?php foreach ($resMat as $m => $d): ?>
            <tr><td><small><?= htmlspecialchars($m) ?></small></td><td class="text-center"><?= $d['viajes'] ?></td><td class="text-center"><?= number_format($d['m3'],1,',','.') ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Pie imprimible -->
  <div style="border-top:1px solid #ccc;padding-top:.5rem;display:flex;justify-content:space-between;font-size:.7rem;color:#999;" class="mt-2">
    <span>DOGROUP &copy; <?= date('Y') ?> — Desarrollado por Departamento de Informática DOGroup</span>
    <span>Generado: <?= date('d/m/Y H:i') ?></span>
  </div>

  <?php require_once 'includes/footer.php'; ?>
  <?php exit; ?>
<?php } // fin verFecha ?>

<?php
/* ── HISTORIAL ── */
$cierres = $pdo->query("SELECT * FROM despacho_cierre_dia ORDER BY fecha DESC LIMIT 90")->fetchAll(PDO::FETCH_ASSOC);
require_once 'includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h4 class="fw-bold mb-0"><i class="bi bi-clock-history text-secondary me-2"></i>Historial de Cierres Diarios</h4>
  <div class="d-flex gap-2">
    <a href="tickets_despacho_cierre.php" class="btn btn-success"><i class="bi bi-calendar2-check me-1"></i>Cierre de hoy</a>
    <a href="tickets_despacho.php" class="btn btn-secondary"><i class="bi bi-arrow-left me-1"></i>Volver atrás</a>
  </div>
</div>

<?php if (empty($cierres)): ?>
<div class="text-center text-muted py-5"><i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>Aún no hay cierres registrados. El historial se construye al cerrar cada día.</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-dark">
        <tr><th>Fecha</th><th class="text-center">Viajes</th><th class="text-center">m³ Total</th><th>Cerrado por</th><th>Fecha cierre</th><th class="text-center">Reporte</th><th class="text-end">Acciones</th></tr>
      </thead>
      <tbody>
      <?php foreach ($cierres as $c): ?>
      <tr>
        <td><strong><?= date('d/m/Y', strtotime($c['fecha'])) ?></strong></td>
        <td class="text-center"><span class="badge bg-dark fs-6"><?= $c['total_tickets'] ?></span></td>
        <td class="text-center"><?= number_format($c['total_m3'],1,',','.') ?> m³</td>
        <td><?= htmlspecialchars($c['cerrado_por_nombre']) ?></td>
        <td><small class="text-muted"><?= htmlspecialchars(substr($c['cerrado_en'],0,16)) ?></small></td>
        <td class="text-center">
          <?php if ($c['enviado']): ?>
          <span class="badge bg-success"><i class="bi bi-envelope-check me-1"></i>Enviado</span>
          <?php else: ?>
          <span class="badge bg-secondary">No enviado</span>
          <?php endif; ?>
        </td>
        <td class="text-end">
          <a href="tickets_despacho_reporte_diario.php?fecha=<?= urlencode($c['fecha']) ?>" class="btn btn-sm btn-outline-secondary" title="Ver detalle"><i class="bi bi-eye me-1"></i>Ver</a>
          <a href="tickets_despacho_cierre.php?csv=1&fecha=<?= urlencode($c['fecha']) ?>" class="btn btn-sm btn-outline-success" title="Descargar CSV"><i class="bi bi-file-earmark-excel"></i></a>
          <a href="tickets_despacho_cierre.php?fecha=<?= urlencode($c['fecha']) ?>" class="btn btn-sm btn-outline-dark" title="Reabrir cierre"><i class="bi bi-arrow-clockwise"></i></a>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
