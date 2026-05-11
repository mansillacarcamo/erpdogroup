<?php
require_once 'config.php';

/* Permitir acceso también a Carchek y conductores externos */
$esExterno    = isExternoLoggedIn();
$esConductor  = isConductorLoggedIn();

if ($esExterno) {
  $ext = getExternoSession();
} elseif ($esConductor) {
  $cond = getConductorSession();
} else {
  requireAuth();
  requireModulo('despacho', $usuario, $pdo);
}

$id = (int)($_GET['id'] ?? 0);
$backUrl = $esExterno ? 'despacho_externo_portal.php' : ($esConductor ? 'despacho_conductor_portal.php' : 'tickets_despacho.php');
if (!$id) { header('Location: '.$backUrl); exit; }

$st = $pdo->prepare("SELECT * FROM tickets_despacho WHERE id=?");
$st->execute([$id]);
$t = $st->fetch(PDO::FETCH_ASSOC);
if (!$t) { header('Location: '.$backUrl); exit; }

$esAdminDespacho = (!$esExterno && !$esConductor) ? isDespachoAdmin($usuario, $pdo) : false;
$esPropietario   = (!$esExterno && !$esConductor) && ((int)$t['creado_por'] === (int)$usuario['id']);
if (!$esExterno && !$esConductor && !$esAdminDespacho && !$esPropietario) {
  $stR = $pdo->prepare("SELECT COUNT(*) FROM despacho_receptores WHERE usuario_id=?");
  $stR->execute([$usuario['id']]);
  if (!(int)$stR->fetchColumn()) { header('Location: tickets_despacho.php'); exit; }
}

$MATERIALES = [
  'tierra'=>'Tierra','integral'=>'Integral','bajo_6'=>'Bajo 6','bajo_4'=>'Bajo 4','bajo_3'=>'Bajo 3',
  'bajo_2'=>'Bajo 2','base_chancada'=>'Base Chancada','grava'=>'Grava','gravilla'=>'Gravilla',
  'arena'=>'Arena','arena_tubo'=>'Arena Tubo','escombros'=>'Escombros','bolones'=>'Bolones',
];

$protocolo  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host       = $_SERVER['HTTP_HOST'] ?? 'localhost';
$validarUrl = $protocolo.'://'.$host.'/validar_ticket.php?t='.urlencode($t['token']);

/* Formatear fecha de validación legible */
$fechaValStr = '';
if ($t['validado_en']) {
  try {
    $dt = new DateTime($t['validado_en']);
    $meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    $fechaValStr = $dt->format('d').' de '.$meses[(int)$dt->format('n')-1].' de '.$dt->format('Y').' a las '.$dt->format('H:i');
  } catch(Exception $e) { $fechaValStr = $t['validado_en']; }
}

if ($esExterno || $esConductor): ?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ticket #<?= str_pad($t['id'],5,'0',STR_PAD_LEFT) ?> — DOGROUP</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="css/styles.css">
</head>
<body class="bg-light">
<div class="container py-3">
  <div class="d-flex align-items-center gap-2 mb-3">
    <a href="<?= $backUrl ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Volver</a>
    <span class="text-muted small">Ticket #<?= str_pad($t['id'],5,'0',STR_PAD_LEFT) ?></span>
  </div>
<?php else: ?>
<?php require_once 'includes/header.php'; ?>
<?php endif; ?>
<style>
/* ── Impresión: ocultar sidebar y nav ── */
@media print {
  .sidebar, .sidebar-toggle, .topbar, .no-print,
  .nav-volver-wrap, .main-content > .d-flex.align-items-center { display: none !important; }
  .main-content { margin-left: 0 !important; padding: 0 !important; }
  body { background: #fff !important; font-size: 11pt; }
  .ticket-print-wrap { max-width: 100% !important; box-shadow: none !important; border: none !important; }
  .ticket-print-header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .mat-badge-print { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .validacion-block { -webkit-print-color-adjust: exact; print-color-adjust: exact; border: 2px solid #198754 !important; }
  .qr-col canvas { width: 150px !important; height: 150px !important; }
}

/* ── Layout ticket ── */
.ticket-print-wrap {
  max-width: 800px;
  margin: 0 auto;
  border: 2px solid #343a40;
  border-radius: 10px;
  overflow: hidden;
  background: #fff;
}

/* Header del ticket */
.ticket-print-header {
  background: #212529;
  color: #fff;
  padding: 1rem 1.4rem;
  display: flex;
  align-items: center;
  gap: 1rem;
}
.ticket-print-header .logo-wrap img {
  height: 54px;
  width: auto;
  object-fit: contain;
  filter: brightness(0) invert(1);
}
.ticket-print-header .empresa-info { flex: 1; }
.ticket-print-header .empresa-nombre { font-size: 1.3rem; font-weight: 800; letter-spacing: 1px; line-height: 1.1; }
.ticket-print-header .empresa-sub    { font-size: .78rem; opacity: .7; margin-top: 2px; }
.ticket-print-header .ticket-num     { text-align: right; }
.ticket-print-header .ticket-num .num { font-size: 2rem; font-weight: 900; letter-spacing: 3px; line-height: 1; }
.ticket-print-header .ticket-num .lbl { font-size: .7rem; opacity: .65; }

/* Banda de estado */
.estado-band {
  padding: .45rem 1.4rem;
  font-size: .82rem;
  font-weight: 700;
  letter-spacing: .5px;
  display: flex;
  align-items: center;
  gap: .5rem;
}
.estado-band.borrador  { background: #6c757d; color: #fff; }
.estado-band.enviado   { background: #ffc107; color: #000; }
.estado-band.validado  { background: #198754; color: #fff; }

/* Cuerpo */
.ticket-print-body { padding: 1.2rem 1.4rem; }

/* Tabla de datos */
.datos-table { width: 100%; border-collapse: collapse; }
.datos-table td { padding: .45rem .5rem; vertical-align: top; font-size: .92rem; }
.datos-table td.lbl {
  width: 35%;
  font-weight: 600;
  color: #555;
  font-size: .82rem;
  text-transform: uppercase;
  letter-spacing: .3px;
  border-bottom: 1px solid #f0f0f0;
}
.datos-table td.val {
  border-bottom: 1px solid #f0f0f0;
  color: #212529;
}
.datos-table tr:last-child td { border-bottom: none; }

/* Material badge */
.mat-badge-print {
  display: inline-block;
  background: #212529;
  color: #fff;
  padding: .25rem .9rem;
  border-radius: 5px;
  font-weight: 700;
  font-size: 1rem;
}
/* PPU badge */
.ppu-badge { font-size: 1.3rem; font-weight: 800; font-family: monospace; letter-spacing: 2px; }

/* QR columna */
.qr-col {
  border-left: 1px solid #dee2e6;
  padding-left: 1.2rem;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-width: 190px;
}
.qr-col canvas { border: 1px solid #ccc; border-radius: 6px; padding: 4px; background: #fff; }

/* Sección de validación */
.validacion-block {
  margin: 1rem 1.4rem;
  border: 2px solid #198754;
  border-radius: 8px;
  overflow: hidden;
}
.validacion-block .vb-header {
  background: #198754;
  color: #fff;
  padding: .5rem 1rem;
  font-weight: 700;
  font-size: .9rem;
  display: flex;
  align-items: center;
  gap: .5rem;
}
.validacion-block .vb-body {
  padding: .9rem 1rem;
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: .5rem 2rem;
}
.vb-item .vb-lbl { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; color: #555; }
.vb-item .vb-val { font-size: .98rem; font-weight: 600; color: #198754; margin-top: 2px; }
.vb-item .vb-val.cambio { font-size: 1.05rem; color: #0a3622; }

/* Pie del ticket */
.ticket-print-footer {
  background: #f8f9fa;
  border-top: 1px solid #dee2e6;
  padding: .6rem 1.4rem;
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: .72rem;
  color: #999;
}
</style>

<!-- Barra de acciones (no se imprime) -->
<div class="no-print d-flex align-items-center justify-content-between mb-3">
  <h4 class="fw-bold mb-0">
    <i class="bi bi-ticket-perforated text-secondary me-2"></i>
    Ticket de Despacho #<?= str_pad($t['id'],5,'0',STR_PAD_LEFT) ?>
    <?php if ($t['estado']==='validado'): ?>
    <span class="badge bg-success ms-2"><i class="bi bi-check-circle-fill me-1"></i>VALIDADO</span>
    <?php elseif ($t['estado']==='enviado'): ?>
    <span class="badge bg-warning text-dark ms-2"><i class="bi bi-send-fill me-1"></i>ENVIADO</span>
    <?php else: ?>
    <span class="badge bg-secondary ms-2">BORRADOR</span>
    <?php endif; ?>
  </h4>
  <div class="d-flex gap-2">
    <?php if ($t['estado'] === 'enviado'): ?>
    <a href="validar_ticket.php?t=<?= urlencode($t['token']) ?>" class="btn btn-success"><i class="bi bi-qr-code-scan me-1"></i>Validar</a>
    <?php endif; ?>
    <button onclick="window.print()" class="btn btn-outline-secondary"><i class="bi bi-printer me-1"></i>Imprimir / PDF</button>
    <a href="tickets_despacho.php" class="btn btn-secondary"><i class="bi bi-arrow-left me-1"></i>Volver atrás</a>
  </div>
</div>

<!-- ══════════════════════════════════════
     TICKET IMPRIMIBLE
     ══════════════════════════════════════ -->
<div class="ticket-print-wrap shadow">

  <!-- CABECERA con logo -->
  <div class="ticket-print-header">
    <div class="logo-wrap">
      <img src="img/logo.png" alt="DOGROUP">
    </div>
    <div class="empresa-info">
      <div class="empresa-nombre">DOGROUP</div>
      <div class="empresa-sub">Ticket de Despacho de Material</div>
    </div>
    <div class="ticket-num">
      <div class="lbl">N° TICKET</div>
      <div class="num"><?= str_pad($t['id'],5,'0',STR_PAD_LEFT) ?></div>
      <div class="lbl mt-1"><?= htmlspecialchars($t['fecha']) ?> &nbsp; <?= htmlspecialchars($t['hora']) ?></div>
    </div>
  </div>

  <!-- BANDA DE ESTADO -->
  <div class="estado-band <?= $t['estado'] ?>">
    <?php if ($t['estado']==='validado'): ?>
    <i class="bi bi-check-circle-fill"></i> TICKET VALIDADO
    <?php elseif ($t['estado']==='enviado'): ?>
    <i class="bi bi-send-fill"></i> ENVIADO — PENDIENTE DE VALIDACIÓN
    <?php else: ?>
    <i class="bi bi-pencil"></i> BORRADOR
    <?php endif; ?>
  </div>

  <!-- CUERPO: datos + QR en columnas -->
  <div class="ticket-print-body">
    <div class="d-flex gap-0">

      <!-- Datos del ticket -->
      <div class="flex-grow-1">
        <table class="datos-table">
          <?php if (!empty($t['salida_nombre'])): ?>
          <tr>
            <td class="lbl"><i class="bi bi-box-arrow-up-right me-1"></i>Salida</td>
            <td class="val"><strong><?= htmlspecialchars($t['salida_nombre']) ?></strong></td>
          </tr>
          <?php endif; ?>
          <tr>
            <td class="lbl"><i class="bi bi-building me-1"></i>Obra</td>
            <td class="val"><strong><?= htmlspecialchars($t['obra_nombre'] ?: '—') ?></strong></td>
          </tr>
          <tr>
            <td class="lbl"><i class="bi bi-truck-front-fill me-1"></i>PPU (Camión)</td>
            <td class="val">
              <span class="ppu-badge"><?= htmlspecialchars($t['ppu'] ?: '—') ?></span>
              <?php if ($t['metros_cubicos'] > 0): ?>
              <span style="font-size:.85rem;color:#555;margin-left:.5rem;">(<?= number_format($t['metros_cubicos'],1,',','.') ?> m³)</span>
              <?php endif; ?>
            </td>
          </tr>
          <tr>
            <td class="lbl"><i class="bi bi-geo-alt-fill me-1"></i>Destino</td>
            <td class="val"><?= htmlspecialchars($t['destino'] ?: '—') ?></td>
          </tr>
          <tr>
            <td class="lbl"><i class="bi bi-box-seam me-1"></i>Material</td>
            <td class="val"><span class="mat-badge-print"><?= htmlspecialchars($MATERIALES[$t['tipo_material']] ?? $t['tipo_material']) ?></span></td>
          </tr>
          <?php if ($t['observaciones']): ?>
          <tr>
            <td class="lbl"><i class="bi bi-chat-text me-1"></i>Observaciones</td>
            <td class="val" style="font-style:italic;color:#666;"><?= htmlspecialchars($t['observaciones']) ?></td>
          </tr>
          <?php endif; ?>
          <?php if (!empty($t['conductor_nombre'])): ?>
          <tr>
            <td class="lbl"><i class="bi bi-person-badge me-1"></i>Conductor</td>
            <td class="val">
              <strong><?= htmlspecialchars($t['conductor_nombre']) ?></strong>
              <?php if ($t['conductor_rut']): ?>
              <span class="text-muted ms-2" style="font-size:.82rem;">RUT: <?= htmlspecialchars($t['conductor_rut']) ?></span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endif; ?>
          <tr>
            <td class="lbl"><i class="bi bi-person me-1"></i>Creado por</td>
            <td class="val"><?= htmlspecialchars($t['creado_por_nombre']) ?></td>
          </tr>
          <tr>
            <td class="lbl"><i class="bi bi-calendar3 me-1"></i>Fecha / Hora</td>
            <td class="val"><?= htmlspecialchars($t['fecha']) ?> &nbsp; <?= htmlspecialchars($t['hora']) ?></td>
          </tr>
        </table>
      </div>

      <!-- QR / ESTADO -->
      <div class="qr-col">
        <?php if ($t['estado'] === 'validado'): ?>
        <div id="aprobadoBox" style="
          width:160px; height:160px;
          border:3px solid #198754;
          border-radius:10px;
          background:#f0fff4;
          display:flex; flex-direction:column;
          align-items:center; justify-content:center;
          gap:4px; box-sizing:border-box; padding:8px;
        ">
          <i class="bi bi-patch-check-fill" style="font-size:2.2rem;color:#198754;line-height:1;"></i>
          <span style="font-size:1.15rem;font-weight:900;color:#198754;letter-spacing:2px;line-height:1.1;text-align:center;">APROBADO</span>
          <span style="font-size:.62rem;color:#198754;font-weight:600;text-align:center;margin-top:2px;">
            #<?= str_pad($t['id'],5,'0',STR_PAD_LEFT) ?>
          </span>
        </div>
        <?php else: ?>
        <p style="font-size:.72rem;color:#888;margin-bottom:.4rem;text-align:center;">
          Escanear para validar
        </p>
        <canvas id="qrCanvas"></canvas>
        <p style="font-size:.65rem;color:#bbb;margin-top:.35rem;text-align:center;word-break:break-all;max-width:160px;">
          #<?= str_pad($t['id'],5,'0',STR_PAD_LEFT) ?> · <?= htmlspecialchars(substr($t['token'],0,10)).'...' ?>
        </p>
        <?php endif; ?>
      </div>

    </div>
  </div>

  <!-- BLOQUE DE VISACIÓN (solo si está validado) -->
  <?php if ($t['estado'] === 'validado'): ?>
  <div class="validacion-block">
    <div class="vb-header">
      <i class="bi bi-patch-check-fill"></i> VISACIÓN / VALIDACIÓN DEL TICKET
    </div>
    <div class="vb-body">
      <div class="vb-item">
        <div class="vb-lbl"><i class="bi bi-shield-check me-1"></i>Aprobado por Carchek</div>
        <div class="vb-val"><?= htmlspecialchars($t['validado_por_nombre'] ?: $t['revisado_por_ext_nom'] ?: '—') ?></div>
      </div>
      <div class="vb-item">
        <div class="vb-lbl"><i class="bi bi-calendar-check me-1"></i>Fecha Carchek</div>
        <div class="vb-val"><?= htmlspecialchars($fechaValStr) ?></div>
      </div>
      <?php if (!empty($t['validado_jefe_nombre'])): ?>
      <div class="vb-item">
        <div class="vb-lbl"><i class="bi bi-shield-fill-check me-1" style="color:#15803d"></i>Aprobado por Jefe de Obra</div>
        <div class="vb-val"><strong><?= htmlspecialchars($t['validado_jefe_nombre']) ?></strong>
          <?php if (!empty($t['validado_jefe_estado'])): ?>
          <span style="display:inline-block;background:<?= ['aprobado'=>'#dcfce7','rechazado'=>'#fee2e2','observacion'=>'#fef3c7'][$t['validado_jefe_estado']] ?? '#f3f4f6' ?>;color:<?= ['aprobado'=>'#14532d','rechazado'=>'#7f1d1d','observacion'=>'#92400e'][$t['validado_jefe_estado']] ?? '#374151' ?>;padding:1px 8px;border-radius:5px;font-size:.7rem;font-weight:700;margin-left:6px"><?= ucfirst($t['validado_jefe_estado']) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div class="vb-item">
        <div class="vb-lbl"><i class="bi bi-calendar-check me-1"></i>Fecha Jefe de Obra</div>
        <div class="vb-val"><?= $t['validado_jefe_en'] ? date('d-m-Y H:i', strtotime($t['validado_jefe_en'])) : '—' ?></div>
      </div>
      <?php if (!empty($t['observacion_jefe'])): ?>
      <div class="vb-item" style="grid-column:1/-1">
        <div class="vb-lbl"><i class="bi bi-chat-left-text me-1"></i>Observación Jefe de Obra</div>
        <div class="vb-val"><?= htmlspecialchars($t['observacion_jefe']) ?></div>
      </div>
      <?php endif; ?>
      <?php else: ?>
      <div class="vb-item">
        <div class="vb-lbl"><i class="bi bi-shield-fill-check me-1" style="color:#9ca3af"></i>Jefe de Obra</div>
        <div class="vb-val" style="color:#9ca3af;font-style:italic">Pendiente de validación</div>
      </div>
      <?php endif; ?>
      <?php if (!empty($t['conductor_nombre'])): ?>
      <div class="vb-item">
        <div class="vb-lbl"><i class="bi bi-person-badge me-1"></i>Conductor</div>
        <div class="vb-val"><?= htmlspecialchars($t['conductor_nombre']) ?><?= $t['conductor_rut'] ? ' <small style="color:#555">RUT: '.htmlspecialchars($t['conductor_rut']).'</small>' : '' ?></div>
      </div>
      <?php endif; ?>
      <div class="vb-item">
        <div class="vb-lbl"><i class="bi bi-person me-1"></i>Creado por</div>
        <div class="vb-val"><?= htmlspecialchars($t['creado_por_nombre']) ?></div>
      </div>
      <?php if (!empty($t['salida_nombre'])): ?>
      <div class="vb-item">
        <div class="vb-lbl"><i class="bi bi-box-arrow-up-right me-1"></i>Salida</div>
        <div class="vb-val"><?= htmlspecialchars($t['salida_nombre']) ?></div>
      </div>
      <?php endif; ?>
      <div class="vb-item">
        <div class="vb-lbl"><i class="bi bi-building me-1"></i>Obra</div>
        <div class="vb-val"><?= htmlspecialchars($t['obra_nombre'] ?: '—') ?></div>
      </div>
    </div>
  </div>
  <?php else: ?>
  <!-- Espacio de firma en blanco (para tickets aún no validados) -->
  <div style="margin:0 1.4rem 1rem; display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
    <div style="border:1px solid #dee2e6;border-radius:6px;padding:.7rem 1rem;">
      <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:#888;margin-bottom:2.5rem;">Validado por</div>
      <div style="border-top:1px solid #aaa;padding-top:.3rem;font-size:.7rem;color:#aaa;">Nombre y firma</div>
    </div>
    <div style="border:1px solid #dee2e6;border-radius:6px;padding:.7rem 1rem;">
      <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:#888;margin-bottom:2.5rem;">Fecha de validación</div>
      <div style="border-top:1px solid #aaa;padding-top:.3rem;font-size:.7rem;color:#aaa;">Fecha</div>
    </div>
  </div>
  <?php endif; ?>

  <!-- PIE DEL TICKET -->
  <div class="ticket-print-footer">
    <span>DOGROUP &copy; <?= date('Y') ?></span>
    <span>Generado: <?= date('d/m/Y H:i') ?></span>
  </div>

</div>

<?php if ($t['estado'] !== 'validado'): ?>
<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.4/build/qrcode.min.js"></script>
<script>
QRCode.toCanvas(
  document.getElementById('qrCanvas'),
  <?= json_encode($validarUrl) ?>,
  { width: 160, margin: 1, color: { dark: '#000000', light: '#ffffff' } },
  function(err) { if (err) console.error('QR error:', err); }
);
</script>
<?php endif; ?>

<?php if ($esExterno || $esConductor): ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</div><!-- /container -->
</body>
</html>
<?php else: ?>
<?php require_once 'includes/footer.php'; ?>
<?php endif; ?>
