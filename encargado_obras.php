<?php
/**
 * Panel del Encargado de Operaciones — Resumen por obra en tiempo real
 */
require_once 'config.php';
requireAuth();

$esAdmin = ($usuario['rol'] === 'admin') || isDespachoAdmin($usuario, $pdo);
$esEncOper = false;
try {
    $stE = $pdo->prepare("SELECT COUNT(*) FROM despacho_encargado_operaciones WHERE usuario_id=?");
    $stE->execute([$usuario['id']]);
    $esEncOper = (bool)$stE->fetchColumn();
} catch(Exception $e){}

$esSupervisor = false;
$stSup = $pdo->prepare("SELECT COUNT(*) FROM obra_supervisores WHERE usuario_id=? AND activo=1");
$stSup->execute([$usuario['id']]);
$esSupervisor = (bool)$stSup->fetchColumn();

if (!$esAdmin && !$esEncOper && !$esSupervisor) {
    header('Location: inicio.php'); exit;
}

$hoy = date('Y-m-d');

// Obras con sus equipos y stats del día
$obras = $pdo->query("SELECT * FROM obras WHERE estado='activa' ORDER BY codigo")->fetchAll(PDO::FETCH_ASSOC);

$resumen = [];
foreach ($obras as $obra) {
    $oid = $obra['id'];

    // Supervisores
    $stS = $pdo->prepare("SELECT u.nombre FROM obra_supervisores os JOIN usuarios u ON u.id=os.usuario_id WHERE os.obra_id=? AND os.activo=1");
    $stS->execute([$oid]);
    $sups = $stS->fetchAll(PDO::FETCH_COLUMN);

    // Conductores + camiones
    $stC = $pdo->prepare("SELECT dc.nombre, dp.ppu FROM obra_equipo oe JOIN despacho_conductores dc ON dc.id=oe.conductor_id LEFT JOIN despacho_ppu dp ON dp.id=oe.ppu_id WHERE oe.obra_id=? AND oe.activo=1");
    $stC->execute([$oid]);
    $conductores = $stC->fetchAll(PDO::FETCH_ASSOC);

    // Carchek
    $stCK = $pdo->prepare("SELECT de.nombre, de.empresa FROM obra_carchek oc JOIN despacho_externos de ON de.id=oc.externo_id WHERE oc.obra_id=? AND oc.activo=1 LIMIT 1");
    $stCK->execute([$oid]);
    $carchek = $stCK->fetch(PDO::FETCH_ASSOC);

    // Tickets hoy
    $stT = $pdo->prepare("SELECT estado, COUNT(*) c FROM tickets_despacho WHERE obra_id=? AND fecha=? GROUP BY estado");
    $stT->execute([$oid, $hoy]);
    $tStats = ['borrador'=>0,'enviado'=>0,'validado'=>0,'total'=>0];
    foreach ($stT->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $tStats[$r['estado']] = (int)$r['c'];
        $tStats['total'] += (int)$r['c'];
    }

    // m³ validados hoy
    $stM3 = $pdo->prepare("SELECT COALESCE(SUM(metros_cubicos),0) FROM tickets_despacho WHERE obra_id=? AND fecha=? AND estado='validado'");
    $stM3->execute([$oid, $hoy]);
    $m3hoy = (float)$stM3->fetchColumn();

    // Último ticket
    $stLast = $pdo->prepare("SELECT creado_en, conductor_nombre, ppu, tipo_material FROM tickets_despacho WHERE obra_id=? ORDER BY creado_en DESC LIMIT 1");
    $stLast->execute([$oid]);
    $ultimo = $stLast->fetch(PDO::FETCH_ASSOC);

    if (!empty($sups) || !empty($conductores) || $tStats['total'] > 0) {
        $resumen[] = compact('obra','sups','conductores','carchek','tStats','m3hoy','ultimo');
    }
}

require_once 'includes/header.php';
?>
<a href="/inicio.php" data-volver data-fallback="/inicio.php" class="btn btn-outline-secondary btn-sm mb-3"><i class="bi bi-arrow-left me-1"></i>Volver</a>

<style>
.obra-resumen-card { border:1.5px solid #dee2e6; border-radius:14px; background:#fff; box-shadow:0 2px 8px rgba(0,0,0,.06); margin-bottom:18px; overflow:hidden; }
.obra-resumen-header { background:#1a1a2e; color:#fff; padding:14px 18px; display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
.obra-codigo { font-family:monospace; font-weight:900; font-size:1.1rem; letter-spacing:1px; }
.stat-pill { border-radius:8px; padding:6px 12px; text-align:center; min-width:70px; }
.conductor-row { display:flex; align-items:center; justify-content:space-between; padding:6px 12px; border-bottom:1px solid #f0f0f0; font-size:.85rem; }
.ppu-sm { font-family:monospace; font-weight:700; background:#1b2838; color:#fff; padding:1px 6px; border-radius:4px; font-size:.8rem; letter-spacing:1px; }
.carchek-tag { background:#fef3c7; color:#92400e; font-size:.8rem; padding:3px 8px; border-radius:6px; font-weight:600; }
.update-dot { width:8px; height:8px; border-radius:50%; background:#22c55e; display:inline-block; animation:blink 2s infinite; margin-right:5px; }
@keyframes blink{0%,100%{opacity:1}50%{opacity:.3}}
</style>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h3 class="fw-bold mb-1"><i class="bi bi-clipboard2-data-fill text-success me-2"></i>Resumen de Obras — Hoy</h3>
    <p class="text-muted small mb-0">
      <span class="update-dot"></span>
      <?= date('l d \d\e F Y') ?> · Se actualiza cada 30 segundos
    </p>
  </div>
  <a href="obras_equipos.php" class="btn btn-outline-primary btn-sm">
    <i class="bi bi-people me-1"></i>Gestionar equipos
  </a>
</div>

<?php if (empty($resumen)): ?>
<div class="alert alert-info">
  <i class="bi bi-info-circle me-2"></i>No hay obras con equipo configurado aún.
  <a href="obras_equipos.php" class="alert-link">Configura los equipos aquí.</a>
</div>
<?php endif; ?>

<?php foreach ($resumen as $r): $o=$r['obra']; ?>
<div class="obra-resumen-card">
  <div class="obra-resumen-header">
    <div>
      <span class="obra-codigo"><?= htmlspecialchars($o['codigo']) ?></span>
      <span class="ms-2 fw-normal" style="opacity:.8"><?= htmlspecialchars($o['nombre']) ?></span>
      <?php if ($o['ciudad']): ?><small class="ms-2" style="opacity:.6"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($o['ciudad']) ?></small><?php endif; ?>
    </div>
    <!-- Supervisores -->
    <div class="d-flex gap-2 flex-wrap">
      <?php foreach ($r['sups'] as $s): ?>
      <span style="background:rgba(255,255,255,.15);padding:3px 10px;border-radius:999px;font-size:.78rem;font-weight:700">
        <i class="bi bi-person-check me-1"></i><?= htmlspecialchars($s) ?>
      </span>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="p-3">
    <div class="row g-3">

      <!-- Stats del día -->
      <div class="col-md-5">
        <div class="d-flex gap-2 mb-3 flex-wrap">
          <div class="stat-pill" style="background:#f0fff4;border:1px solid #bbf7d0">
            <div style="font-size:1.6rem;font-weight:900;color:#15803d"><?= $r['tStats']['validado'] ?></div>
            <div style="font-size:.7rem;color:#15803d;font-weight:600">Validados</div>
          </div>
          <div class="stat-pill" style="background:#fff8e1;border:1px solid #fde68a">
            <div style="font-size:1.6rem;font-weight:900;color:#b45309"><?= $r['tStats']['enviado'] ?></div>
            <div style="font-size:.7rem;color:#b45309;font-weight:600">Pendientes</div>
          </div>
          <div class="stat-pill" style="background:#f8f9fa;border:1px solid #dee2e6">
            <div style="font-size:1.6rem;font-weight:900;color:#374151"><?= $r['tStats']['total'] ?></div>
            <div style="font-size:.7rem;color:#6c757d;font-weight:600">Total hoy</div>
          </div>
          <?php if ($r['m3hoy'] > 0): ?>
          <div class="stat-pill" style="background:#eff6ff;border:1px solid #bfdbfe">
            <div style="font-size:1.3rem;font-weight:900;color:#1d4ed8"><?= number_format($r['m3hoy'],1,',','.') ?></div>
            <div style="font-size:.7rem;color:#1d4ed8;font-weight:600">m³ validados</div>
          </div>
          <?php endif; ?>
        </div>

        <!-- Último ticket -->
        <?php if ($r['ultimo']): $u=$r['ultimo']; ?>
        <div style="background:#f8f9fa;border-radius:8px;padding:8px 12px;font-size:.82rem">
          <span class="text-muted">Último:</span>
          <strong><?= htmlspecialchars($u['conductor_nombre'] ?: '—') ?></strong>
          <?php if ($u['ppu']): ?><span class="ppu-sm"><?= htmlspecialchars($u['ppu']) ?></span><?php endif; ?>
          <span class="badge bg-secondary ms-1"><?= htmlspecialchars($u['tipo_material'] ?? '') ?></span>
          <br><span class="text-muted"><?= date('H:i', strtotime($u['creado_en'])) ?> hrs</span>
        </div>
        <?php endif; ?>
      </div>

      <!-- Conductores -->
      <div class="col-md-4">
        <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#6c757d;margin-bottom:6px">
          <i class="bi bi-people me-1"></i>Equipo (<?= count($r['conductores']) ?>)
        </div>
        <?php foreach ($r['conductores'] as $c): ?>
        <div class="conductor-row">
          <span><?= htmlspecialchars($c['nombre']) ?></span>
          <?php if ($c['ppu']): ?><span class="ppu-sm"><?= htmlspecialchars($c['ppu']) ?></span>
          <?php else: ?><span class="text-warning small"><i class="bi bi-exclamation-triangle"></i></span><?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if (empty($r['conductores'])): ?><p class="text-muted small ps-2">Sin conductores</p><?php endif; ?>
      </div>

      <!-- Carchek -->
      <div class="col-md-3">
        <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#6c757d;margin-bottom:6px">
          <i class="bi bi-qr-code-scan me-1"></i>Validador Carchek
        </div>
        <?php if ($r['carchek']): ?>
        <div class="carchek-tag">
          <i class="bi bi-check-circle-fill me-1"></i><?= htmlspecialchars($r['carchek']['nombre']) ?>
          <br><small><?= htmlspecialchars($r['carchek']['empresa']) ?></small>
        </div>
        <?php else: ?>
        <span class="text-danger small"><i class="bi bi-x-circle me-1"></i>Sin Carchek asignado</span>
        <?php endif; ?>

        <div class="mt-2">
          <a href="tickets_despacho.php?obra=<?= $o['id'] ?>" class="btn btn-sm btn-outline-dark w-100">
            <i class="bi bi-ticket-perforated me-1"></i>Ver tickets
          </a>
        </div>
      </div>

    </div>
  </div>
</div>
<?php endforeach; ?>

<script>
// Auto-actualizar cada 30 segundos
setTimeout(function(){ window.location.reload(); }, 30000);
</script>

<?php require_once 'includes/footer.php'; ?>
