<?php
require_once 'config.php';
requireAuth();
requireModulo('despacho', $usuario, $pdo);

use PHPMailer\PHPMailer\PHPMailer;

/* Solo receptores y admins de despacho pueden cerrar el día */
$esAdminDespacho = isDespachoAdmin($usuario, $pdo);
$esReceptor = false;
$stR = $pdo->prepare("SELECT COUNT(*) FROM despacho_receptores WHERE usuario_id=?");
$stR->execute([$usuario['id']]);
$esReceptor = (int)$stR->fetchColumn() > 0;
if (!$esAdminDespacho && !$esReceptor) {
  header('Location: tickets_despacho.php?error=sin_permiso'); exit;
}

$MATERIALES = [
  'tierra'=>'Tierra','integral'=>'Integral','bajo_6'=>'Bajo 6','bajo_4'=>'Bajo 4','bajo_3'=>'Bajo 3',
  'bajo_2'=>'Bajo 2','base_chancada'=>'Base Chancada','grava'=>'Grava','gravilla'=>'Gravilla',
  'arena'=>'Arena','arena_tubo'=>'Arena Tubo','escombros'=>'Escombros','bolones'=>'Bolones',
];

$fecha = $_GET['fecha'] ?? date('Y-m-d');
$msg   = null; $msgTipo = 'success';
if (!empty($_GET['msg'])) { $msg = $_GET['msg']; $msgTipo = $_GET['mt'] ?? 'success'; }

/* ── Exportar CSV ── */
if (isset($_GET['csv'])) {
  $fecha = $_GET['fecha'] ?? date('Y-m-d');
  $tickets = $pdo->prepare("SELECT * FROM tickets_despacho WHERE fecha=? AND estado='validado' ORDER BY hora");
  $tickets->execute([$fecha]);
  $rows = $tickets->fetchAll(PDO::FETCH_ASSOC);

  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="despacho_'.$fecha.'.csv"');
  header('Pragma: no-cache');
  $out = fopen('php://output', 'w');
  fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM para Excel
  fputcsv($out, ['#','Fecha','Hora','Obra','Conductor','RUT Conductor','PPU','m³','Destino','Material','Cambio','Creado por','Validado por'], ';');
  foreach ($rows as $r) {
    fputcsv($out, [
      str_pad($r['id'],5,'0',STR_PAD_LEFT),
      $r['fecha'], $r['hora'],
      $r['obra_nombre'],
      $r['conductor_nombre'] ?? '',
      $r['conductor_rut']    ?? '',
      $r['ppu'],
      number_format($r['metros_cubicos'],1,',','.'),
      $r['destino'],
      $MATERIALES[$r['tipo_material']] ?? $r['tipo_material'],
      $r['cambio'],
      $r['creado_por_nombre'],
      $r['validado_por_nombre'],
    ], ';');
  }
  fclose($out);
  exit;
}

/* ── POST: Cerrar día y notificar ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'cerrar_dia') {
  $fecha = $_POST['fecha'] ?? date('Y-m-d');

  /* Obtener tickets del día */
  $stT = $pdo->prepare("SELECT * FROM tickets_despacho WHERE fecha=? AND estado='validado'");
  $stT->execute([$fecha]);
  $ticketsDelDia = $stT->fetchAll(PDO::FETCH_ASSOC);

  if (empty($ticketsDelDia)) {
    header("Location: tickets_despacho_cierre.php?fecha=$fecha&msg=".urlencode('No hay tickets validados para esta fecha.')."&mt=warning");
    exit;
  }

  $totalTickets = count($ticketsDelDia);
  $totalM3      = array_sum(array_column($ticketsDelDia, 'metros_cubicos'));

  /* Resumen por PPU */
  $resPPU = []; $resMat = []; $resObra = [];
  foreach ($ticketsDelDia as $t) {
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

  $resumenJson = json_encode(['ppu'=>$resPPU,'material'=>$resMat,'obra'=>$resObra]);

  /* Guardar o actualizar cierre */
  $pdo->prepare("INSERT INTO despacho_cierre_dia (fecha, total_tickets, total_m3, resumen_json, cerrado_por, cerrado_por_nombre)
    VALUES (?,?,?,?,?,?)
    ON CONFLICT(fecha) DO UPDATE SET
      total_tickets=excluded.total_tickets, total_m3=excluded.total_m3,
      resumen_json=excluded.resumen_json, cerrado_por=excluded.cerrado_por,
      cerrado_por_nombre=excluded.cerrado_por_nombre, cerrado_en=CURRENT_TIMESTAMP")
    ->execute([$fecha, $totalTickets, $totalM3, $resumenJson, $usuario['id'], $usuario['nombre']]);

  /* Notificar al encargado de costos */
  $encCostos = $pdo->query("SELECT u.nombre, u.email FROM despacho_encargado_costos ec JOIN usuarios u ON u.id=ec.usuario_id WHERE u.email IS NOT NULL AND u.email != ''")->fetchAll(PDO::FETCH_ASSOC);

  $emailEnviado = false;
  if (!empty($encCostos)) {
    try {
      require_once 'email_functions.php';
      require_once 'smtp_config.php';
      global $SMTP_CONFIG, $SITE_URL;

      $protocolo = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
      $host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
      $urlReporte = $protocolo.'://'.$host.'/tickets_despacho_reporte_diario.php?fecha='.urlencode($fecha);

      /* Construir tabla de tickets para el email */
      $tablaRows = '';
      foreach ($ticketsDelDia as $t) {
        $mat = htmlspecialchars($MATERIALES[$t['tipo_material']] ?? $t['tipo_material']);
        $m3f = number_format($t['metros_cubicos'],1,',','.');
        $tablaRows .= "<tr>
          <td style='padding:6px 8px;border:1px solid #ddd;text-align:center;'>{$t['hora']}</td>
          <td style='padding:6px 8px;border:1px solid #ddd;'>" . htmlspecialchars($t['obra_nombre'] ?: '—') . "</td>
          <td style='padding:6px 8px;border:1px solid #ddd;text-align:center;font-weight:bold;'>" . htmlspecialchars($t['ppu']) . "</td>
          <td style='padding:6px 8px;border:1px solid #ddd;text-align:center;'>$m3f m³</td>
          <td style='padding:6px 8px;border:1px solid #ddd;'>$mat</td>
          <td style='padding:6px 8px;border:1px solid #ddd;'>" . htmlspecialchars($t['cambio']) . "</td>
          <td style='padding:6px 8px;border:1px solid #ddd;'>" . htmlspecialchars($t['validado_por_nombre']) . "</td>
        </tr>";
      }

      /* Resumen por PPU */
      $resRows = '';
      foreach ($resPPU as $ppu => $datos) {
        $resRows .= "<tr>
          <td style='padding:5px 8px;border:1px solid #ddd;font-weight:bold;'>" . htmlspecialchars($ppu) . "</td>
          <td style='padding:5px 8px;border:1px solid #ddd;text-align:center;'>{$datos['viajes']}</td>
          <td style='padding:5px 8px;border:1px solid #ddd;text-align:center;'>" . number_format($datos['m3'],1,',','.') . " m³</td>
        </tr>";
      }

      $htmlEmail = "
        <div style='font-family:Arial,sans-serif;max-width:700px;margin:0 auto;'>
          <div style='background:#212529;padding:20px 24px;border-radius:8px 8px 0 0;'>
            <h2 style='color:#fff;margin:0;font-size:20px;'>DOGROUP — Reporte Diario de Despacho</h2>
            <p style='color:#aaa;margin:4px 0 0;font-size:13px;'>Fecha: <strong style='color:#fff;'>" . date('d/m/Y', strtotime($fecha)) . "</strong></p>
          </div>
          <div style='background:#f8f9fa;padding:16px 24px;border:1px solid #dee2e6;'>
            <div style='display:flex;gap:24px;flex-wrap:wrap;'>
              <div style='background:#198754;color:#fff;padding:12px 20px;border-radius:8px;text-align:center;min-width:120px;'>
                <div style='font-size:28px;font-weight:800;'>$totalTickets</div>
                <div style='font-size:12px;opacity:.85;'>Viajes validados</div>
              </div>
              <div style='background:#0dcaf0;color:#000;padding:12px 20px;border-radius:8px;text-align:center;min-width:120px;'>
                <div style='font-size:28px;font-weight:800;'>" . number_format($totalM3,1,',','.') . "</div>
                <div style='font-size:12px;'>m³ totales</div>
              </div>
            </div>
          </div>
          <div style='background:#fff;padding:16px 24px;border:1px solid #dee2e6;'>
            <h3 style='font-size:15px;margin:0 0 10px;'>Detalle de viajes</h3>
            <table style='width:100%;border-collapse:collapse;font-size:13px;'>
              <thead><tr style='background:#343a40;color:#fff;'>
                <th style='padding:7px 8px;'>Hora</th>
                <th style='padding:7px 8px;'>Obra</th>
                <th style='padding:7px 8px;'>PPU</th>
                <th style='padding:7px 8px;'>m³</th>
                <th style='padding:7px 8px;'>Material</th>
                <th style='padding:7px 8px;'>Cambio</th>
                <th style='padding:7px 8px;'>Validado por</th>
              </tr></thead>
              <tbody>$tablaRows</tbody>
            </table>
          </div>
          <div style='background:#fff;padding:16px 24px;border:1px solid #dee2e6;border-top:none;'>
            <h3 style='font-size:15px;margin:0 0 10px;'>Resumen por PPU</h3>
            <table style='width:100%;border-collapse:collapse;font-size:13px;'>
              <thead><tr style='background:#6c757d;color:#fff;'>
                <th style='padding:6px 8px;'>PPU</th>
                <th style='padding:6px 8px;text-align:center;'>Viajes</th>
                <th style='padding:6px 8px;text-align:center;'>Total m³</th>
              </tr></thead>
              <tbody>$resRows</tbody>
            </table>
          </div>
          <div style='background:#f8f9fa;padding:14px 24px;border:1px solid #dee2e6;border-top:none;border-radius:0 0 8px 8px;text-align:center;'>
            <a href='$urlReporte' style='background:#212529;color:#fff;padding:10px 24px;border-radius:6px;text-decoration:none;font-size:14px;font-weight:bold;'>
              Ver reporte completo
            </a>
            <p style='margin:10px 0 0;font-size:11px;color:#999;'>
              Cerrado por: {$usuario['nombre']} · DOGROUP &copy; " . date('Y') . "
            </p>
          </div>
        </div>";

      $mail = new PHPMailer(true);
      $mail->isSMTP();
      $mail->Host       = $SMTP_CONFIG['host'];
      $mail->SMTPAuth   = true;
      $mail->Username   = $SMTP_CONFIG['user'];
      $mail->Password   = $SMTP_CONFIG['pass'];
      $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
      $mail->Port       = $SMTP_CONFIG['port'];
      $mail->CharSet    = 'UTF-8';
      $mail->setFrom($SMTP_CONFIG['from_email'], $SMTP_CONFIG['from_name']);
      $mail->isHTML(true);
      $mail->Subject = 'Reporte Diario de Despacho — ' . date('d/m/Y', strtotime($fecha));
      $mail->Body    = $htmlEmail;
      foreach ($encCostos as $ec) { $mail->addAddress($ec['email'], $ec['nombre']); }
      $mail->send();
      $emailEnviado = true;
      $pdo->prepare("UPDATE despacho_cierre_dia SET enviado=1, enviado_en=CURRENT_TIMESTAMP WHERE fecha=?")->execute([$fecha]);
    } catch (Exception $e) { /* Email falla en silencio, el cierre igual se guarda */ }
  }

  $msj = 'Día cerrado correctamente. '.($emailEnviado ? 'Reporte enviado al encargado de costos.' : 'Sin encargado de costos configurado o email no disponible.');
  header("Location: tickets_despacho_cierre.php?fecha=$fecha&msg=".urlencode($msj)."&mt=success"); exit;
}

/* ── Cargar datos de la fecha ── */
$stTickets = $pdo->prepare("SELECT * FROM tickets_despacho WHERE fecha=? AND estado='validado' ORDER BY hora");
$stTickets->execute([$fecha]);
$tickets = $stTickets->fetchAll(PDO::FETCH_ASSOC);

$stBorradores = $pdo->prepare("SELECT COUNT(*) FROM tickets_despacho WHERE fecha=? AND estado IN ('borrador','enviado')");
$stBorradores->execute([$fecha]);
$pendientes = (int)$stBorradores->fetchColumn();

/* Estadísticas */
$totalM3 = array_sum(array_column($tickets, 'metros_cubicos'));
$resPPU  = []; $resMat = []; $resObra = [];
foreach ($tickets as $t) {
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

/* ¿Ya fue cerrado este día? */
$stCierre = $pdo->prepare("SELECT * FROM despacho_cierre_dia WHERE fecha=?");
$stCierre->execute([$fecha]);
$cierreExistente = $stCierre->fetch(PDO::FETCH_ASSOC);

require_once 'includes/header.php';
?>
<style>
@media print {
  .sidebar,.sidebar-toggle,.topbar,.no-print,.nav-volver-wrap { display:none !important; }
  .main-content { margin-left:0 !important; padding:0 !important; }
  body { background:#fff !important; font-size:10pt; }
  .resumen-card,.stat-box { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
}
.stat-box { border-radius:10px; padding:1rem 1.2rem; text-align:center; }
.stat-box .stat-val { font-size:2.2rem; font-weight:800; line-height:1; }
.stat-box .stat-lbl { font-size:.78rem; opacity:.85; margin-top:3px; }
</style>

<!-- Cabecera -->
<div class="no-print d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
  <div>
    <h4 class="fw-bold mb-1"><i class="bi bi-calendar2-check text-secondary me-2"></i>Cierre del Día — Despacho</h4>
    <p class="text-muted mb-0">Resumen y aprobación del despacho diario</p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="tickets_despacho_reporte_diario.php" class="btn btn-outline-secondary"><i class="bi bi-clock-history me-1"></i>Historial</a>
    <button onclick="window.print()" class="btn btn-outline-secondary"><i class="bi bi-printer me-1"></i>Imprimir</button>
    <a href="tickets_despacho_cierre.php?csv=1&fecha=<?= urlencode($fecha) ?>" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i>Exportar CSV</a>
    <a href="tickets_despacho.php" class="btn btn-secondary"><i class="bi bi-arrow-left me-1"></i>Volver</a>
  </div>
</div>

<!-- Selector de fecha -->
<div class="card border-0 shadow-sm mb-3 no-print">
  <div class="card-body py-2 d-flex align-items-center gap-3">
    <label class="fw-semibold mb-0"><i class="bi bi-calendar3 me-1"></i>Fecha:</label>
    <form method="GET" class="d-flex gap-2 align-items-center">
      <input type="date" name="fecha" class="form-control form-control-sm" value="<?= htmlspecialchars($fecha) ?>" onchange="this.form.submit()">
    </form>
    <?php if ($cierreExistente): ?>
    <span class="badge bg-success ms-2"><i class="bi bi-check-circle-fill me-1"></i>Día cerrado por <?= htmlspecialchars($cierreExistente['cerrado_por_nombre']) ?></span>
    <?php if ($cierreExistente['enviado']): ?>
    <span class="badge bg-info text-dark"><i class="bi bi-envelope-check me-1"></i>Reporte enviado</span>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgTipo ?> alert-dismissible fade show"><i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<?php if ($pendientes > 0): ?>
<div class="alert alert-warning no-print"><i class="bi bi-exclamation-triangle me-1"></i>Hay <strong><?= $pendientes ?></strong> ticket(s) en borrador o enviados sin validar para esta fecha. Solo se incluyen los validados.</div>
<?php endif; ?>

<?php if (empty($tickets)): ?>
<div class="text-center text-muted py-5"><i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>No hay tickets validados para <?= htmlspecialchars($fecha) ?>.</div>
<?php else: ?>

<!-- Encabezado imprimible con logo -->
<div class="d-none d-print-flex align-items-center justify-content-between mb-3" style="background:#212529;color:#fff;padding:1rem 1.4rem;border-radius:8px;">
  <div class="d-flex align-items-center gap-3">
    <img src="img/logo.png" alt="DOGROUP" style="height:50px;filter:brightness(0) invert(1);">
    <div>
      <div style="font-size:1.2rem;font-weight:800;">DOGROUP</div>
      <div style="font-size:.75rem;opacity:.7;">Reporte Diario de Despacho</div>
    </div>
  </div>
  <div style="text-align:right;">
    <div style="font-size:1.5rem;font-weight:800;"><?= date('d/m/Y', strtotime($fecha)) ?></div>
    <div style="font-size:.7rem;opacity:.7;">Generado: <?= date('d/m/Y H:i') ?></div>
  </div>
</div>

<!-- Estadísticas -->
<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-box bg-dark text-white">
      <div class="stat-val"><?= count($tickets) ?></div>
      <div class="stat-lbl"><i class="bi bi-ticket-perforated me-1"></i>Viajes validados</div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-box bg-info text-dark">
      <div class="stat-val"><?= number_format($totalM3,1,',','.') ?> m³</div>
      <div class="stat-lbl"><i class="bi bi-box-seam me-1"></i>Metros cúbicos totales</div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-box bg-secondary text-white">
      <div class="stat-val"><?= count($resPPU) ?></div>
      <div class="stat-lbl"><i class="bi bi-truck-front-fill me-1"></i>Camiones distintos</div>
    </div>
  </div>
</div>

<!-- Detalle tickets -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-dark text-white fw-bold"><i class="bi bi-list-check me-1"></i>Detalle de Tickets — <?= htmlspecialchars(date('d/m/Y', strtotime($fecha))) ?></div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" style="font-size:.87rem;">
      <thead class="table-light">
      <tr><th>#</th><th>Hora</th><th>Conductor</th><th>Obra</th><th>PPU</th><th>m³</th><th>Destino</th><th>Material</th><th>Cambio</th><th>Validado por</th></tr>
        </thead>
        <tbody>
      <?php foreach ($tickets as $t): ?>
      <tr>
        <td><small class="text-muted">#<?= str_pad($t['id'],4,'0',STR_PAD_LEFT) ?></small></td>
        <td><strong><?= htmlspecialchars($t['hora']) ?></strong></td>
        <td><small><?= htmlspecialchars($t['conductor_nombre'] ?? '—') ?><?= !empty($t['conductor_rut']) ? '<br><span class="text-muted">'.htmlspecialchars($t['conductor_rut']).'</span>' : '' ?></small></td>
        <td><small><?= htmlspecialchars($t['obra_nombre'] ?: '—') ?></small></td>
        <td><span class="badge bg-dark"><?= htmlspecialchars($t['ppu'] ?: '—') ?></span></td>
        <td><?= $t['metros_cubicos'] > 0 ? number_format($t['metros_cubicos'],1,',','.').' m³' : '—' ?></td>
        <td><small><?= htmlspecialchars($t['destino'] ?: '—') ?></small></td>
        <td><span class="badge bg-secondary"><?= htmlspecialchars($MATERIALES[$t['tipo_material']] ?? $t['tipo_material']) ?></span></td>
        <td><small><?= htmlspecialchars($t['cambio'] ?: '—') ?></small></td>
        <td><small><?= htmlspecialchars($t['validado_por_nombre']) ?></small></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Resúmenes -->
<div class="row g-3 mb-4">
  <!-- Por PPU -->
  <div class="col-md-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-secondary text-white fw-bold py-2"><i class="bi bi-truck-front-fill me-1"></i>Por PPU</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead class="table-light"><tr><th>PPU</th><th class="text-center">Viajes</th><th class="text-center">m³</th></tr></thead>
          <tbody>
          <?php foreach ($resPPU as $ppu => $d): ?>
          <tr>
            <td><strong><?= htmlspecialchars($ppu) ?></strong></td>
            <td class="text-center"><?= $d['viajes'] ?></td>
            <td class="text-center"><?= number_format($d['m3'],1,',','.') ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <!-- Por Obra -->
  <div class="col-md-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-warning text-dark fw-bold py-2"><i class="bi bi-building me-1"></i>Por Obra</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead class="table-light"><tr><th>Obra</th><th class="text-center">Viajes</th><th class="text-center">m³</th></tr></thead>
          <tbody>
          <?php foreach ($resObra as $ob => $d): ?>
          <tr>
            <td><small><?= htmlspecialchars($ob) ?></small></td>
            <td class="text-center"><?= $d['viajes'] ?></td>
            <td class="text-center"><?= number_format($d['m3'],1,',','.') ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <!-- Por Material -->
  <div class="col-md-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-info text-dark fw-bold py-2"><i class="bi bi-box-seam me-1"></i>Por Material</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead class="table-light"><tr><th>Material</th><th class="text-center">Viajes</th><th class="text-center">m³</th></tr></thead>
          <tbody>
          <?php foreach ($resMat as $mat => $d): ?>
          <tr>
            <td><small><?= htmlspecialchars($mat) ?></small></td>
            <td class="text-center"><?= $d['viajes'] ?></td>
            <td class="text-center"><?= number_format($d['m3'],1,',','.') ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Sección de cierre -->
<div class="card border-<?= $cierreExistente ? 'success' : 'secondary' ?> shadow-sm no-print mb-4">
  <div class="card-header bg-<?= $cierreExistente ? 'success' : 'dark' ?> text-white fw-bold">
    <i class="bi bi-<?= $cierreExistente ? 'check-circle-fill' : 'lock-fill' ?> me-1"></i>
    <?= $cierreExistente ? 'Día ya cerrado' : 'Cerrar y Enviar Reporte' ?>
  </div>
  <div class="card-body">
    <?php if ($cierreExistente): ?>
    <div class="d-flex flex-wrap align-items-center gap-3">
      <div>
        <p class="mb-1">Cerrado por <strong><?= htmlspecialchars($cierreExistente['cerrado_por_nombre']) ?></strong> el <?= htmlspecialchars($cierreExistente['cerrado_en']) ?></p>
        <?php if ($cierreExistente['enviado']): ?>
        <p class="mb-0 text-success"><i class="bi bi-envelope-check-fill me-1"></i>Reporte enviado al encargado de costos el <?= htmlspecialchars($cierreExistente['enviado_en']) ?></p>
        <?php else: ?>
        <p class="mb-0 text-warning"><i class="bi bi-envelope-exclamation me-1"></i>Reporte no enviado (sin encargado de costos configurado o sin email).</p>
        <?php endif; ?>
      </div>
      <!-- Reenviar -->
      <form method="POST" class="ms-auto">
        <input type="hidden" name="accion" value="cerrar_dia">
        <input type="hidden" name="fecha"  value="<?= htmlspecialchars($fecha) ?>">
        <button class="btn btn-outline-success" onclick="return confirm('¿Regenerar y reenviar el reporte de este día?')">
          <i class="bi bi-arrow-clockwise me-1"></i>Regenerar y Reenviar
        </button>
      </form>
    </div>
    <?php else: ?>
    <p class="text-muted mb-3">Al cerrar el día se guardará el resumen y se enviará el reporte por email al encargado de costos configurado en el módulo.</p>
    <form method="POST" onsubmit="return confirm('¿Cerrar el día y enviar el reporte al encargado de costos?')">
      <input type="hidden" name="accion" value="cerrar_dia">
      <input type="hidden" name="fecha"  value="<?= htmlspecialchars($fecha) ?>">
      <button class="btn btn-success btn-lg px-4">
        <i class="bi bi-send-check-fill me-2"></i>Aprobar día y enviar reporte
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>

<!-- Pie imprimible -->
<div class="d-none d-print-block" style="border-top:1px solid #ccc;padding-top:.5rem;margin-top:1rem;font-size:.7rem;color:#999;display:flex;justify-content:space-between;">
  <span>DOGROUP &copy; <?= date('Y') ?> — Todos los derechos reservados — Desarrollado por César Mansilla / Bynari SpA / www.bynari.cl</span>
  <span>Generado: <?= date('d/m/Y H:i') ?></span>
</div>

<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
