<?php
require_once 'config.php';
requireAuth();
requireModulo('despacho', $usuario, $pdo);

/* Solo acceden Encargados de Operaciones (perfil interno asignado) o admins */
$esAdminDespacho = isDespachoAdmin($usuario, $pdo);
$esEncOper       = isEncargadoOperaciones($usuario, $pdo);
if (!$esAdminDespacho && !$esEncOper) {
  header('Location: tickets_despacho.php?error=sin_permiso');
  exit;
}

$MATERIALES = [
  'tierra'=>'Tierra','integral'=>'Integral','bajo_6'=>'Bajo 6','bajo_4'=>'Bajo 4','bajo_3'=>'Bajo 3','bajo_2'=>'Bajo 2',
  'base_chancada'=>'Base Chancada','grava'=>'Grava','gravilla'=>'Gravilla','arena'=>'Arena','arena_tubo'=>'Arena Tubo',
  'escombros'=>'Escombros','bolones'=>'Bolones',
];

/* ── Filtros ── */
$desde = $_GET['desde'] ?? date('Y-m-01');
$hasta = $_GET['hasta'] ?? date('Y-m-d');
$tipo  = $_GET['tipo']  ?? 'todos'; // todos | carchek | cierre

/* ── Reportes Carchek (revisiones diarias) ── */
$reportesCarchek = [];
try {
  $stRC = $pdo->prepare("
    SELECT rc.*, e.nombre AS validador_nombre, e.empresa AS validador_empresa
    FROM despacho_reporte_carchek rc
    LEFT JOIN despacho_externos e ON e.id = rc.externo_id
    WHERE DATE(rc.fecha_reporte) BETWEEN ? AND ?
    ORDER BY rc.enviado_en DESC
  ");
  $stRC->execute([$desde, $hasta]);
  $reportesCarchek = $stRC->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

/* ── Cierres diarios internos ── */
$cierresDiarios = [];
try {
  $stCD = $pdo->prepare("
    SELECT cd.*
    FROM despacho_cierre_dia cd
    WHERE DATE(cd.fecha) BETWEEN ? AND ?
    ORDER BY cd.fecha DESC, cd.cerrado_en DESC
  ");
  $stCD->execute([$desde, $hasta]);
  $cierresDiarios = $stCD->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

/* ── Resumen ── */
$totRC = count($reportesCarchek);
$totCD = count($cierresDiarios);
$sumAprob = 0; $sumRech = 0; $sumObs = 0; $sumTickets = 0; $sumM3 = 0;
foreach ($reportesCarchek as $r) {
  $sumAprob   += (int)($r['total_aprobados']  ?? 0);
  $sumRech    += (int)($r['total_rechazados'] ?? 0);
  $sumObs     += (int)($r['total_observados'] ?? 0);
  $sumTickets += (int)($r['total_tickets']    ?? 0);
  $sumM3      += (float)($r['total_m3']       ?? 0);
}
$sumTicketsCD = 0; $sumM3CD = 0;
foreach ($cierresDiarios as $c) {
  $sumTicketsCD += (int)($c['total_tickets'] ?? 0);
  $sumM3CD      += (float)($c['total_m3']    ?? 0);
}

/* ── Actividad en vivo: tickets validados/revisados HOY ── */
$hoy = date('Y-m-d');
$ticketsHoy = [];
try {
  $stHoy = $pdo->prepare("
    SELECT td.id, td.fecha, td.hora, td.ppu, td.metros_cubicos,
           td.tipo_material, td.salida_nombre, td.obra_nombre, td.destino,
           td.conductor_nombre, td.creado_por_nombre,
           td.estado, td.estado_revision, td.observacion_revision,
           td.validado_en, td.validado_por_nombre,
           td.revisado_en, td.revisado_por_ext_nom,
           e.empresa AS validador_empresa
    FROM tickets_despacho td
    LEFT JOIN despacho_externos e ON e.id = td.revisado_por_ext_id
    WHERE td.estado='validado' AND DATE(td.validado_en) = ?
    ORDER BY COALESCE(td.revisado_en, td.validado_en) DESC
    LIMIT 200
  ");
  $stHoy->execute([$hoy]);
  $ticketsHoy = $stHoy->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

/* Stats del día en vivo */
$hoyTot = count($ticketsHoy); $hoyAprob=0; $hoyRech=0; $hoyObs=0; $hoySinRev=0; $hoyM3=0;
foreach ($ticketsHoy as $t) {
  if      ($t['estado_revision'] === 'aprobado')    $hoyAprob++;
  elseif  ($t['estado_revision'] === 'rechazado')   $hoyRech++;
  elseif  ($t['estado_revision'] === 'observacion') $hoyObs++;
  else                                              $hoySinRev++;
  $hoyM3 += (float)$t['metros_cubicos'];
}

$titulo = 'Mis Reportes de Operaciones';
require_once 'includes/header.php';
?>
<style>
.rep-card {
  border:1px solid #e6e9ef; border-radius:10px; transition:all .15s;
  background:#fff;
}
.rep-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.08); transform:translateY(-1px); }
.rep-stat {
  background:#f8f9fb; border:1px solid #e6e9ef; border-radius:8px;
  padding:.6rem .85rem; min-width:110px; text-align:center;
}
.rep-stat .lbl { font-size:.72rem; color:#6c757d; text-transform:uppercase; letter-spacing:.5px; }
.rep-stat .val { font-size:1.25rem; font-weight:800; color:#1b2838; line-height:1.1; }
.rep-section-title {
  font-weight:800; color:#1b2838; display:flex; align-items:center; gap:.5rem;
  border-bottom:2px solid #1b2838; padding-bottom:.4rem; margin-bottom:1rem;
}
.btn-tab-tipo { border-radius:999px; }
.live-card { border-left:4px solid #198754 !important; }
.live-dot {
  display:inline-block; width:10px; height:10px; border-radius:50%;
  background:#dc3545; box-shadow:0 0 0 0 rgba(220,53,69,.7);
  animation: livePulse 1.6s infinite;
  vertical-align:middle;
}
@keyframes livePulse {
  0%   { box-shadow:0 0 0 0   rgba(220,53,69,.7); }
  70%  { box-shadow:0 0 0 12px rgba(220,53,69,0); }
  100% { box-shadow:0 0 0 0   rgba(220,53,69,0); }
}
.live-list { max-height:520px; overflow-y:auto; }
.live-list thead th { position:sticky; top:0; z-index:2; }
@media (max-width:576px){
  .rep-stat { min-width:48%; }
}
</style>

<div class="container-fluid py-3">
  <!-- Header -->
  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
    <div>
      <h3 class="fw-bold mb-1">
        <i class="bi bi-inbox-fill text-warning me-2"></i>Mis Reportes de Operaciones
      </h3>
      <p class="text-muted mb-0">
        Reportes de despacho dirigidos al
        <strong>Encargado de Operaciones</strong> — recibidos por correo y archivados aquí.
      </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <a href="tickets_despacho.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Volver al módulo
      </a>
      <?php if ($esAdminDespacho): ?>
      <a href="tickets_despacho_admin.php" class="btn btn-dark">
        <i class="bi bi-gear me-1"></i>Configurar
      </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- ╔══════════════════════════════════════════════════╗ -->
  <!-- ║   ACTIVIDAD EN VIVO  (auto-refresh tiempo real)   ║ -->
  <!-- ╚══════════════════════════════════════════════════╝ -->
  <div class="card border-0 shadow-sm mb-3 live-card">
    <div class="card-header bg-dark text-white d-flex align-items-center justify-content-between">
      <div>
        <i class="bi bi-broadcast me-2"></i>
        <strong>Actividad en vivo — Hoy <?= date('d/m/Y') ?></strong>
        <span class="live-dot ms-2"></span>
        <small class="text-warning ms-1">en tiempo real</small>
      </div>
      <div class="text-end small">
        <span class="text-muted">Última actualización:</span>
        <span id="liveLastUpdate" class="fw-bold"><?= date('H:i:s') ?></span>
      </div>
    </div>
    <div class="card-body">
      <!-- Stats del día -->
      <div class="d-flex flex-wrap gap-2 mb-3">
        <div class="rep-stat flex-grow-1">
          <div class="lbl">Validados hoy</div>
          <div class="val text-primary"><?= $hoyTot ?></div>
        </div>
        <div class="rep-stat flex-grow-1">
          <div class="lbl"><i class="bi bi-check-circle me-1"></i>Aprobados</div>
          <div class="val text-success"><?= $hoyAprob ?></div>
        </div>
        <div class="rep-stat flex-grow-1">
          <div class="lbl"><i class="bi bi-x-circle me-1"></i>Rechazados</div>
          <div class="val text-danger"><?= $hoyRech ?></div>
        </div>
        <div class="rep-stat flex-grow-1">
          <div class="lbl"><i class="bi bi-exclamation-triangle me-1"></i>Con obs.</div>
          <div class="val text-warning"><?= $hoyObs ?></div>
        </div>
        <div class="rep-stat flex-grow-1">
          <div class="lbl"><i class="bi bi-hourglass-split me-1"></i>Sin revisar</div>
          <div class="val text-secondary"><?= $hoySinRev ?></div>
        </div>
        <div class="rep-stat flex-grow-1">
          <div class="lbl">m³ del día</div>
          <div class="val"><?= number_format($hoyM3, 1, ',', '.') ?></div>
        </div>
      </div>

      <?php if (empty($ticketsHoy)): ?>
      <div class="alert alert-light border text-center py-4 mb-0">
        <i class="bi bi-broadcast text-muted" style="font-size:2rem;"></i>
        <div class="text-muted mt-2">
          Aún no hay tickets validados hoy. Esta sección se actualiza sola en cuanto el Carchek valide un ticket.
        </div>
      </div>
      <?php else: ?>
      <div class="table-responsive live-list">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead class="table-dark">
            <tr>
              <th>#</th>
              <th>Hora val.</th>
              <th>PPU</th>
              <th>m³</th>
              <th>Material</th>
              <th>Salida</th>
              <th>Obra</th>
              <th>Destino</th>
              <th>Conductor</th>
              <th>Validado por</th>
              <th>Estado revisión</th>
            </tr>
          </thead>
          <tbody>
            <?php
              $estLbl = [
                'aprobado'    => '<span class="badge bg-success"><i class="bi bi-check-circle-fill me-1"></i>Aprobado</span>',
                'rechazado'   => '<span class="badge bg-danger"><i class="bi bi-x-circle-fill me-1"></i>Rechazado</span>',
                'observacion' => '<span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle-fill me-1"></i>Con observación</span>',
              ];
              $rowClass = [
                'aprobado'    => 'table-success',
                'rechazado'   => 'table-danger',
                'observacion' => 'table-warning',
              ];
              foreach ($ticketsHoy as $t):
                $clsRow = $rowClass[$t['estado_revision']] ?? '';
                $lblEst = $estLbl[$t['estado_revision']] ?? '<span class="badge bg-secondary"><i class="bi bi-hourglass-split me-1"></i>Sin revisar</span>';
            ?>
            <tr class="<?= $clsRow ?>">
              <td class="fw-bold">#<?= str_pad($t['id'],5,'0',STR_PAD_LEFT) ?></td>
              <td><small><?= !empty($t['validado_en']) ? date('H:i', strtotime($t['validado_en'])) : '—' ?></small></td>
              <td><strong><?= htmlspecialchars($t['ppu'] ?: '—') ?></strong></td>
              <td><?= (float)$t['metros_cubicos'] > 0 ? number_format((float)$t['metros_cubicos'],1,',','.') : '—' ?></td>
              <td><small><?= htmlspecialchars($MATERIALES[$t['tipo_material']] ?? $t['tipo_material']) ?></small></td>
              <td><small><?= htmlspecialchars($t['salida_nombre'] ?? '' ?: '—') ?></small></td>
              <td><small><?= htmlspecialchars($t['obra_nombre'] ?: '—') ?></small></td>
              <td><small><?= htmlspecialchars($t['destino'] ?: '—') ?></small></td>
              <td><small><?= htmlspecialchars($t['conductor_nombre'] ?: '—') ?></small></td>
              <td>
                <small>
                  <?= htmlspecialchars($t['validado_por_nombre'] ?: '—') ?>
                  <?php if (!empty($t['validador_empresa'])): ?>
                    <br><span class="text-muted"><?= htmlspecialchars($t['validador_empresa']) ?></span>
                  <?php endif; ?>
                </small>
              </td>
              <td>
                <?= $lblEst ?>
                <?php if (!empty($t['observacion_revision'])): ?>
                  <div class="small text-muted mt-1" style="max-width:220px;">
                    <i class="bi bi-chat-dots me-1"></i><?= htmlspecialchars($t['observacion_revision']) ?>
                  </div>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Filtros -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-3">
      <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3 col-6">
          <label class="form-label small fw-semibold mb-1">Desde</label>
          <input type="date" name="desde" value="<?= htmlspecialchars($desde) ?>" class="form-control form-control-sm">
        </div>
        <div class="col-md-3 col-6">
          <label class="form-label small fw-semibold mb-1">Hasta</label>
          <input type="date" name="hasta" value="<?= htmlspecialchars($hasta) ?>" class="form-control form-control-sm">
        </div>
        <div class="col-md-4 col-12">
          <label class="form-label small fw-semibold mb-1">Tipo de reporte</label>
          <div class="d-flex gap-1 flex-wrap">
            <a href="?desde=<?= $desde ?>&hasta=<?= $hasta ?>&tipo=todos"   class="btn btn-sm btn-tab-tipo <?= $tipo==='todos'?'btn-dark':'btn-outline-dark' ?>">Todos</a>
            <a href="?desde=<?= $desde ?>&hasta=<?= $hasta ?>&tipo=carchek" class="btn btn-sm btn-tab-tipo <?= $tipo==='carchek'?'btn-info':'btn-outline-info' ?>">Carchek</a>
            <a href="?desde=<?= $desde ?>&hasta=<?= $hasta ?>&tipo=cierre"  class="btn btn-sm btn-tab-tipo <?= $tipo==='cierre'?'btn-success':'btn-outline-success' ?>">Cierre diario</a>
          </div>
        </div>
        <div class="col-md-2 col-12">
          <input type="hidden" name="tipo" value="<?= htmlspecialchars($tipo) ?>">
          <button class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filtrar</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Resumen agregado -->
  <div class="d-flex flex-wrap gap-2 mb-4">
    <div class="rep-stat flex-grow-1">
      <div class="lbl">Reportes Carchek</div>
      <div class="val text-info"><?= $totRC ?></div>
    </div>
    <div class="rep-stat flex-grow-1">
      <div class="lbl">Cierres diarios</div>
      <div class="val text-success"><?= $totCD ?></div>
    </div>
    <div class="rep-stat flex-grow-1">
      <div class="lbl">Tickets revisados</div>
      <div class="val"><?= $sumTickets ?></div>
    </div>
    <div class="rep-stat flex-grow-1">
      <div class="lbl">Aprobados</div>
      <div class="val text-success"><?= $sumAprob ?></div>
    </div>
    <div class="rep-stat flex-grow-1">
      <div class="lbl">Rechazados</div>
      <div class="val text-danger"><?= $sumRech ?></div>
    </div>
    <div class="rep-stat flex-grow-1">
      <div class="lbl">Con observación</div>
      <div class="val text-warning"><?= $sumObs ?></div>
    </div>
    <div class="rep-stat flex-grow-1">
      <div class="lbl">m³ revisados</div>
      <div class="val"><?= number_format($sumM3, 1, ',', '.') ?></div>
    </div>
    <div class="rep-stat flex-grow-1">
      <div class="lbl">m³ cierre interno</div>
      <div class="val text-success"><?= number_format($sumM3CD, 1, ',', '.') ?></div>
    </div>
  </div>

  <?php if ($tipo === 'todos' || $tipo === 'carchek'): ?>
  <!-- Reportes Carchek -->
  <div class="rep-section-title">
    <i class="bi bi-clipboard-check-fill text-info"></i>
    Reportes de revisión Carchek
    <span class="badge bg-info text-dark ms-1"><?= $totRC ?></span>
  </div>

  <?php if (empty($reportesCarchek)): ?>
  <div class="alert alert-light border text-center py-4">
    <i class="bi bi-inbox text-muted" style="font-size:2rem;"></i>
    <div class="text-muted mt-2">No hay reportes Carchek en el rango seleccionado.</div>
  </div>
  <?php else: ?>
  <div class="row g-3 mb-4">
    <?php foreach ($reportesCarchek as $r): ?>
    <div class="col-12 col-md-6 col-xl-4">
      <div class="rep-card p-3 h-100">
        <div class="d-flex align-items-start justify-content-between mb-2">
          <div>
            <span class="badge bg-info text-dark mb-1">Reporte Carchek</span>
            <h5 class="mb-0 fw-bold"><?= date('d/m/Y', strtotime($r['fecha_reporte'])) ?></h5>
            <small class="text-muted">
              Enviado: <?= date('d/m/Y H:i', strtotime($r['enviado_en'])) ?>
            </small>
          </div>
          <div class="text-end">
            <div class="badge bg-secondary"><?= (int)($r['total_tickets'] ?? 0) ?> tickets</div>
          </div>
        </div>

        <div class="small text-muted mb-2">
          <i class="bi bi-person-vcard me-1"></i>
          <strong><?= htmlspecialchars($r['validador_nombre'] ?: '—') ?></strong>
          <?php if (!empty($r['validador_empresa'])): ?>
            <span class="text-muted">· <?= htmlspecialchars($r['validador_empresa']) ?></span>
          <?php endif; ?>
        </div>

        <div class="d-flex gap-1 flex-wrap mb-2">
          <span class="badge bg-success"><?= (int)($r['total_aprobados']  ?? 0) ?> aprobados</span>
          <span class="badge bg-danger"><?= (int)($r['total_rechazados'] ?? 0) ?> rechazados</span>
          <span class="badge bg-warning text-dark"><?= (int)($r['total_observados'] ?? 0) ?> obs.</span>
          <span class="badge bg-dark"><?= number_format((float)($r['total_m3'] ?? 0), 1, ',', '.') ?> m³</span>
        </div>

        <div class="small text-muted mb-2" style="word-break:break-all;">
          <i class="bi bi-envelope me-1"></i>
          <?= htmlspecialchars($r['destinatarios'] ?: '—') ?>
        </div>

        <div class="d-flex gap-1 mt-auto">
          <?php if (!empty($r['html_reporte'])): ?>
          <button type="button" class="btn btn-sm btn-info text-white flex-grow-1"
                  data-bs-toggle="modal" data-bs-target="#modalReporte<?= $r['id'] ?>">
            <i class="bi bi-eye me-1"></i>Ver reporte
          </button>
          <?php else: ?>
          <button class="btn btn-sm btn-outline-secondary flex-grow-1" disabled>
            <i class="bi bi-eye-slash me-1"></i>Sin contenido
          </button>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if (!empty($r['html_reporte'])): ?>
    <!-- Modal con HTML del reporte -->
    <div class="modal fade" id="modalReporte<?= $r['id'] ?>" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header bg-info text-white">
            <h5 class="modal-title">
              <i class="bi bi-clipboard-check me-1"></i>
              Reporte Carchek — <?= date('d/m/Y', strtotime($r['fecha_reporte'])) ?>
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body p-0">
            <iframe srcdoc="<?= htmlspecialchars($r['html_reporte']) ?>"
                    style="width:100%; height:75vh; border:0;"></iframe>
          </div>
          <div class="modal-footer">
            <small class="text-muted me-auto">
              Enviado a: <?= htmlspecialchars($r['destinatarios']) ?>
            </small>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <?php if ($tipo === 'todos' || $tipo === 'cierre'): ?>
  <!-- Cierres diarios -->
  <div class="rep-section-title">
    <i class="bi bi-calendar-check-fill text-success"></i>
    Cierres diarios del módulo
    <span class="badge bg-success ms-1"><?= $totCD ?></span>
  </div>

  <?php if (empty($cierresDiarios)): ?>
  <div class="alert alert-light border text-center py-4">
    <i class="bi bi-calendar-x text-muted" style="font-size:2rem;"></i>
    <div class="text-muted mt-2">No hay cierres diarios en el rango seleccionado.</div>
  </div>
  <?php else: ?>
  <div class="card border-0 shadow-sm mb-4">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Fecha</th>
            <th class="text-center">Tickets</th>
            <th class="text-center">m³ totales</th>
            <th>Cerrado por</th>
            <th>Cerrado el</th>
            <th class="text-center">Reporte</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($cierresDiarios as $c): ?>
          <tr>
            <td class="fw-bold"><?= date('d/m/Y', strtotime($c['fecha'])) ?></td>
            <td class="text-center"><?= (int)$c['total_tickets'] ?></td>
            <td class="text-center"><?= number_format((float)$c['total_m3'], 1, ',', '.') ?> m³</td>
            <td><?= htmlspecialchars($c['cerrado_por_nombre'] ?: '—') ?></td>
            <td><small class="text-muted"><?= date('d/m/Y H:i', strtotime($c['cerrado_en'])) ?></small></td>
            <td class="text-center">
              <a href="tickets_despacho_reporte_diario.php?fecha=<?= urlencode($c['fecha']) ?>"
                 target="_blank" class="btn btn-sm btn-outline-success">
                <i class="bi bi-file-earmark-text me-1"></i>Ver reporte
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>

</div>

<!-- Auto-refresh: detecta validaciones/revisiones del Carchek y nuevos reportes en tiempo real -->
<script>window.DESPACHO_REFRESH = { scope: 'operaciones', interval: 6000, badge: true };</script>
<script src="js/despacho_autorefresh.js"></script>

<?php require_once 'includes/footer.php'; ?>
